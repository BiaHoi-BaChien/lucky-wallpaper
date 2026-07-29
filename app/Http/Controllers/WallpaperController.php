<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCompositionProposal;
use App\Jobs\GenerateWallpaperImage;
use App\Models\ApiRun;
use App\Models\CompositionProposal;
use App\Models\Wallpaper;
use App\Services\NotionClient;
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

    public function storeProposal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'target_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $result = DB::transaction(function () use ($validated): array {
            $existing = Wallpaper::query()
                ->where('target_date', $validated['target_date'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return [$existing, null];
            }

            $wallpaper = Wallpaper::query()->create([
                'target_date' => $validated['target_date'],
                'source' => 'generated',
                'state' => 'draft',
            ]);
            $inputHash = hash('sha256', $validated['target_date'].'|'.config('lucky.openai.prompt_version'));
            $run = $this->createApiRun($wallpaper, 'composition_proposal', $inputHash);

            return [$wallpaper, $run];
        });

        [$wallpaper, $run] = $result;
        if ($run instanceof ApiRun) {
            GenerateCompositionProposal::dispatch($wallpaper->id, $run->id);
        }

        return to_route('wallpapers.show', ['wallpaper' => $wallpaper, 'operation' => $run?->id]);
    }

    public function show(Wallpaper $wallpaper): Response
    {
        return Inertia::render('wallpapers/show', [
            'wallpaper' => $wallpaper->load(['proposals' => fn ($query) => $query->orderByDesc('sequence')]),
            'latestApiRun' => ApiRun::query()
                ->where('subject_type', $wallpaper->getMorphClass())
                ->where('subject_id', $wallpaper->id)
                ->whereIn('type', ['composition_proposal', 'image_generation'])
                ->latest()
                ->first(),
        ]);
    }

    public function repropose(Wallpaper $wallpaper): RedirectResponse
    {
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

    public function download(Wallpaper $wallpaper): StreamedResponse|HttpResponse
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
        $page = app(NotionClient::class)->getPage($wallpaper->notion_page_id);
        $url = app(NotionClient::class)->wallpaperFileUrl($page);
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
