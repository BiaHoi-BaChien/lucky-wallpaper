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
        $downloadRequiresNotionConfiguration = $wallpaper->image_path === null
            && $wallpaper->notion_page_id !== null
            && ! $notion->isConfigured();

        return Inertia::render('wallpapers/show', [
            'wallpaper' => $wallpaper->load(['proposals' => fn ($query) => $query->orderByDesc('sequence')]),
            'downloadAvailable' => $wallpaper->image_path !== null
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
            'proposal_id' => ['required', 'integer'],
        ]);
        if ($wallpaper->image_path !== null) {
            return back();
        }

        $proposal = CompositionProposal::query()
            ->where('wallpaper_id', $wallpaper->id)
            ->whereKey($validated['proposal_id'])
            ->firstOrFail();
        $wallpaper->proposals()->where('status', 'proposed')->whereKeyNot($proposal->id)->update(['status' => 'rejected']);
        $proposal->update(['status' => 'approved']);

        $inputHash = hash('sha256', $proposal->input_hash.'|image');
        $run = $this->createApiRun($wallpaper, 'image_generation', $inputHash, config('lucky.openai.image_model'));
        GenerateWallpaperImage::dispatch($wallpaper->id, $proposal->id, $run->id);

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

    public function download(Wallpaper $wallpaper, NotionClient $notion): StreamedResponse|HttpResponse
    {
        if ($wallpaper->image_path !== null) {
            abort_unless(Storage::disk((string) $wallpaper->image_disk)->exists($wallpaper->image_path), 404);

            return Storage::download(
                $wallpaper->image_path,
                $wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg',
                ['Content-Type' => 'image/jpeg'],
            );
        }

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
            'Content-Disposition' => 'attachment; filename="'.$wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg"',
            'Cache-Control' => 'private, no-store',
        ]);
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
