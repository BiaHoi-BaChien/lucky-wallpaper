<?php

namespace App\Http\Controllers;

use App\Jobs\SyncWallpaperResultToNotion;
use App\Models\AnalysisSnapshot;
use App\Models\SyncRun;
use App\Models\Wallpaper;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ResultController extends Controller
{
    public function index(Request $request): Response
    {
        $defaultDate = CarbonImmutable::now(config('lucky.timezone'))->toDateString();
        $date = $request->string('date')->toString();

        return Inertia::render('results/index', [
            'defaultDate' => $defaultDate,
            'selectedDate' => $date,
            'wallpaper' => $date === '' ? null : Wallpaper::query()->where('target_date', $date)->first(),
            'latestRun' => $date === '' ? null : SyncRun::query()
                ->where('type', 'notion_result')
                ->where('wallpaper_id', Wallpaper::query()->where('target_date', $date)->value('id'))
                ->latest()
                ->first(),
            'latestImport' => SyncRun::query()->where('type', 'notion_import')->latest()->first(),
        ]);
    }

    public function update(Request $request, Wallpaper $wallpaper): RedirectResponse
    {
        $validated = $request->validate([
            'prize_vnd' => ['required', 'integer', 'min:0', 'digits_between:1,15'],
        ]);

        $wallpaper->update(['prize_vnd' => (int) $validated['prize_vnd']]);
        AnalysisSnapshot::query()->where('status', 'succeeded')->update(['status' => 'invalidated']);
        $run = SyncRun::query()->create([
            'type' => 'notion_result',
            'wallpaper_id' => $wallpaper->id,
            'total' => 1,
        ]);
        SyncWallpaperResultToNotion::dispatch($wallpaper->id, $run->id);

        return back()->with('operationId', $run->id);
    }
}
