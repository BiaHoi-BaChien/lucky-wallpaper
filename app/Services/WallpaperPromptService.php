<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use App\Models\CompositionProposal;
use App\Models\Wallpaper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class WallpaperPromptService
{
    private const PROPOSAL_FIELDS = [
        'title',
        'art_style',
        'conclusion',
        'overview',
        'composition',
        'color_wu_xing',
        'symbolism',
    ];

    public function __construct(
        private readonly HistoricalAnalysisService $analysisService,
        private readonly CalendarContextService $calendarService,
    ) {}

    /**
     * @return array{
     *     instructions: string,
     *     input: string,
     *     input_hash: string,
     *     prompt: string,
     *     prompt_hash: string,
     *     context_hash: string,
     *     filename: string,
     *     calendar: array,
     *     analysis_hash: string
     * }
     */
    public function composition(string $targetDate, ?Wallpaper $wallpaper = null, bool $reproposal = false): array
    {
        $snapshot = $this->analysisService->currentSnapshot();
        if ($snapshot === null) {
            throw new ExternalApiException('historical_analysis_required', false);
        }

        $calendar = $this->calendarService->forDate($targetDate);
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
        $rejected = $wallpaper === null
            ? []
            : $wallpaper->proposals()
                ->whereIn('status', $reproposal ? ['rejected', 'proposed'] : ['rejected'])
                ->orderBy('sequence')
                ->get(['title', 'art_style', 'composition'])
                ->toArray();

        $input = json_encode([
            'target_date' => $targetDate,
            'calendar' => $calendar,
            'historical_analysis_markdown' => $snapshot->summary,
            'top_winners' => $topWinners,
            'recent_art_styles' => $recentStyles,
            'rejected_same_day_proposals' => $rejected,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $instructions = $this->compositionInstructions(
            CarbonImmutable::parse($targetDate, config('lucky.timezone'))->format('Y年n月j日'),
        );
        $schema = json_encode(
            OpenAiClient::PROPOSAL_SCHEMA,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
        $prettyInput = json_encode(
            json_decode($input, true, flags: JSON_THROW_ON_ERROR),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
        $prompt = <<<PROMPT
{$instructions}

以下の入力データを使って構図を1案だけ提案してください。
回答は次のJSON Schemaに一致するJSONオブジェクトだけにしてください。説明文やコードフェンスは付けないでください。

JSON Schema:
{$schema}

入力データ:
{$prettyInput}
PROMPT;
        $inputHash = hash(
            'sha256',
            $snapshot->data_hash.'|'.$snapshot->prompt_version.'|'.$input,
        );

        return [
            'instructions' => $instructions,
            'input' => $input,
            'input_hash' => $inputHash,
            'prompt' => $prompt,
            'prompt_hash' => hash('sha256', config('lucky.openai.prompt_version').'|'.$prompt),
            'context_hash' => $inputHash,
            'filename' => 'wallpaper-composition-'.$targetDate.'.txt',
            'calendar' => $calendar,
            'analysis_hash' => $snapshot->data_hash,
        ];
    }

    /**
     * @return array{
     *     prompt: string,
     *     prompt_hash: string,
     *     context_hash: string,
     *     filename: string
     * }
     */
    public function image(Wallpaper $wallpaper, ?CompositionProposal $proposal = null): array
    {
        $details = $proposal ?? $wallpaper;
        $prompt = implode("\n\n", [
            'スマートフォン用の縦長壁紙を1枚制作してください。画像内には文字、数字、ロゴ、署名、透かしを一切入れないでください。',
            '構図名: '.$details->title,
            '画風: '.$details->art_style,
            '概要: '.$details->overview,
            '配置: '.$details->composition,
            '色彩・五行: '.$details->color_wu_xing,
            '象徴意図: '.$details->symbolism,
            '視認性: ロック画面の時計やアイコンが重なる上部と下部は情報量を抑え、主要モチーフは安全領域に配置する。',
        ]);

        return [
            'prompt' => $prompt,
            'prompt_hash' => hash('sha256', config('lucky.openai.prompt_version').'|'.$prompt),
            'context_hash' => hash(
                'sha256',
                ($proposal === null ? $wallpaper->compositionDetails() : $proposal->input_hash).'|image',
            ),
            'filename' => 'wallpaper-image-'.$wallpaper->target_date->format('Y-m-d').'.txt',
        ];
    }

    public function parseProposal(string $json): array
    {
        $json = trim($json);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/is', $json, $matches) === 1) {
            $json = trim($matches[1]);
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'proposal_json' => '構図提案を有効なJSON形式で入力してください。',
            ]);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw ValidationException::withMessages([
                'proposal_json' => '構図提案はJSONオブジェクトで入力してください。',
            ]);
        }

        $unexpected = array_diff(array_keys($payload), self::PROPOSAL_FIELDS);
        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'proposal_json' => '構図提案に未対応の項目が含まれています: '.implode(', ', $unexpected),
            ]);
        }

        return Validator::make($payload, [
            'title' => ['required', 'string', 'max:255'],
            'art_style' => ['required', 'string', 'max:255'],
            'conclusion' => ['required', 'string', 'max:65535'],
            'overview' => ['required', 'string', 'max:500000'],
            'composition' => ['required', 'string', 'max:500000'],
            'color_wu_xing' => ['required', 'string', 'max:500000'],
            'symbolism' => ['required', 'string', 'max:500000'],
        ])->validate();
    }

    public function saveProposal(
        Wallpaper $wallpaper,
        array $result,
        string $expectedInputHash,
        bool $reproposal,
    ): CompositionProposal {
        return DB::transaction(function () use ($wallpaper, $result, $expectedInputHash, $reproposal): CompositionProposal {
            $wallpaper = Wallpaper::query()->lockForUpdate()->findOrFail($wallpaper->id);
            $prepared = $this->composition(
                $wallpaper->target_date->format('Y-m-d'),
                $wallpaper,
                $reproposal,
            );
            if (! hash_equals($prepared['input_hash'], $expectedInputHash)) {
                throw new ExternalApiException('composition_prompt_stale', false);
            }

            if ($reproposal) {
                $wallpaper->proposals()->where('status', 'proposed')->update(['status' => 'rejected']);
            }

            $proposal = $wallpaper->proposals()->create([
                ...$result,
                'sequence' => ((int) $wallpaper->proposals()->max('sequence')) + 1,
                'status' => 'proposed',
                'calendar_context' => $prepared['calendar'],
                'analysis_hash' => $prepared['analysis_hash'],
                'input_hash' => $prepared['input_hash'],
            ]);
            $wallpaper->update([
                ...$result,
                'state' => 'proposed',
                'warnings' => $prepared['calendar']['warnings'] ?? [],
                'chosen_proposal_id' => null,
            ]);

            return $proposal;
        });
    }

    private function compositionInstructions(string $targetDate): string
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
