<?php

namespace App\Jobs;

use App\Exceptions\ExternalApiException;
use App\Models\ApiRun;
use App\Models\CompositionProposal;
use App\Models\Wallpaper;
use App\Services\ImageService;
use App\Services\KigakuPolicy;
use App\Services\OpenAiClient;
use App\Services\WallpaperPromptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateWallpaper implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 420;

    public function __construct(public readonly string $runId, public readonly string $targetDate)
    {
        $this->onConnection('database');
    }

    public function handle(OpenAiClient $openai, WallpaperPromptService $prompts, ImageService $images): void
    {
        $claimed = ApiRun::query()->whereKey($this->runId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $stored = null;
        try {
            $run = ApiRun::query()->findOrFail($this->runId);
            if (Wallpaper::query()->where('target_date', $this->targetDate)->exists()) {
                throw new ExternalApiException('wallpaper_already_exists');
            }
            $prepared = $prompts->composition($this->targetDate, jsonOnly: true);
            if (! hash_equals($run->input_hash, $prepared['input_hash'])) {
                throw new ExternalApiException('composition_prompt_stale');
            }
            $result = $prompts->parseProposal($openai->composition($prepared['prompt']), $this->targetDate);
            $composition = array_intersect_key($result, array_flip(KigakuPolicy::COMPOSITION_FIELDS));
            $proposal = new CompositionProposal([
                ...$composition, 'calendar_context' => ['kigaku' => $result['kigaku']],
            ]);
            $imagePrompt = $prompts->image(new Wallpaper(['target_date' => $this->targetDate]), $proposal, imageOnly: true);
            $stored = $images->normalizeAndStore($openai->image($imagePrompt['prompt']));

            DB::transaction(function () use ($run, $result, $prepared, $prompts, $stored): void {
                $wallpaper = Wallpaper::query()->create([
                    'target_date' => $this->targetDate, 'source' => 'generated', 'state' => 'draft',
                ]);
                $prompts->saveProposal($wallpaper, $result, $prepared['input_hash'], false);
                $wallpaper->update([
                    'image_disk' => $stored['disk'], 'image_path' => $stored['path'],
                    'image_mime' => $stored['mime'], 'image_bytes' => $stored['bytes'],
                    'image_sha256' => $stored['sha256'], 'state' => 'generated',
                ]);
                $run->update([
                    'status' => 'succeeded', 'finished_at' => now(),
                    'subject_type' => $wallpaper->getMorphClass(), 'subject_id' => $wallpaper->id,
                ]);
            });
        } catch (Throwable $exception) {
            try {
                if ($stored !== null) {
                    Storage::disk($stored['disk'])->delete($stored['path']);
                }
            } finally {
                $this->failed($exception);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $code = match (true) {
            $exception instanceof ExternalApiException => $exception->errorCode,
            $exception instanceof ValidationException => 'openai_invalid_composition',
            $exception instanceof UniqueConstraintViolationException => 'wallpaper_already_exists',
            default => 'wallpaper_generation_failed',
        };
        ApiRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error_code' => $code, 'retryable' => false, 'finished_at' => now(),
        ]);
    }
}
