<?php

namespace App\Jobs;

use App\Exceptions\ExternalApiException;
use App\Models\AppSetting;
use App\Models\SyncRun;
use App\Models\Wallpaper;
use App\Services\NotionClient;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class ProcessNotionSync implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('integrations');
    }

    public function handle(NotionClient $notion): void
    {
        $run = SyncRun::query()->findOrFail($this->runId);
        $run->update(['status' => 'running', 'started_at' => now(), 'retryable' => false, 'error_code' => null]);

        $editedAfter = AppSetting::read('notion_last_successful_sync_at');
        if ($editedAfter !== null) {
            $editedAfter = CarbonImmutable::parse($editedAfter)->subMinute()->toIso8601String();
        }

        $localDates = Wallpaper::query()->pluck('target_date')->map(fn ($date) => substr((string) $date, 0, 10))->flip();
        $candidates = [];
        $dateOccurrences = [];
        $invalid = 0;
        $cursor = null;

        do {
            $page = $notion->queryDataSource($cursor, $editedAfter);
            foreach ($page['results'] ?? [] as $notionPage) {
                $candidate = $notion->parseCandidate($notionPage);
                if (
                    $candidate['page_id'] === ''
                    || ! is_string($candidate['target_date'])
                    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate['target_date']) !== 1
                    || $candidate['price_vnd'] === null
                    || $candidate['title'] === ''
                ) {
                    $invalid++;

                    continue;
                }
                $date = $candidate['target_date'];
                $dateOccurrences[$date] = ($dateOccurrences[$date] ?? 0) + 1;
                if (! isset($candidates[$date]) || $candidate['last_edited_time'] > $candidates[$date]['last_edited_time']) {
                    $candidates[$date] = $candidate;
                }
            }
            $cursor = $page['next_cursor'] ?? null;
        } while (($page['has_more'] ?? false) === true && is_string($cursor));

        $warnings = [];
        $allCount = count($candidates);
        foreach ($dateOccurrences as $date => $count) {
            if ($count > 1) {
                $warnings[] = "{$date}: Notionに同日候補が複数あるため、最終更新が最新のページを採用しました。";
            }
        }

        $newCandidates = [];
        $existing = 0;
        foreach ($candidates as $candidate) {
            if ($localDates->has($candidate['target_date'])) {
                $existing++;
            } else {
                $newCandidates[] = $candidate;
            }
        }

        $run->update([
            'total' => $allCount,
            'processed' => $existing,
            'skipped_existing' => $existing,
            'skipped_invalid' => $invalid,
            'warnings' => $warnings,
        ]);

        if ($newCandidates === []) {
            FinalizeNotionSync::dispatch($this->runId);

            return;
        }

        $jobs = array_map(
            fn (array $chunk) => new ImportNotionPages($this->runId, $chunk),
            array_chunk($newCandidates, 20),
        );

        $runId = $this->runId;

        Bus::batch($jobs)
            ->name('notion-sync-'.$runId)
            ->onQueue('integrations')
            ->then(static fn (Batch $batch) => FinalizeNotionSync::dispatch($runId))
            ->catch(static function (Batch $batch, Throwable $exception) use ($runId): void {
                SyncRun::query()->whereKey($runId)->update([
                    'status' => 'failed',
                    'retryable' => true,
                    'error_code' => 'notion_import_batch_failed',
                    'finished_at' => now(),
                ]);
            })
            ->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        SyncRun::query()->whereKey($this->runId)->update([
            'status' => 'failed',
            'retryable' => $exception instanceof ExternalApiException ? $exception->retryable : true,
            'error_code' => $exception instanceof ExternalApiException ? $exception->errorCode : 'notion_sync_failed',
            'finished_at' => now(),
        ]);
    }
}
