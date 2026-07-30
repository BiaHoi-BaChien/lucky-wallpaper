<?php

namespace App\Jobs;

use App\Exceptions\ExternalApiException;
use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Services\HistoricalAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateHistoricalAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public readonly int $snapshotId,
        public readonly string $apiRunId,
    ) {
        $this->onQueue('openai');
    }

    public function handle(HistoricalAnalysisService $analysisService): void
    {
        $snapshot = AnalysisSnapshot::query()->findOrFail($this->snapshotId);
        $run = ApiRun::query()->findOrFail($this->apiRunId);

        $snapshot->update(['status' => 'running']);
        $run->update([
            'status' => 'running',
            'started_at' => now(),
            'error_code' => null,
            'retryable' => false,
        ]);

        $analysisService->analyze($snapshot);

        $run->update([
            'status' => 'succeeded',
            'finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        AnalysisSnapshot::query()->whereKey($this->snapshotId)->update(['status' => 'failed']);
        ApiRun::query()->whereKey($this->apiRunId)->update([
            'status' => 'failed',
            'error_code' => $exception instanceof ExternalApiException ? $exception->errorCode : 'historical_analysis_job_failed',
            'retryable' => $exception instanceof ExternalApiException ? $exception->retryable : true,
            'finished_at' => now(),
        ]);
    }
}
