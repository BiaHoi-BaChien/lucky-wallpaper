<?php

namespace App\Http\Controllers;

use App\Exceptions\ExternalApiException;
use App\Http\Requests\WallpaperFilterRequest;
use App\Models\Wallpaper;
use App\Services\ImageService;
use App\Services\KigakuPolicy;
use App\Services\NotionClient;
use App\Services\WallpaperDeletionService;
use App\Services\WallpaperImageRestoreService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class WallpaperController extends Controller
{
    public function create(Request $request): Response
    {
        $defaultDate = CarbonImmutable::now(config('lucky.timezone'))->addDay()->toDateString();
        $selectedDate = $request->string('date')->toString() ?: $defaultDate;

        return Inertia::render('wallpapers/create', [
            'defaultDate' => $defaultDate,
            'selectedDate' => $selectedDate,
            'existing' => Wallpaper::query()->where('target_date', $selectedDate)->first(),
        ]);
    }

    public function show(Wallpaper $wallpaper, NotionClient $notion): Response
    {
        $localImageAvailable = $this->hasLocalImage($wallpaper);
        $downloadRequiresNotionConfiguration = ! $localImageAvailable
            && $wallpaper->notion_page_id !== null
            && ! $notion->isConfigured();

        return Inertia::render('wallpapers/show', [
            'wallpaper' => $wallpaper->load(['proposals' => fn ($query) => $query->orderByDesc('sequence')]),
            'kigaku' => $wallpaper->kigaku(),
            'directionLabel' => KigakuPolicy::GROUPS[KigakuPolicy::group($wallpaper->kigaku())],
            'localImageAvailable' => $localImageAvailable,
            'downloadAvailable' => $localImageAvailable
                || ($wallpaper->notion_page_id !== null && $notion->isConfigured()),
            'downloadUnavailableReason' => $downloadRequiresNotionConfiguration
                ? '画像はNotionバックアップに保管されています。ダウンロードするにはNOTION_TOKENの設定が必要です。'
                : null,
            'previousWallpaperId' => Wallpaper::query()
                ->where('target_date', '<', $wallpaper->target_date->toDateString())
                ->orderByDesc('target_date')
                ->value('id'),
            'nextWallpaperId' => Wallpaper::query()
                ->where('target_date', '>', $wallpaper->target_date->toDateString())
                ->orderBy('target_date')
                ->value('id'),
        ]);
    }

    public function index(WallpaperFilterRequest $request): Response
    {
        $filters = $request->validated();

        return Inertia::render('wallpapers/index', [
            'filters' => $filters,
            'directionLabels' => KigakuPolicy::GROUPS,
            'wallpapers' => Wallpaper::query()->filtered($filters)->with('chosenProposal')
                ->orderByDesc('target_date')->paginate(20)->withQueryString()
                ->through(fn (Wallpaper $wallpaper): array => [
                    ...$wallpaper->toArray(),
                    'direction_label' => KigakuPolicy::GROUPS[KigakuPolicy::group($wallpaper->kigaku())],
                ]),
        ]);
    }

    public function destroy(
        Wallpaper $wallpaper,
        WallpaperDeletionService $deletionService,
    ): RedirectResponse {
        try {
            $deletionService->delete($wallpaper);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'delete' => '履歴の削除に失敗しました。ストレージの状態を確認して再試行してください。',
            ]);
        }

        return to_route('wallpapers.index')
            ->with('status', '履歴を削除しました。');
    }

    public function destroyImage(
        Wallpaper $wallpaper,
        WallpaperDeletionService $deletionService,
    ): RedirectResponse {
        try {
            $deleted = $deletionService->deleteImage($wallpaper);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'deleteImage' => '画像ファイルの削除に失敗しました。ストレージの状態を確認して再試行してください。',
            ]);
        }

        return back()->with(
            'status',
            $deleted
                ? '画像ファイルを削除しました。履歴データは保持されています。'
                : '画像ファイルは既に存在しません。履歴データは保持されています。',
        );
    }

    public function restoreImage(
        Wallpaper $wallpaper,
        WallpaperImageRestoreService $restoreService,
    ): RedirectResponse {
        try {
            $restored = $restoreService->restore($wallpaper);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'restoreImage' => 'Notionから画像ファイルを復元できませんでした。Notionバックアップと接続設定を確認して再試行してください。',
            ]);
        }

        return back()->with(
            'status',
            $restored
                ? 'Notionから画像ファイルを復元しました。'
                : '画像ファイルは既にサーバーに存在します。',
        );
    }

    public function download(
        Wallpaper $wallpaper,
        NotionClient $notion,
        ImageService $images,
    ): StreamedResponse|HttpResponse {
        if ($this->hasLocalImage($wallpaper)) {
            return Storage::disk((string) $wallpaper->image_disk)->download(
                (string) $wallpaper->image_path,
                $wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg',
                ['Content-Type' => 'image/jpeg'],
            );
        }

        return $this->notionImageResponse($wallpaper, $notion, $images, true);
    }

    public function preview(
        Wallpaper $wallpaper,
        NotionClient $notion,
        ImageService $images,
    ): StreamedResponse|HttpResponse {
        if ($this->hasLocalImage($wallpaper)) {
            return Storage::disk((string) $wallpaper->image_disk)->response(
                (string) $wallpaper->image_path,
                $wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg',
                [
                    'Content-Type' => $wallpaper->image_mime ?: 'image/jpeg',
                    'Cache-Control' => 'private, max-age=300',
                ],
                'inline',
            );
        }

        return $this->notionImageResponse($wallpaper, $notion, $images, false);
    }

    private function notionImageResponse(
        Wallpaper $wallpaper,
        NotionClient $notion,
        ImageService $images,
        bool $download,
    ): HttpResponse {
        abort_if($wallpaper->notion_page_id === null, 404);
        abort_unless(
            $notion->isConfigured(),
            503,
            'Notionバックアップを利用するにはNOTION_TOKENの設定が必要です。',
        );
        $page = $notion->getPage($wallpaper->notion_page_id);
        $url = $notion->wallpaperFileUrl($page);
        abort_if($url === null, 404);
        $response = Http::timeout(60)->withOptions(['stream' => true])->get($url);
        abort_unless($response->successful(), 502);

        try {
            $bytes = $images->transcodeToJpeg($images->readLimitedResponse($response));
        } catch (ExternalApiException) {
            abort(502, 'Notionの画像ファイルを検証できませんでした。');
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => ($download ? 'attachment' : 'inline')
                .'; filename="'.$wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function hasLocalImage(Wallpaper $wallpaper): bool
    {
        return $wallpaper->image_disk !== null
            && $wallpaper->image_path !== null
            && Storage::disk($wallpaper->image_disk)->exists($wallpaper->image_path);
    }
}
