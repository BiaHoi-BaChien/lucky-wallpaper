<?php

namespace App\Services;

use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Models\SyncRun;
use App\Models\Wallpaper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class WallpaperDeletionService
{
    public function __construct(private readonly NotionClient $notion) {}

    public function delete(Wallpaper $wallpaper): void
    {
        $this->ensureNoActiveProcesses($wallpaper);

        $imagePath = $wallpaper->image_path;
        $disk = null;
        $imageBytes = null;

        if ($imagePath !== null) {
            $disk = Storage::disk(
                $wallpaper->image_disk ?: (string) config('filesystems.default'),
            );
            if ($disk->exists($imagePath)) {
                $imageBytes = $disk->get($imagePath);
            }
        }

        $notionPageId = $wallpaper->notion_page_id;
        $notionTrashed = false;
        $imageDeleted = false;

        try {
            if ($notionPageId !== null && $notionPageId !== '') {
                $this->notion->trashPage($notionPageId);
                $notionTrashed = true;
            }

            if ($disk !== null && $imageBytes !== null) {
                if (! $disk->delete($imagePath)) {
                    throw new RuntimeException('wallpaper_image_delete_failed');
                }

                $imageDeleted = true;
            }

            DB::transaction(function () use ($wallpaper): void {
                ApiRun::query()
                    ->where('subject_type', $wallpaper->getMorphClass())
                    ->where('subject_id', $wallpaper->getKey())
                    ->delete();
                SyncRun::query()->where('wallpaper_id', $wallpaper->getKey())->delete();
                AnalysisSnapshot::query()
                    ->where('status', 'succeeded')
                    ->update(['status' => 'invalidated']);
                $wallpaper->deleteOrFail();
            });
        } catch (Throwable $exception) {
            if ($imageDeleted && $disk !== null && $imageBytes !== null) {
                try {
                    $disk->put($imagePath, $imageBytes);
                } catch (Throwable $restoreException) {
                    report($restoreException);
                }
            }

            if ($notionTrashed && $notionPageId !== null) {
                try {
                    $this->notion->restorePage($notionPageId);
                } catch (Throwable $restoreException) {
                    report($restoreException);
                }
            }

            throw $exception;
        }
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
                'delete' => '処理中の履歴は削除できません。処理完了後に再試行してください。',
            ]);
        }
    }
}
