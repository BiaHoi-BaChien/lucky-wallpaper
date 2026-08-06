<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Models\Wallpaper;
use Illuminate\Support\Collection;

class HistoricalAnalysisService
{
    private const MANUAL_DATA_FILENAME = 'wallpaper-analysis-data.json';

    private const EMPTY_SUMMARY = <<<'MARKDOWN'
# 高額当選壁紙の傾向分析

## 対象データ

構図と当選金額が登録された壁紙履歴はまだありません。

## 構図提案への活用指針

過去傾向を参照できないため、新規性と画風のローテーションを優先します。

## 注意点

この分析は過去実績との相関を扱うもので、当選や当選確率の向上を保証するものではありません。
MARKDOWN;

    public function __construct(private readonly OpenAiClient $openAi) {}

    public function currentDataHash(): string
    {
        return $this->dataHash($this->records());
    }

    public function currentSnapshot(): ?AnalysisSnapshot
    {
        return AnalysisSnapshot::query()
            ->where('data_hash', $this->currentDataHash())
            ->where('prompt_version', config('lucky.openai.prompt_version'))
            ->where('status', 'succeeded')
            ->latest()
            ->first();
    }

    public function latestDisplayableSnapshot(): ?AnalysisSnapshot
    {
        return AnalysisSnapshot::query()
            ->where('summary', '!=', '')
            ->latest()
            ->first();
    }

    public function records(): Collection
    {
        return Wallpaper::query()
            ->whereNotNull('prize_vnd')
            ->whereNotNull('title')
            ->whereNotNull('composition')
            ->orderBy('target_date')
            ->get(['target_date', 'prize_vnd', 'title', 'art_style', 'overview', 'composition', 'color_wu_xing', 'symbolism']);
    }

    public function analyze(AnalysisSnapshot $snapshot): AnalysisSnapshot
    {
        $records = $this->records();
        if (! hash_equals($snapshot->data_hash, $this->dataHash($records))) {
            throw new ExternalApiException('historical_analysis_stale_input', false);
        }

        $summaries = [];
        foreach ($this->chunks($records) as $index => $chunk) {
            $input = json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $run = ApiRun::query()->create([
                'type' => 'historical_analysis_chunk',
                'model' => config('lucky.openai.text_model'),
                'prompt_version' => $snapshot->prompt_version,
                'input_hash' => hash('sha256', $input),
                'subject_type' => $snapshot->getMorphClass(),
                'subject_id' => $snapshot->getKey(),
            ]);
            $result = $this->openAi->structured(
                $run,
                $this->chunkInstructions(),
                '分析チャンク '.($index + 1)."\n\n".$input,
                $this->summarySchema(),
                'wallpaper_analysis_chunk',
            );
            $summaries[] = $this->normalizeMarkdown($result['analysis_markdown']);
        }

        if ($summaries === []) {
            $summary = self::EMPTY_SUMMARY;
        } elseif (count($summaries) === 1) {
            $summary = $summaries[0];
        } else {
            $input = json_encode($summaries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $run = ApiRun::query()->create([
                'type' => 'historical_analysis_merge',
                'model' => config('lucky.openai.text_model'),
                'prompt_version' => $snapshot->prompt_version,
                'input_hash' => hash('sha256', $input),
                'subject_type' => $snapshot->getMorphClass(),
                'subject_id' => $snapshot->getKey(),
            ]);
            $result = $this->openAi->structured(
                $run,
                $this->mergeInstructions(),
                $input,
                $this->summarySchema(),
                'wallpaper_analysis_summary',
            );
            $summary = $this->normalizeMarkdown($result['analysis_markdown']);
        }

        $snapshot->update([
            'model' => config('lucky.openai.text_model'),
            'summary' => $summary,
            'statistics' => $this->statistics($records, count($summaries)),
            'status' => hash_equals($snapshot->data_hash, $this->currentDataHash()) ? 'succeeded' : 'invalidated',
        ]);

        return $snapshot->refresh();
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
        $highPrizeThreshold = $this->highPrizeThreshold($records);
        $chunks = [];
        $current = [];
        $characters = 0;

        foreach ($records->sortByDesc('prize_vnd') as $record) {
            $row = [
                'date' => $record->target_date->format('Y-m-d'),
                'prize_vnd' => $record->prize_vnd,
                'is_high_prize' => $record->prize_vnd >= $highPrizeThreshold,
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

    /**
     * @return array{
     *     prompt: string,
     *     prompt_hash: string,
     *     context_hash: string,
     *     filename: string,
     *     data_filename: string,
     *     default_result: string|null
     * }
     */
    public function manualPrompt(): array
    {
        $records = $this->records();
        $dataHash = $this->dataHash($records);
        $dataFilename = self::MANUAL_DATA_FILENAME;
        $recordCount = $records->count();
        $prompt = $this->chunkInstructions().<<<PROMPT


ChatGPTプロジェクトの「利用可能なソース」に、次の分析データJSONを登録してから分析してください。
ファイル名: {$dataFilename}
データハッシュ: {$dataHash}
対象件数: {$recordCount}件

PythonでJSONファイル全体を読み込み、data_hash、record_count、recordsの実件数、is_high_prize=true/falseの件数を最初に検証してください。
JSON内のdata_hashが上記データハッシュと一致しない場合は分析を中止し、正しいファイルの登録を依頼してください。
一部レコードの検索結果だけで判断せず、recordsの全件を一括して分析してください。追加質問は行わないでください。
回答は「# 高額当選壁紙の傾向分析」から始まる日本語Markdown本文だけにしてください。JSONやコードフェンスは使用しないでください。
分析結果は画面に表示するとともに、同じ内容をUTF-8のMarkdownファイル（wallpaper-analysis.md）としてダウンロードできるようにしてください。
PROMPT;

        return [
            'prompt' => $prompt,
            'prompt_hash' => hash('sha256', config('lucky.openai.prompt_version').'|'.$prompt),
            'context_hash' => $dataHash,
            'filename' => 'wallpaper-analysis-'.substr($dataHash, 0, 12).'.txt',
            'data_filename' => self::MANUAL_DATA_FILENAME,
            'default_result' => $records->isEmpty() ? self::EMPTY_SUMMARY : null,
        ];
    }

    /**
     * @return array{content: string, filename: string}
     */
    public function manualData(string $expectedDataHash): array
    {
        $records = $this->records();
        $dataHash = $this->dataHash($records);
        if (! hash_equals($dataHash, $expectedDataHash)) {
            throw new ExternalApiException('historical_analysis_stale_input', false);
        }

        $rows = collect($this->chunks($records))->flatten(1)->values()->all();
        $payload = [
            'schema_version' => '1',
            'generated_at' => now()->timezone((string) config('lucky.timezone'))->toIso8601String(),
            'timezone' => (string) config('lucky.timezone'),
            'data_hash' => $dataHash,
            'record_count' => $records->count(),
            'high_prize_threshold_vnd' => $this->highPrizeThreshold($records),
            'records' => $rows,
        ];

        return [
            'content' => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            )."\n",
            'filename' => self::MANUAL_DATA_FILENAME,
        ];
    }

    public function saveManualResult(string $markdown, string $dataHash, string $promptHash): AnalysisSnapshot
    {
        $prompt = $this->manualPrompt();
        if (
            ! hash_equals($prompt['context_hash'], $dataHash)
            || ! hash_equals($prompt['prompt_hash'], $promptHash)
        ) {
            throw new ExternalApiException('historical_analysis_stale_input', false);
        }

        $records = $this->records();
        $summary = $records->isEmpty()
            ? self::EMPTY_SUMMARY
            : $this->normalizeMarkdown($markdown);
        $snapshot = AnalysisSnapshot::query()->firstOrNew([
            'data_hash' => $dataHash,
            'prompt_version' => (string) config('lucky.openai.prompt_version'),
        ]);
        $snapshot->fill([
            'model' => 'chatgpt-manual',
            'summary' => $summary,
            'statistics' => $this->statistics($records, $records->isEmpty() ? 0 : 1),
            'status' => 'succeeded',
        ])->save();

        return $snapshot->refresh();
    }

    private function summarySchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['analysis_markdown'],
            'properties' => ['analysis_markdown' => ['type' => 'string']],
        ];
    }

    private function highPrizeThreshold(Collection $records): int
    {
        $prizes = $records->pluck('prize_vnd')
            ->filter(fn (mixed $prize): bool => is_int($prize))
            ->sort()
            ->values();
        if ($prizes->isEmpty()) {
            return 0;
        }

        $index = (int) floor(($prizes->count() - 1) * 0.75);

        return (int) $prizes->get($index);
    }

    private function normalizeMarkdown(string $markdown): string
    {
        $markdown = trim($markdown);
        if (! str_starts_with($markdown, '# ')) {
            $markdown = "# 高額当選壁紙の傾向分析\n\n".$markdown;
        }

        return $markdown;
    }

    private function statistics(Collection $records, int $chunks): array
    {
        return [
            'records' => $records->count(),
            'chunks' => $chunks,
            'max_prize_vnd' => $records->max('prize_vnd'),
            'high_prize_threshold_vnd' => $this->highPrizeThreshold($records),
        ];
    }

    private function chunkInstructions(): string
    {
        return <<<'PROMPT'
あなたは壁紙の過去実績を分析するデータアナリストです。
入力は当選金額の高い順で、全体の上位25%に相当する壁紙には is_high_prize=true が付いています。
高額当選側とそれ以外を比較し、構図、画風、色彩、モチーフ、象徴の相関傾向と反例を分析してください。
因果関係や当選確率の向上を断定せず、サンプル数が少ない場合はその限界を明記してください。
analysis_markdown にはコードフェンスを使わない日本語Markdownを格納し、見出し、箇条書きを使用してください。
PROMPT;
    }

    private function mergeInstructions(): string
    {
        return <<<'PROMPT'
複数の部分分析を統合し、重複を除いた一つの日本語Markdown文書にしてください。
「# 高額当選壁紙の傾向分析」を先頭見出しとし、対象データ、高額当選側で見られる傾向、反例・注意点、構図提案への活用指針を含めてください。
因果関係や当選確率の向上を断定せず、未知の構図を探索する余地も残してください。
analysis_markdown にMarkdown本文だけを格納し、コードフェンスは使用しないでください。
PROMPT;
    }
}
