<?php

namespace App\Jobs;

use App\Exceptions\ExternalApiException;
use App\Models\ApiRun;
use App\Models\Wallpaper;
use App\Services\CalendarContextService;
use App\Services\HistoricalAnalysisService;
use App\Services\OpenAiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateCompositionProposal implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public readonly int $wallpaperId,
        public readonly string $apiRunId,
        public readonly bool $reproposal = false,
    ) {
        $this->onQueue('openai');
    }

    public function handle(
        HistoricalAnalysisService $analysisService,
        CalendarContextService $calendarService,
        OpenAiClient $openAi,
    ): void {
        $wallpaper = Wallpaper::query()->findOrFail($this->wallpaperId);
        $run = ApiRun::query()->findOrFail($this->apiRunId);
        $snapshot = $analysisService->currentSnapshot();
        if ($snapshot === null) {
            throw new ExternalApiException('historical_analysis_required', false);
        }
        $calendar = $calendarService->forDate($wallpaper->target_date->format('Y-m-d'));

        $topWinners = Wallpaper::query()
            ->whereNotNull('prize_vnd')
            ->orderByDesc('prize_vnd')
            ->limit(20)
            ->get(['target_date', 'prize_vnd', 'title', 'art_style', 'composition'])
            ->toArray();
        $recentStyles = Wallpaper::query()
            ->whereNotNull('art_style')
            ->orderByDesc('target_date')
            ->limit(30)
            ->pluck('art_style')
            ->values()
            ->all();
        $rejected = $wallpaper->proposals()
            ->whereIn('status', $this->reproposal ? ['rejected', 'proposed'] : ['rejected'])
            ->orderBy('sequence')
            ->get(['title', 'art_style', 'composition'])
            ->toArray();

        $input = json_encode([
            'target_date' => $wallpaper->target_date->format('Y-m-d'),
            'calendar' => $calendar,
            'historical_analysis_markdown' => $snapshot->summary,
            'top_winners' => $topWinners,
            'recent_art_styles' => $recentStyles,
            'rejected_same_day_proposals' => $rejected,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $result = $openAi->structured(
            $run,
            $this->instructions($wallpaper->target_date->format('Y年n月j日')),
            $input,
            OpenAiClient::PROPOSAL_SCHEMA,
            'wallpaper_composition',
        );

        if ($this->reproposal) {
            $wallpaper->proposals()->where('status', 'proposed')->update(['status' => 'rejected']);
        }

        $proposal = $wallpaper->proposals()->create([
            ...$result,
            'sequence' => ((int) $wallpaper->proposals()->max('sequence')) + 1,
            'status' => 'proposed',
            'calendar_context' => $calendar,
            'analysis_hash' => $snapshot->data_hash,
            'input_hash' => hash('sha256', $input),
        ]);

        $wallpaper->update([
            ...$result,
            'state' => 'proposed',
            'warnings' => $calendar['warnings'] ?? [],
            'chosen_proposal_id' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $run = ApiRun::query()->find($this->apiRunId);
        if ($run !== null && $run->status !== 'failed') {
            $run->update([
                'status' => 'failed',
                'error_code' => $exception instanceof ExternalApiException ? $exception->errorCode : 'proposal_job_failed',
                'retryable' => $exception instanceof ExternalApiException ? $exception->retryable : true,
                'finished_at' => now(),
            ]);
        }
    }

    private function instructions(string $targetDate): string
    {
        return <<<PROMPT
あなたは金運をテーマにしたスマートフォン壁紙のアートディレクターです。
これは{$targetDate}用のスマートフォン壁紙です。
対象日に使用することを前提に、入力された暦情報を反映した構図を提案してください。
出力は過去実績との相関に基づく創作上の提案であり、宝くじ当選や確率向上を保証してはいけません。
historical_analysis_markdown の傾向、反例、注意点、活用指針を構図検討に反映してください。
画風は連続利用を避け、絵画、実写写真、彫刻写真など幅広くローテーションしてください。
動植物、人物、現象、天体、抽象像、無機物、建築などあらゆるモチーフを利用できます。
過去実績を参照しつつ、未知の構図やモチーフも探索してください。
暦情報は入力された値だけを使い、欠損値を推測で補ってはいけません。
同日の却下案と実質的に同じ提案をしてはいけません。
結論は「構図名 × 画風」の1行にしてください。
PROMPT;
    }
}
