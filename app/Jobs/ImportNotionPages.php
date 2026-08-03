<?php

namespace App\Jobs;

use App\Exceptions\ExternalApiException;
use App\Models\AnalysisSnapshot;
use App\Models\SyncRun;
use App\Models\Wallpaper;
use App\Services\NotionClient;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportNotionPages implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 4;

    public int $timeout = 240;

    public function __construct(public readonly string $runId, public readonly array $candidates)
    {
        $this->onQueue('integrations');
    }

    public function handle(NotionClient $notion): void
    {
        foreach ($this->candidates as $candidate) {
            if (Wallpaper::query()->where('target_date', $candidate['target_date'])->exists()) {
                SyncRun::query()->whereKey($this->runId)->increment('skipped_existing');
                SyncRun::query()->whereKey($this->runId)->increment('processed');

                continue;
            }

            try {
                $body = $notion->getPageBody($candidate['page_id']);
            } catch (ExternalApiException $exception) {
                if (! $exception->retryable) {
                    $this->fail($exception);
                }

                throw $exception;
            }
            if ($body === '') {
                SyncRun::query()->whereKey($this->runId)->increment('skipped_empty_body');
                SyncRun::query()->whereKey($this->runId)->increment('processed');

                continue;
            }

            try {
                Wallpaper::query()->create([
                    'target_date' => $candidate['target_date'],
                    'title' => $candidate['title'],
                    'conclusion' => $candidate['title'],
                    'overview' => $body,
                    'composition' => $body,
                    'prize_vnd' => $candidate['price_vnd'],
                    'source' => 'notion',
                    'notion_page_id' => $candidate['page_id'],
                    'state' => 'imported',
                ]);
                SyncRun::query()->whereKey($this->runId)->increment('imported');
                AnalysisSnapshot::query()->where('status', 'succeeded')->update(['status' => 'invalidated']);
            } catch (QueryException) {
                SyncRun::query()->whereKey($this->runId)->increment('skipped_existing');
            }
            SyncRun::query()->whereKey($this->runId)->increment('processed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        SyncRun::query()->whereKey($this->runId)->update([
            'status' => 'failed',
            'retryable' => $exception instanceof ExternalApiException ? $exception->retryable : true,
            'error_code' => $exception instanceof ExternalApiException ? $exception->errorCode : 'notion_import_batch_failed',
            'finished_at' => now(),
        ]);
    }
}
