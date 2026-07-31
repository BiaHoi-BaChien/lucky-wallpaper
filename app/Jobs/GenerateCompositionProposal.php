<?php

namespace App\Jobs;

use App\Exceptions\ExternalApiException;
use App\Models\ApiRun;
use App\Models\Wallpaper;
use App\Services\OpenAiClient;
use App\Services\WallpaperPromptService;
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
        WallpaperPromptService $prompts,
        OpenAiClient $openAi,
    ): void {
        $wallpaper = Wallpaper::query()->findOrFail($this->wallpaperId);
        $run = ApiRun::query()->findOrFail($this->apiRunId);
        $prepared = $prompts->composition(
            $wallpaper->target_date->format('Y-m-d'),
            $wallpaper,
            $this->reproposal,
        );

        $result = $openAi->structured(
            $run,
            $prepared['instructions'],
            $prepared['input'],
            OpenAiClient::PROPOSAL_SCHEMA,
            'wallpaper_composition',
        );
        $prompts->saveProposal($wallpaper, $result, $prepared['input_hash'], $this->reproposal);
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
}
