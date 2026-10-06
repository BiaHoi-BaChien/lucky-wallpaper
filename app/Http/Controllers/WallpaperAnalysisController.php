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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class WallpaperAnalysisController extends Controller
{
    public function index(HistoricalAnalysisService $analysisService): InertiaResponse
    {
        $analysis = $analysisService->latestDisplayableSnapshot();

        return Inertia::render('wallpaper-analyses/index', [
            'analysis' => $analysis === null ? null : [
                'id' => $analysis->id,
                'markdown' => $analysis->summary,
                'html' => Str::markdown($analysis->summary, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ]),
                'is_latest' => $analysisService->currentSnapshot()?->is($analysis) ?? false,
                'created_at' => $analysis->updated_at?->toIso8601String(),
                'statistics' => $analysisService->publicStatistics($analysis->statistics),
            ],
            'analysisPlan' => $analysisService->planOverview(),
            'latestAnalysisRun' => ApiRun::query()
                ->where('type', 'historical_analysis')
                ->latest()
                ->first(),
        ]);
    }

    public function prompt(Request $request, HistoricalAnalysisService $analysisService): JsonResponse
    {
        $this->validateOptions($request);

        return response()->json($analysisService->manualPrompt(
            fullConfirmed: $request->boolean('full_confirmed'),
            perspective: (string) $request->input('perspective', ''),
        ))->header('Cache-Control', 'private, no-store');
    }

    public function data(
        Request $request,
        HistoricalAnalysisService $analysisService,
    ): Response {
        $this->validateOptions($request);
        $validated = $request->validate([
            'prompt_date' => ['required', 'date_format:Y-m-d'],
            'prompt_hash' => ['nullable', 'string', 'size:64'],
        ]);

        $data = $analysisService->manualData(
            $validated['prompt_date'],
            $request->boolean('full_confirmed'),
            (string) $request->input('perspective', ''),
            $validated['prompt_hash'] ?? null,
        );

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
        $this->validateOptions($request);
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
                $request->boolean('full_confirmed'),
                (string) $request->input('perspective', ''),
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
        $request->validate(['reuse_only' => ['sometimes', 'boolean']]);
        $reuseOnly = $request->boolean('reuse_only');
        if (! $reuseOnly) {
            $request->validate(['api_confirmed' => ['accepted']]);
        }
        $this->validateOptions($request);
        $fullConfirmed = $request->boolean('full_confirmed');
        $perspective = (string) $request->input('perspective', '');
        $plan = $analysisService->plan($fullConfirmed, $perspective);
        if ($reuseOnly && $plan['mode'] !== 'unchanged') {
            throw ValidationException::withMessages([
                'analysis' => '前回の結果を再利用できません。最新の履歴を確認して分析してください。',
            ]);
        }
        $analysisService->assertExecutable($plan);
        if ($plan['mode'] === 'unchanged') {
            $plan['base']->update(['status' => 'succeeded']);

            return back()->with('status', '追加・変更されたデータはありません。前回の分析結果を利用します。');
        }
        $dataHash = $plan['dataHash'];
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

            $preserveExistingResult = $snapshot->summary !== '';
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

        GenerateHistoricalAnalysis::dispatch($snapshot->id, $run->id, $preserveExistingResult, $fullConfirmed, $perspective, $plan['token']);

        return back()->with('operationId', $run->id);
    }

    private function validateOptions(Request $request): void
    {
        $request->validate([
            'full_confirmed' => ['sometimes', 'boolean'],
            'perspective' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! $request->boolean('full_confirmed') && trim((string) $request->input('perspective', '')) !== '') {
            throw ValidationException::withMessages(['full_confirmed' => '新しい切り口での全件再分析には許可が必要です。']);
        }
    }
}
