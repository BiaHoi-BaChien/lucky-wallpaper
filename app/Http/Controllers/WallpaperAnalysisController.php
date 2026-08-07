<?php

namespace App\Http\Controllers;

use App\Exceptions\ExternalApiException;
use App\Jobs\GenerateHistoricalAnalysis;
use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Services\HistoricalAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WallpaperAnalysisController extends Controller
{
    public function prompt(HistoricalAnalysisService $analysisService): JsonResponse
    {
        return response()->json($analysisService->manualPrompt());
    }

    public function data(
        Request $request,
        HistoricalAnalysisService $analysisService,
    ): Response {
        $validated = $request->validate([
            'prompt_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $data = $analysisService->manualData($validated['prompt_date']);

        return response($data['content'], 200, [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'attachment; filename="'.$data['filename'].'"',
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeManual(
        Request $request,
        HistoricalAnalysisService $analysisService,
    ): RedirectResponse {
        $validated = $request->validate([
            'analysis_markdown' => ['nullable', 'string', 'max:1000000'],
            'prompt_hash' => ['required', 'string', 'size:64'],
            'prompt_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $active = ApiRun::query()
            ->where('type', 'historical_analysis')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($active) {
            throw ValidationException::withMessages([
                'analysis_markdown' => 'APIによる傾向分析が実行中です。完了後に保存してください。',
            ]);
        }

        try {
            $analysisService->saveManualResult(
                (string) ($validated['analysis_markdown'] ?? ''),
                $validated['prompt_hash'],
                $validated['prompt_date'],
            );
        } catch (ExternalApiException $exception) {
            if ($exception->errorCode === 'historical_analysis_stale_input') {
                throw ValidationException::withMessages([
                    'analysis_markdown' => 'プロンプトが更新されています。プロンプトを再作成してください。',
                ]);
            }

            throw $exception;
        }

        return back()->with('status', 'ChatGPTの傾向分析を保存しました。');
    }

    public function store(
        Request $request,
        HistoricalAnalysisService $analysisService,
    ): RedirectResponse {
        $request->validate(['api_confirmed' => ['accepted']]);
        $dataHash = $analysisService->currentDataHash();
        $promptVersion = (string) config('lucky.openai.prompt_version');

        [$snapshot, $run, $preserveExistingResult] = DB::transaction(function () use ($dataHash, $promptVersion): array {
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

            $active = ApiRun::query()
                ->where('type', 'historical_analysis')
                ->whereIn('status', ['queued', 'running'])
                ->exists();
            if ($active) {
                throw ValidationException::withMessages([
                    'analysis' => '傾向分析は既に実行中です。',
                ]);
            }

            $preserveExistingResult = $snapshot->status === 'succeeded' && $snapshot->summary !== '';
            if (! $preserveExistingResult) {
                $snapshot->update([
                    'model' => config('lucky.openai.text_model'),
                    'summary' => '',
                    'statistics' => null,
                    'status' => 'queued',
                ]);
            }
            $run = ApiRun::query()->create([
                'type' => 'historical_analysis',
                'model' => config('lucky.openai.text_model'),
                'prompt_version' => $promptVersion,
                'input_hash' => $dataHash,
                'subject_type' => $snapshot->getMorphClass(),
                'subject_id' => $snapshot->getKey(),
            ]);

            return [$snapshot, $run, $preserveExistingResult];
        });

        GenerateHistoricalAnalysis::dispatch($snapshot->id, $run->id, $preserveExistingResult);

        return back()->with('operationId', $run->id);
    }
}
