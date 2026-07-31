<?php

namespace App\Jobs;

use App\Exceptions\ExternalApiException;
use App\Models\ApiRun;
use App\Models\CompositionProposal;
use App\Models\Wallpaper;
use App\Services\ImageService;
use App\Services\OpenAiClient;
use App\Services\WallpaperPromptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateWallpaperImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public readonly int $wallpaperId,
        public readonly ?int $proposalId,
        public readonly string $apiRunId,
    ) {
        $this->onQueue('openai');
    }

    public function handle(
        OpenAiClient $openAi,
        ImageService $images,
        WallpaperPromptService $prompts,
    ): void {
        $wallpaper = Wallpaper::query()->findOrFail($this->wallpaperId);
        $proposal = $this->proposalId === null
            ? null
            : CompositionProposal::query()
                ->where('wallpaper_id', $wallpaper->id)
                ->findOrFail($this->proposalId);
        $run = ApiRun::query()->findOrFail($this->apiRunId);
        $prepared = $prompts->image($wallpaper, $proposal);
        $bytes = $openAi->image($run, $prepared['prompt']);
        $stored = $images->normalizeAndStore($bytes);

        $wallpaper->update([
            'chosen_proposal_id' => $proposal?->id,
            'image_disk' => $stored['disk'],
            'image_path' => $stored['path'],
            'image_mime' => $stored['mime'],
            'image_bytes' => $stored['bytes'],
            'image_sha256' => $stored['sha256'],
            'state' => 'generated',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $run = ApiRun::query()->find($this->apiRunId);
        if ($run !== null && $run->status !== 'failed') {
            $run->update([
                'status' => 'failed',
                'error_code' => $exception instanceof ExternalApiException ? $exception->errorCode : 'image_job_failed',
                'retryable' => $exception instanceof ExternalApiException ? $exception->retryable : true,
                'finished_at' => now(),
            ]);
        }
    }
}
