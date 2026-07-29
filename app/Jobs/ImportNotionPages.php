<?php

namespace App\Jobs;

use App\Models\AnalysisSnapshot;
use App\Models\SyncRun;
use App\Models\Wallpaper;
use App\Services\NotionClient;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;

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

            $body = $notion->getPageBody($candidate['page_id']);
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
}
