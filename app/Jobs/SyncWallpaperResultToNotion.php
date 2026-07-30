<?php

namespace App\Jobs;

use App\Exceptions\ExternalApiException;
use App\Models\SyncRun;
use App\Models\Wallpaper;
use App\Services\ImageService;
use App\Services\NotionClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SyncWallpaperResultToNotion implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 600;

    public function __construct(public readonly int $wallpaperId, public readonly string $runId)
    {
        $this->onQueue('integrations');
    }

    public function handle(NotionClient $notion, ImageService $images): void
    {
        $run = SyncRun::query()->findOrFail($this->runId);
        $run->update(['status' => 'running', 'started_at' => now(), 'retryable' => false, 'error_code' => null]);
        $wallpaper = Wallpaper::query()->findOrFail($this->wallpaperId);
        $targetDate = $wallpaper->target_date->format('Y-m-d');

        $pageId = $wallpaper->notion_page_id;
        if ($pageId === null) {
            $matches = $notion->findPagesByDate($targetDate);
            if (count($matches) > 1) {
                throw new ExternalApiException('notion_duplicate_date', false);
            }
            if ($matches === []) {
                $pageId = $notion->createWallpaperPage([
                    'target_date' => $targetDate,
                    'title' => (string) $wallpaper->title,
                    'price_vnd' => (int) $wallpaper->prize_vnd,
                    'body' => $wallpaper->compositionDetails(),
                ]);
            } else {
                $pageId = (string) $matches[0]['id'];
            }
            $wallpaper->update(['notion_page_id' => $pageId]);
        }

        $fileUploadId = null;
        $fileName = null;
        $hadLocalImage = $wallpaper->image_path !== null;
        if ($hadLocalImage) {
            $bytes = Storage::disk((string) $wallpaper->image_disk)->get((string) $wallpaper->image_path);
            $bytes = $images->fitForNotion($bytes);
            $fileName = basename((string) $wallpaper->image_path);
            $fileUploadId = $notion->uploadFile($bytes, $fileName);
        }

        $notion->updateResult($pageId, (int) $wallpaper->prize_vnd, $fileUploadId, $fileName);
        $verifiedPage = $notion->getPage($pageId);
        $verified = $notion->parseCandidate($verifiedPage);
        if ($verified['target_date'] !== $targetDate || $verified['price_vnd'] !== (int) $wallpaper->prize_vnd) {
            throw new ExternalApiException('notion_verification_failed', true);
        }
        if ($hadLocalImage && $notion->wallpaperFileUrl($verifiedPage) === null) {
            throw new ExternalApiException('notion_file_verification_failed', true);
        }

        $deleteLocalImage = $hadLocalImage && (bool) config('lucky.image.delete_after_notion_backup');
        if ($deleteLocalImage) {
            Storage::disk((string) $wallpaper->image_disk)->delete((string) $wallpaper->image_path);
        }

        $wallpaper->update([
            'image_disk' => $deleteLocalImage ? null : $wallpaper->image_disk,
            'image_path' => $deleteLocalImage ? null : $wallpaper->image_path,
            'image_bytes' => $deleteLocalImage ? null : $wallpaper->image_bytes,
            'state' => $deleteLocalImage ? 'archived' : 'result_synced',
            'result_synced_at' => now(),
        ]);
        $run->update([
            'status' => 'succeeded',
            'total' => 1,
            'processed' => 1,
            'imported' => 1,
            'finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        SyncRun::query()->whereKey($this->runId)->update([
            'status' => 'failed',
            'retryable' => $exception instanceof ExternalApiException ? $exception->retryable : true,
            'error_code' => $exception instanceof ExternalApiException ? $exception->errorCode : 'notion_result_sync_failed',
            'finished_at' => now(),
        ]);
    }
}
