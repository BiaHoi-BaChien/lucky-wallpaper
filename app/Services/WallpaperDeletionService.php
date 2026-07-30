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
    public function deleteImage(Wallpaper $wallpaper): bool
    {
        $this->ensureNoActiveProcesses($wallpaper, 'deleteImage');

        $imagePath = $wallpaper->image_path;
        if ($imagePath === null) {
            return false;
        }

        $disk = Storage::disk(
            $wallpaper->image_disk ?: (string) config('filesystems.default'),
        );
        if (! $disk->exists($imagePath)) {
            $this->clearImageMetadata($wallpaper);

            return false;
        }

        $imageBytes = $disk->get($imagePath);
        if (! $disk->delete($imagePath)) {
            throw new RuntimeException('wallpaper_image_delete_failed');
        }

        try {
            $this->clearImageMetadata($wallpaper);
        } catch (Throwable $exception) {
            try {
                $disk->put($imagePath, $imageBytes);
            } catch (Throwable $restoreException) {
                report($restoreException);
            }

            throw $exception;
        }

        return true;
    }

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

        $imageDeleted = false;

        try {
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

            throw $exception;
        }
    }

    private function ensureNoActiveProcesses(Wallpaper $wallpaper, string $errorKey = 'delete'): void
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
                $errorKey => '処理中の履歴は削除できません。処理完了後に再試行してください。',
            ]);
        }
    }

    private function clearImageMetadata(Wallpaper $wallpaper): void
    {
        $wallpaper->update([
            'image_disk' => null,
            'image_path' => null,
            'image_mime' => null,
            'image_bytes' => null,
            'image_sha256' => null,
        ]);
    }
}
