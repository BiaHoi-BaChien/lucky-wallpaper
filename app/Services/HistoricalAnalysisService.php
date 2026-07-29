<?php

namespace App\Services;

use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Models\Wallpaper;
use Illuminate\Support\Collection;

class HistoricalAnalysisService
{
    public function __construct(private readonly OpenAiClient $openAi) {}

    public function getOrCreate(Wallpaper $subject): AnalysisSnapshot
    {
        $records = Wallpaper::query()
            ->whereNotNull('title')
            ->whereNotNull('composition')
            ->orderBy('target_date')
            ->get(['target_date', 'prize_vnd', 'title', 'art_style', 'overview', 'composition', 'color_wu_xing', 'symbolism']);

        $hash = $this->dataHash($records);
        $promptVersion = (string) config('lucky.openai.prompt_version');
        $existing = AnalysisSnapshot::query()
            ->where('data_hash', $hash)
            ->where('prompt_version', $promptVersion)
            ->where('status', 'succeeded')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $summaries = [];
        foreach ($this->chunks($records) as $index => $chunk) {
            $input = json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $run = ApiRun::query()->create([
                'type' => 'historical_analysis_chunk',
                'model' => config('lucky.openai.text_model'),
                'prompt_version' => $promptVersion,
                'input_hash' => hash('sha256', $input),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
            ]);
            $result = $this->openAi->structured(
                $run,
                '宝くじ当選を保証せず、過去の壁紙構図と賞金額の相関として分析してください。因果関係や当選確率の向上を断定してはいけません。',
                '分析チャンク '.($index + 1)."\n".$input,
                $this->summarySchema(),
                'wallpaper_analysis_chunk',
            );
            $summaries[] = $result['summary'];
        }

        if ($summaries === []) {
            $summary = '有効な過去実績はまだありません。新規性と画風ローテーションを優先してください。';
        } elseif (count($summaries) === 1) {
            $summary = $summaries[0];
        } else {
            $input = json_encode($summaries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $run = ApiRun::query()->create([
                'type' => 'historical_analysis_merge',
                'model' => config('lucky.openai.text_model'),
                'prompt_version' => $promptVersion,
                'input_hash' => hash('sha256', $input),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
            ]);
            $result = $this->openAi->structured(
                $run,
                '複数の部分分析を統合し、再現可能な傾向、反例、新規探索余地を簡潔にまとめてください。当選の保証や因果関係の断定は禁止です。',
                $input,
                $this->summarySchema(),
                'wallpaper_analysis_summary',
            );
            $summary = $result['summary'];
        }

        return AnalysisSnapshot::query()->create([
            'data_hash' => $hash,
            'prompt_version' => $promptVersion,
            'model' => config('lucky.openai.text_model'),
            'summary' => $summary,
            'statistics' => [
                'records' => $records->count(),
                'chunks' => count($summaries),
                'max_prize_vnd' => $records->max('prize_vnd'),
            ],
            'status' => 'succeeded',
        ]);
    }

    public function dataHash(Collection $records): string
    {
        $canonical = $records->map(fn (Wallpaper $wallpaper): array => [
            'target_date' => $wallpaper->target_date->format('Y-m-d'),
            'prize_vnd' => $wallpaper->prize_vnd,
            'title' => $wallpaper->title,
            'art_style' => $wallpaper->art_style,
            'overview' => $wallpaper->overview,
            'composition' => $wallpaper->composition,
            'color_wu_xing' => $wallpaper->color_wu_xing,
            'symbolism' => $wallpaper->symbolism,
        ])->all();

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function chunks(Collection $records): array
    {
        $maxRecords = (int) config('lucky.analysis.records_per_chunk');
        $maxCharacters = (int) config('lucky.analysis.characters_per_chunk');
        $chunks = [];
        $current = [];
        $characters = 0;

        foreach ($records as $record) {
            $row = [
                'date' => $record->target_date->format('Y-m-d'),
                'price_vnd' => $record->prize_vnd,
                'title' => $record->title,
                'art_style' => $record->art_style,
                'overview' => $record->overview,
                'composition' => $record->composition,
                'color_wu_xing' => $record->color_wu_xing,
                'symbolism' => $record->symbolism,
            ];
            $length = mb_strlen(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if ($current !== [] && (count($current) >= $maxRecords || $characters + $length > $maxCharacters)) {
                $chunks[] = $current;
                $current = [];
                $characters = 0;
            }
            $current[] = $row;
            $characters += $length;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function summarySchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary'],
            'properties' => ['summary' => ['type' => 'string']],
        ];
    }
}
