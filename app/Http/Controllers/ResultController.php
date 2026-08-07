<?php

namespace App\Http\Controllers;

use App\Jobs\SyncWallpaperResultToNotion;
use App\Models\AnalysisSnapshot;
use App\Models\SyncRun;
use App\Models\Wallpaper;
use App\Services\NotionClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ResultController extends Controller
{
    public function index(Request $request, NotionClient $notion): Response
    {
        $defaultDate = CarbonImmutable::now(config('lucky.timezone'))->toDateString();
        $date = $request->string('date')->toString();
        $wallpaper = $date === '' ? null : Wallpaper::query()->where('target_date', $date)->first();
        $localImageAvailable = $wallpaper?->image_disk !== null
            && $wallpaper->image_path !== null
            && Storage::disk($wallpaper->image_disk)->exists($wallpaper->image_path);

        return Inertia::render('results/index', [
            'defaultDate' => $defaultDate,
            'selectedDate' => $date,
            'wallpaper' => $wallpaper,
            'imageAvailable' => $wallpaper !== null && ($localImageAvailable
                || ($wallpaper->notion_page_id !== null && $notion->isConfigured())),
            'latestRun' => $date === '' || ! $notion->isConfigured() ? null : SyncRun::query()
                ->where('type', 'notion_result')
                ->where('wallpaper_id', $wallpaper?->id)
                ->latest()
                ->first(),
        ]);
    }

    public function update(
        Request $request,
        Wallpaper $wallpaper,
        NotionClient $notion,
    ): RedirectResponse {
        $validated = $request->validate([
            'prize_vnd' => ['required', 'integer', 'min:0', 'digits_between:1,15'],
        ]);

        $wallpaper->update(['prize_vnd' => (int) $validated['prize_vnd']]);
        if ($wallpaper->wasChanged('prize_vnd')) {
            AnalysisSnapshot::query()->where('status', 'succeeded')->update(['status' => 'invalidated']);
        }
        if (! $notion->isConfigured()) {
            return back()->with('status', '実績をサーバーに保存しました。Notionバックアップは未設定のため実行していません。');
        }

        $run = SyncRun::query()->create([
            'type' => 'notion_result',
            'wallpaper_id' => $wallpaper->id,
            'total' => 1,
        ]);
        SyncWallpaperResultToNotion::dispatch($wallpaper->id, $run->id);

        return back()->with('operationId', $run->id);
    }
}
