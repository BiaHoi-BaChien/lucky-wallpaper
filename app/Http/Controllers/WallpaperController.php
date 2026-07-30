<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCompositionProposal;
use App\Jobs\GenerateWallpaperImage;
use App\Models\ApiRun;
use App\Models\CompositionProposal;
use App\Models\Wallpaper;
use App\Services\HistoricalAnalysisService;
use App\Services\NotionClient;
use App\Services\WallpaperDeletionService;
use App\Services\WallpaperImageRestoreService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class WallpaperController extends Controller
{
    public function create(Request $request, HistoricalAnalysisService $analysisService): Response
    {
        $defaultDate = CarbonImmutable::now(config('lucky.timezone'))->addDay()->toDateString();
        $selectedDate = $request->string('date')->toString() ?: $defaultDate;
        $analysis = $analysisService->latestDisplayableSnapshot();
        $currentDataHash = $analysisService->currentDataHash();
        $analysisIsLatest = $analysis !== null
            && $analysis->status === 'succeeded'
            && hash_equals($currentDataHash, $analysis->data_hash)
            && $analysis->prompt_version === (string) config('lucky.openai.prompt_version');

        return Inertia::render('wallpapers/create', [
            'defaultDate' => $defaultDate,
            'selectedDate' => $selectedDate,
            'existing' => Wallpaper::query()->where('target_date', $selectedDate)->first(),
            'analysis' => $analysis === null ? null : [
                'id' => $analysis->id,
                'markdown' => $analysis->summary,
                'is_latest' => $analysisIsLatest,
                'created_at' => $analysis->updated_at?->toIso8601String(),
                'statistics' => $analysis->statistics,
            ],
            'latestAnalysisRun' => ApiRun::query()
                ->where('type', 'historical_analysis')
                ->latest()
                ->first(),
        ]);
    }

    public function storeProposal(
        Request $request,
        HistoricalAnalysisService $analysisService,
    ): RedirectResponse {
        $validated = $request->validate([
            'target_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $result = DB::transaction(function () use ($validated, $analysisService): array {
            $existing = Wallpaper::query()
                ->where('target_date', $validated['target_date'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return [$existing, null];
            }

            $snapshot = $analysisService->currentSnapshot();
            if ($snapshot === null) {
                throw ValidationException::withMessages([
                    'proposal' => '最新の傾向分析を実行してから構図を提案してください。',
                ]);
            }

            $wallpaper = Wallpaper::query()->create([
                'target_date' => $validated['target_date'],
                'source' => 'generated',
                'state' => 'draft',
            ]);
            $inputHash = hash(
                'sha256',
                $validated['target_date'].'|'.config('lucky.openai.prompt_version').'|'.$snapshot->data_hash,
            );
            $run = $this->createApiRun($wallpaper, 'composition_proposal', $inputHash);

            return [$wallpaper, $run];
        });

        [$wallpaper, $run] = $result;
        if ($run instanceof ApiRun) {
            GenerateCompositionProposal::dispatch($wallpaper->id, $run->id);
        }

        return to_route('wallpapers.show', ['wallpaper' => $wallpaper, 'operation' => $run?->id]);
    }

    public function show(Wallpaper $wallpaper, NotionClient $notion): Response
    {
        $localImageAvailable = $this->hasLocalImage($wallpaper);
        $downloadRequiresNotionConfiguration = ! $localImageAvailable
            && $wallpaper->notion_page_id !== null
            && ! $notion->isConfigured();

        return Inertia::render('wallpapers/show', [
            'wallpaper' => $wallpaper->load(['proposals' => fn ($query) => $query->orderByDesc('sequence')]),
            'localImageAvailable' => $localImageAvailable,
            'downloadAvailable' => $localImageAvailable
                || ($wallpaper->notion_page_id !== null && $notion->isConfigured()),
            'downloadUnavailableReason' => $downloadRequiresNotionConfiguration
                ? '画像はNotionバックアップに保管されています。ダウンロードするにはNOTION_TOKENの設定が必要です。'
                : null,
            'latestApiRun' => ApiRun::query()
                ->where('subject_type', $wallpaper->getMorphClass())
                ->where('subject_id', $wallpaper->id)
                ->whereIn('type', ['composition_proposal', 'image_generation'])
                ->latest()
                ->first(),
        ]);
    }

    public function repropose(
        Wallpaper $wallpaper,
        HistoricalAnalysisService $analysisService,
    ): RedirectResponse {
        if ($analysisService->currentSnapshot() === null) {
            throw ValidationException::withMessages([
                'proposal' => '壁紙履歴が更新されています。最新の傾向分析を実行してから再提案してください。',
            ]);
        }

        $active = ApiRun::query()
            ->where('subject_type', $wallpaper->getMorphClass())
            ->where('subject_id', $wallpaper->id)
            ->where('type', 'composition_proposal')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($active) {
            throw ValidationException::withMessages(['proposal' => '構図提案は既に実行中です。']);
        }

        $inputHash = hash('sha256', $wallpaper->id.'|reproposal|'.$wallpaper->proposals()->count());
        $run = $this->createApiRun($wallpaper, 'composition_proposal', $inputHash);
        GenerateCompositionProposal::dispatch($wallpaper->id, $run->id, true);

        return back()->with('operationId', $run->id);
    }

    public function image(Request $request, Wallpaper $wallpaper): RedirectResponse
    {
        $validated = $request->validate([
            'proposal_id' => ['nullable', 'integer'],
        ]);
        if ($this->hasLocalImage($wallpaper)) {
            return back();
        }

        $active = ApiRun::query()
            ->where('subject_type', $wallpaper->getMorphClass())
            ->where('subject_id', $wallpaper->id)
            ->where('type', 'image_generation')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($active) {
            throw ValidationException::withMessages(['image' => '画像生成は既に実行中です。']);
        }

        $proposal = isset($validated['proposal_id'])
            ? CompositionProposal::query()
                ->where('wallpaper_id', $wallpaper->id)
                ->whereKey($validated['proposal_id'])
                ->firstOrFail()
            : null;
        if ($proposal === null && ! $this->hasCompositionDetails($wallpaper)) {
            throw ValidationException::withMessages([
                'image' => '画像生成に必要な構図の詳細がありません。',
            ]);
        }

        if ($proposal !== null) {
            $wallpaper->proposals()
                ->where('status', 'proposed')
                ->whereKeyNot($proposal->id)
                ->update(['status' => 'rejected']);
            $proposal->update(['status' => 'approved']);
        }

        $inputHash = $proposal !== null
            ? hash('sha256', $proposal->input_hash.'|image')
            : hash('sha256', $wallpaper->compositionDetails().'|image');
        $run = $this->createApiRun($wallpaper, 'image_generation', $inputHash, config('lucky.openai.image_model'));
        GenerateWallpaperImage::dispatch($wallpaper->id, $proposal?->id, $run->id);

        return back()->with('operationId', $run->id);
    }

    public function index(): Response
    {
        return Inertia::render('wallpapers/index', [
            'wallpapers' => Wallpaper::query()->orderByDesc('target_date')->paginate(20),
        ]);
    }

    public function destroy(
        Wallpaper $wallpaper,
        WallpaperDeletionService $deletionService,
        NotionClient $notion,
    ): RedirectResponse {
        try {
            $deletionService->delete($wallpaper);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'delete' => $notion->isConfigured()
                    ? '履歴の削除に失敗しました。Notionバックアップまたはストレージの接続を確認して再試行してください。'
                    : '履歴の削除に失敗しました。ストレージの状態を確認して再試行してください。',
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

    public function download(Wallpaper $wallpaper, NotionClient $notion): StreamedResponse|HttpResponse
    {
        if ($this->hasLocalImage($wallpaper)) {
            return Storage::disk((string) $wallpaper->image_disk)->download(
                (string) $wallpaper->image_path,
                $wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg',
                ['Content-Type' => 'image/jpeg'],
            );
        }

        return $this->notionImageResponse($wallpaper, $notion, true);
    }

    public function preview(Wallpaper $wallpaper, NotionClient $notion): StreamedResponse|HttpResponse
    {
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

        return $this->notionImageResponse($wallpaper, $notion, false);
    }

    private function notionImageResponse(
        Wallpaper $wallpaper,
        NotionClient $notion,
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
        $response = Http::timeout(60)->get($url);
        abort_unless($response->successful(), 502);

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'image/jpeg',
            'Content-Disposition' => ($download ? 'attachment' : 'inline')
                .'; filename="'.$wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function hasLocalImage(Wallpaper $wallpaper): bool
    {
        return $wallpaper->image_disk !== null
            && $wallpaper->image_path !== null
            && Storage::disk($wallpaper->image_disk)->exists($wallpaper->image_path);
    }

    private function hasCompositionDetails(Wallpaper $wallpaper): bool
    {
        return collect([
            $wallpaper->title,
            $wallpaper->conclusion,
            $wallpaper->overview,
            $wallpaper->composition,
            $wallpaper->color_wu_xing,
            $wallpaper->symbolism,
        ])->contains(fn (?string $value): bool => $value !== null && trim($value) !== '');
    }

    private function createApiRun(
        Wallpaper $wallpaper,
        string $type,
        string $inputHash,
        ?string $model = null,
    ): ApiRun {
        return ApiRun::query()->create([
            'type' => $type,
            'model' => $model ?? config('lucky.openai.text_model'),
            'prompt_version' => config('lucky.openai.prompt_version'),
            'input_hash' => $inputHash,
            'subject_type' => $wallpaper->getMorphClass(),
            'subject_id' => $wallpaper->getKey(),
        ]);
    }
}
