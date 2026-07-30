<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateHistoricalAnalysis;
use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Services\HistoricalAnalysisService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WallpaperAnalysisController extends Controller
{
    public function store(HistoricalAnalysisService $analysisService): RedirectResponse
    {
        $dataHash = $analysisService->currentDataHash();
        $promptVersion = (string) config('lucky.openai.prompt_version');

        [$snapshot, $run] = DB::transaction(function () use ($dataHash, $promptVersion): array {
            $snapshot = AnalysisSnapshot::query()->firstOrCreate(
                [
                    'data_hash' => $dataHash,
                    'prompt_version' => $promptVersion,
                ],
                [
                    'model' => config('lucky.openai.text_model'),
                    'summary' => '',
                    'status' => 'queued',
                ],
            );

            if ($snapshot->status === 'succeeded') {
                return [$snapshot, null];
            }

            $active = ApiRun::query()
                ->where('type', 'historical_analysis')
                ->whereIn('status', ['queued', 'running'])
                ->exists();
            if ($active) {
                throw ValidationException::withMessages([
                    'analysis' => '傾向分析は既に実行中です。',
                ]);
            }

            $snapshot->update([
                'model' => config('lucky.openai.text_model'),
                'summary' => '',
                'statistics' => null,
                'status' => 'queued',
            ]);
            $run = ApiRun::query()->create([
                'type' => 'historical_analysis',
                'model' => config('lucky.openai.text_model'),
                'prompt_version' => $promptVersion,
                'input_hash' => $dataHash,
                'subject_type' => $snapshot->getMorphClass(),
                'subject_id' => $snapshot->getKey(),
            ]);

            return [$snapshot, $run];
        });

        if ($run instanceof ApiRun) {
            GenerateHistoricalAnalysis::dispatch($snapshot->id, $run->id);
        }

        return back()->with('operationId', $run?->id);
    }
}
