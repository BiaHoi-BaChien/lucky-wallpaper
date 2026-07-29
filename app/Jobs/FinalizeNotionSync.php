<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\SyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinalizeNotionSync implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        $run = SyncRun::query()->findOrFail($this->runId);
        if ($run->status === 'failed') {
            return;
        }

        $checkpoint = $run->started_at ?? now();
        AppSetting::write('notion_last_successful_sync_at', $checkpoint->toIso8601String());
        $run->update([
            'status' => 'succeeded',
            'checkpoint_at' => $checkpoint,
            'finished_at' => now(),
            'retryable' => false,
        ]);
    }
}
