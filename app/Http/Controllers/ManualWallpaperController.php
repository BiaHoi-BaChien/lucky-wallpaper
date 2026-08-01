<?php

namespace App\Http\Controllers;

use App\Exceptions\ExternalApiException;
use App\Models\ApiRun;
use App\Models\CompositionProposal;
use App\Models\Wallpaper;
use App\Services\ImageService;
use App\Services\WallpaperPromptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManualWallpaperController extends Controller
{
    public function proposalPrompt(Request $request, WallpaperPromptService $prompts): JsonResponse
    {
        $validated = $request->validate([
            'target_date' => ['required', 'date_format:Y-m-d'],
        ]);
        if (Wallpaper::query()->where('target_date', $validated['target_date'])->exists()) {
            throw ValidationException::withMessages([
                'target_date' => 'この日付の壁紙は登録済みです。',
            ]);
        }

        return response()->json(
            $this->promptResponse($this->prepareComposition($prompts, $validated['target_date'])),
        );
    }

    public function storeProposal(
        Request $request,
        WallpaperPromptService $prompts,
    ): RedirectResponse {
        $validated = $request->validate([
            'target_date' => ['required', 'date_format:Y-m-d'],
            'proposal_json' => ['required', 'string', 'max:2000000'],
            'prompt_hash' => ['required', 'string', 'size:64'],
        ]);
        $this->rejectActiveRun('composition_proposal');
        $prepared = $this->prepareComposition($prompts, $validated['target_date']);
        $this->assertPromptHash($prepared['prompt_hash'], $validated['prompt_hash'], 'proposal_json');
        $result = $prompts->parseProposal($validated['proposal_json']);

        $wallpaper = DB::transaction(function () use ($validated, $prepared, $result, $prompts): Wallpaper {
            $existing = Wallpaper::query()
                ->where('target_date', $validated['target_date'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'target_date' => 'この日付の壁紙は登録済みです。',
                ]);
            }

            $wallpaper = Wallpaper::query()->create([
                'target_date' => $validated['target_date'],
                'source' => 'generated',
                'state' => 'draft',
            ]);
            $prompts->saveProposal($wallpaper, $result, $prepared['input_hash'], false);

            return $wallpaper;
        });

        return to_route('wallpapers.show', ['wallpaper' => $wallpaper])
            ->with('status', 'ChatGPTの構図提案を保存しました。');
    }

    public function reproposalPrompt(
        Wallpaper $wallpaper,
        WallpaperPromptService $prompts,
    ): JsonResponse {
        $this->rejectActiveRun('composition_proposal', $wallpaper);
        $reproposal = $wallpaper->proposals()->exists();

        return response()->json($this->promptResponse(
            $this->prepareComposition(
                $prompts,
                $wallpaper->target_date->format('Y-m-d'),
                $wallpaper,
                $reproposal,
            ),
        ));
    }

    public function storeReproposal(
        Request $request,
        Wallpaper $wallpaper,
        WallpaperPromptService $prompts,
    ): RedirectResponse {
        $validated = $request->validate([
            'proposal_json' => ['required', 'string', 'max:2000000'],
            'prompt_hash' => ['required', 'string', 'size:64'],
        ]);
        $this->rejectActiveRun('composition_proposal', $wallpaper);
        $reproposal = $wallpaper->proposals()->exists();
        $prepared = $this->prepareComposition(
            $prompts,
            $wallpaper->target_date->format('Y-m-d'),
            $wallpaper,
            $reproposal,
        );
        $this->assertPromptHash($prepared['prompt_hash'], $validated['prompt_hash'], 'proposal_json');
        $result = $prompts->parseProposal($validated['proposal_json']);
        $prompts->saveProposal($wallpaper, $result, $prepared['input_hash'], $reproposal);

        return back()->with('status', 'ChatGPTの構図提案を保存しました。');
    }

    public function imagePrompt(
        Request $request,
        Wallpaper $wallpaper,
        WallpaperPromptService $prompts,
    ): JsonResponse {
        $validated = $request->validate([
            'proposal_id' => ['nullable', 'integer'],
        ]);
        $this->rejectActiveRun('image_generation', $wallpaper);
        $proposal = $this->proposal($wallpaper, $validated['proposal_id'] ?? null);
        if ($proposal === null && ! $this->hasCompositionDetails($wallpaper)) {
            throw ValidationException::withMessages([
                'image' => '画像作成に必要な構図の詳細がありません。',
            ]);
        }

        return response()->json($this->promptResponse($prompts->image($wallpaper, $proposal)));
    }

    public function storeImage(
        Request $request,
        Wallpaper $wallpaper,
        WallpaperPromptService $prompts,
        ImageService $images,
    ): RedirectResponse {
        $validated = $request->validate([
            'proposal_id' => ['nullable', 'integer'],
            'prompt_hash' => ['nullable', 'string', 'size:64'],
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        ]);
        $this->rejectActiveRun('image_generation', $wallpaper);
        if ($this->hasLocalImage($wallpaper)) {
            throw ValidationException::withMessages(['image' => '画像は既に保存されています。']);
        }

        $proposal = $this->proposal($wallpaper, $validated['proposal_id'] ?? null);
        if ($proposal === null && ! $this->hasCompositionDetails($wallpaper)) {
            throw ValidationException::withMessages([
                'image' => '画像作成に必要な構図の詳細がありません。',
            ]);
        }
        $promptHash = $validated['prompt_hash'] ?? null;
        if ($promptHash !== null) {
            $prepared = $prompts->image($wallpaper, $proposal);
            $this->assertPromptHash($prepared['prompt_hash'], $promptHash, 'image');
        }

        $bytes = $request->file('image')?->get();
        if (! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages(['image' => '画像ファイルを読み取れませんでした。']);
        }

        try {
            $stored = $images->normalizeAndStore($bytes);
        } catch (ExternalApiException $exception) {
            throw ValidationException::withMessages([
                'image' => $exception->errorCode === 'invalid_generated_image'
                    ? '対応している画像ファイルを選択してください。'
                    : '画像を保存できませんでした。',
            ]);
        }

        try {
            DB::transaction(function () use ($wallpaper, $validated, $stored, $prompts, $promptHash): void {
                $wallpaper = Wallpaper::query()->lockForUpdate()->findOrFail($wallpaper->id);
                if ($this->hasLocalImage($wallpaper)) {
                    throw ValidationException::withMessages(['image' => '画像は既に保存されています。']);
                }
                $currentProposal = $this->proposal($wallpaper, $validated['proposal_id'] ?? null);
                if ($promptHash !== null) {
                    $currentPrompt = $prompts->image($wallpaper, $currentProposal);
                    $this->assertPromptHash($currentPrompt['prompt_hash'], $promptHash, 'image');
                }

                if ($currentProposal !== null) {
                    $wallpaper->proposals()
                        ->where('status', 'proposed')
                        ->whereKeyNot($currentProposal->id)
                        ->update(['status' => 'rejected']);
                    $currentProposal->update(['status' => 'approved']);
                }
                $wallpaper->update([
                    'chosen_proposal_id' => $currentProposal?->id,
                    'image_disk' => $stored['disk'],
                    'image_path' => $stored['path'],
                    'image_mime' => $stored['mime'],
                    'image_bytes' => $stored['bytes'],
                    'image_sha256' => $stored['sha256'],
                    'state' => 'generated',
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk($stored['disk'])->delete($stored['path']);
            throw $exception;
        }

        return back()->with('status', '画像を保存しました。');
    }

    private function prepareComposition(
        WallpaperPromptService $prompts,
        string $targetDate,
        ?Wallpaper $wallpaper = null,
        bool $reproposal = false,
    ): array {
        try {
            return $prompts->composition($targetDate, $wallpaper, $reproposal);
        } catch (ExternalApiException $exception) {
            if ($exception->errorCode === 'historical_analysis_required') {
                throw ValidationException::withMessages([
                    'proposal_json' => '最新の傾向分析を完了してから構図を提案してください。',
                ]);
            }

            throw $exception;
        }
    }

    private function rejectActiveRun(string $type, ?Wallpaper $wallpaper = null): void
    {
        $query = ApiRun::query()
            ->where('type', $type)
            ->whereIn('status', ['queued', 'running']);
        if ($wallpaper !== null) {
            $query
                ->where('subject_type', $wallpaper->getMorphClass())
                ->where('subject_id', $wallpaper->id);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                $type === 'image_generation' ? 'image' : 'proposal_json' => 'API処理が実行中です。完了後に操作してください。',
            ]);
        }
    }

    private function proposal(Wallpaper $wallpaper, mixed $proposalId): ?CompositionProposal
    {
        if ($proposalId === null) {
            return null;
        }

        return CompositionProposal::query()
            ->where('wallpaper_id', $wallpaper->id)
            ->whereKey($proposalId)
            ->firstOrFail();
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

    private function hasLocalImage(Wallpaper $wallpaper): bool
    {
        return $wallpaper->image_disk !== null
            && $wallpaper->image_path !== null
            && Storage::disk($wallpaper->image_disk)->exists($wallpaper->image_path);
    }

    private function assertPromptHash(string $expected, string $actual, string $field): void
    {
        if (! hash_equals($expected, $actual)) {
            throw ValidationException::withMessages([
                $field => '元データが更新されています。プロンプトを再作成してください。',
            ]);
        }
    }

    private function promptResponse(array $prepared): array
    {
        return [
            'prompt' => $prepared['prompt'],
            'prompt_hash' => $prepared['prompt_hash'],
            'context_hash' => $prepared['context_hash'],
            'filename' => $prepared['filename'],
        ];
    }
}
