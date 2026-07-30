<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use App\Models\ApiRun;
use App\Models\SyncRun;
use App\Models\Wallpaper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class WallpaperImageRestoreService
{
    public function __construct(
        private readonly NotionClient $notion,
        private readonly ImageService $images,
    ) {}

    public function restore(Wallpaper $wallpaper): bool
    {
        if ($this->hasLocalImage($wallpaper)) {
            return false;
        }

        $this->ensureNoActiveProcesses($wallpaper);

        if (! $this->notion->isConfigured()) {
            throw ValidationException::withMessages([
                'restoreImage' => 'Notionから画像を復元するにはNOTION_TOKENの設定が必要です。',
            ]);
        }

        if ($wallpaper->notion_page_id === null || $wallpaper->notion_page_id === '') {
            $this->throwImageMissing();
        }

        $page = $this->notion->getPage($wallpaper->notion_page_id);
        $url = $this->notion->wallpaperFileUrl($page);
        if ($url === null) {
            $this->throwImageMissing();
        }

        $response = Http::timeout(60)->get($url);
        if ($response->status() === 404) {
            $this->throwImageMissing();
        }
        if (! $response->successful()) {
            throw new ExternalApiException(
                'notion_image_http_'.$response->status(),
                $response->serverError(),
            );
        }

        $stored = $this->images->normalizeAndStore($response->body());

        try {
            $wallpaper->update([
                'image_disk' => $stored['disk'],
                'image_path' => $stored['path'],
                'image_mime' => $stored['mime'],
                'image_bytes' => $stored['bytes'],
                'image_sha256' => $stored['sha256'],
            ]);
        } catch (Throwable $exception) {
            try {
                Storage::disk($stored['disk'])->delete($stored['path']);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        return true;
    }

    private function hasLocalImage(Wallpaper $wallpaper): bool
    {
        return $wallpaper->image_disk !== null
            && $wallpaper->image_path !== null
            && Storage::disk($wallpaper->image_disk)->exists($wallpaper->image_path);
    }

    private function ensureNoActiveProcesses(Wallpaper $wallpaper): void
    {
        $hasActiveApiRun = ApiRun::query()
            ->where('subject_type', $wallpaper->getMorphClass())
            ->where('subject_id', $wallpaper->getKey())
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        $hasActiveSyncRun = SyncRun::query()
            ->where('wallpaper_id', $wallpaper->getKey())
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($hasActiveApiRun || $hasActiveSyncRun) {
            throw ValidationException::withMessages([
                'restoreImage' => '処理中の履歴は復元できません。処理完了後に再試行してください。',
            ]);
        }
    }

    private function throwImageMissing(): never
    {
        throw ValidationException::withMessages([
            'restoreImage' => 'Notionバックアップに画像ファイルがありません。',
        ]);
    }
}
