<?php

namespace App\Http\Controllers;

use App\Exceptions\ExternalApiException;
use App\Models\CompositionProposal;
use App\Models\SyncRun;
use App\Models\Wallpaper;
use App\Services\ImageService;
use App\Services\KigakuPolicy;
use App\Services\WallpaperPromptService;
use Illuminate\Database\UniqueConstraintViolationException;
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
        $validated = $request->validate(['target_date' => ['required', 'date_format:Y-m-d']]);
        if (Wallpaper::query()->where('target_date', $validated['target_date'])->exists()) {
            throw ValidationException::withMessages(['target_date' => 'この日付の壁紙は登録済みです。']);
        }

        return response()->json($this->promptResponse($prompts->composition($validated['target_date'])));
    }

    public function storeProposal(Request $request, WallpaperPromptService $prompts, ImageService $images): RedirectResponse
    {
        return $this->storeComposition($request, $prompts, $images);
    }

    public function reproposalPrompt(Wallpaper $wallpaper, WallpaperPromptService $prompts): JsonResponse
    {
        $this->assertCanChange($wallpaper);

        return response()->json($this->promptResponse($prompts->composition($wallpaper->target_date->format('Y-m-d'), $wallpaper)));
    }

    public function storeReproposal(Request $request, Wallpaper $wallpaper, WallpaperPromptService $prompts, ImageService $images): RedirectResponse
    {
        return $this->storeComposition($request, $prompts, $images, $wallpaper);
    }

    private function storeComposition(Request $request, WallpaperPromptService $prompts, ImageService $images, ?Wallpaper $wallpaper = null): RedirectResponse
    {
        $validated = $request->validate([
            ...($wallpaper === null ? ['target_date' => ['required', 'date_format:Y-m-d']] : []),
            'proposal_json' => ['required', 'string', 'max:2000000'],
            'prompt_hash' => [$wallpaper === null ? 'nullable' : 'required', 'string', 'size:64'],
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        ]);
        $targetDate = $wallpaper?->target_date->format('Y-m-d') ?? $validated['target_date'];
        if ($wallpaper !== null) {
            $this->assertCanChange($wallpaper);
        }
        $prepared = $prompts->composition($targetDate, $wallpaper);
        $promptHash = $validated['prompt_hash'] ?? $prepared['prompt_hash'];
        $this->assertPromptHash($prepared['prompt_hash'], $promptHash, 'proposal_json');
        $result = $prompts->parseProposal($validated['proposal_json'], $targetDate);
        $stored = $this->storeUploadedImage($request, $images);

        try {
            $wallpaper = DB::transaction(function () use ($wallpaper, $targetDate, $promptHash, $result, $prompts, $stored): Wallpaper {
                $reproposal = $wallpaper !== null;
                if ($wallpaper === null) {
                    if (Wallpaper::query()->where('target_date', $targetDate)->lockForUpdate()->exists()) {
                        throw ValidationException::withMessages(['target_date' => 'この日付の壁紙は登録済みです。']);
                    }
                    $wallpaper = Wallpaper::query()->create(['target_date' => $targetDate, 'source' => 'generated', 'state' => 'draft']);
                } else {
                    $wallpaper = Wallpaper::query()->lockForUpdate()->findOrFail($wallpaper->id);
                    $this->assertCanChange($wallpaper);
                }
                $prepared = $prompts->composition($targetDate, $reproposal ? $wallpaper : null);
                $this->assertPromptHash($prepared['prompt_hash'], $promptHash, 'proposal_json');
                $proposal = $prompts->saveProposal($wallpaper, $result, $prepared['input_hash'], $reproposal);
                $wallpaper->update(['chosen_proposal_id' => $proposal->id, ...$this->imageAttributes($stored)]);

                return $wallpaper;
            });
        } catch (Throwable $exception) {
            Storage::disk($stored['disk'])->delete($stored['path']);
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['target_date' => 'この日付の壁紙は登録済みです。']);
            }
            if ($exception instanceof ExternalApiException && $exception->errorCode === 'composition_prompt_stale') {
                throw ValidationException::withMessages(['proposal_json' => '元データが更新されています。プロンプトを再作成してください。']);
            }
            throw $exception;
        }

        return to_route('wallpapers.show', ['wallpaper' => $wallpaper])->with('status', '構図の説明と壁紙画像を登録しました。');
    }

    public function imagePrompt(Request $request, Wallpaper $wallpaper, WallpaperPromptService $prompts): JsonResponse
    {
        $validated = $request->validate(['proposal_id' => ['nullable', 'integer']]);
        $proposal = $this->proposal($wallpaper, $validated['proposal_id'] ?? null);
        $this->assertHasComposition($wallpaper, $proposal);

        return response()->json($this->promptResponse($prompts->image($wallpaper, $proposal)));
    }

    public function storeImage(Request $request, Wallpaper $wallpaper, WallpaperPromptService $prompts, ImageService $images): RedirectResponse
    {
        $validated = $request->validate([
            'proposal_id' => ['nullable', 'integer'],
            'prompt_hash' => ['nullable', 'string', 'size:64'],
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        ]);
        $this->assertCanChange($wallpaper);
        $proposal = $this->proposal($wallpaper, $validated['proposal_id'] ?? null);
        $this->assertHasComposition($wallpaper, $proposal);
        $promptHash = $validated['prompt_hash'] ?? null;
        if ($promptHash !== null) {
            $this->assertPromptHash($prompts->image($wallpaper, $proposal)['prompt_hash'], $promptHash, 'image');
        }
        $stored = $this->storeUploadedImage($request, $images);
        try {
            DB::transaction(function () use ($wallpaper, $validated, $stored, $prompts, $promptHash): void {
                $wallpaper = Wallpaper::query()->lockForUpdate()->findOrFail($wallpaper->id);
                $this->assertCanChange($wallpaper);
                $proposal = $this->proposal($wallpaper, $validated['proposal_id'] ?? null);
                $this->assertHasComposition($wallpaper, $proposal);
                if ($promptHash !== null) {
                    $this->assertPromptHash($prompts->image($wallpaper, $proposal)['prompt_hash'], $promptHash, 'image');
                }
                $details = [];
                if ($proposal !== null) {
                    $wallpaper->proposals()->where('status', 'proposed')->whereKeyNot($proposal->id)->update(['status' => 'rejected']);
                    $proposal->update(['status' => 'approved']);
                    $details = $proposal->only([...KigakuPolicy::COMPOSITION_FIELDS, 'composition_zone']);
                }
                $wallpaper->update([
                    ...$details,
                    'chosen_proposal_id' => $proposal->id ?? $wallpaper->chosen_proposal_id,
                    ...$this->imageAttributes($stored),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk($stored['disk'])->delete($stored['path']);
            throw $exception;
        }

        return back()->with('status', '画像を保存しました。');
    }

    private function imageAttributes(array $stored): array
    {
        return [
            'image_disk' => $stored['disk'], 'image_path' => $stored['path'],
            'image_mime' => $stored['mime'], 'image_bytes' => $stored['bytes'],
            'image_sha256' => $stored['sha256'], 'state' => 'generated',
        ];
    }

    private function storeUploadedImage(Request $request, ImageService $images): array
    {
        $bytes = $request->file('image')?->get();
        if (! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages(['image' => '画像ファイルを読み取れませんでした。']);
        }
        try {
            return $images->normalizeAndStore($bytes);
        } catch (ExternalApiException $exception) {
            throw ValidationException::withMessages([
                'image' => $exception->errorCode === 'invalid_generated_image'
                    ? '対応している画像ファイルを選択してください。' : '画像を保存できませんでした。',
            ]);
        }
    }

    private function assertCanChange(Wallpaper $wallpaper): void
    {
        if ($wallpaper->image_disk !== null && $wallpaper->image_path !== null
            && Storage::disk($wallpaper->image_disk)->exists($wallpaper->image_path)) {
            throw ValidationException::withMessages(['image' => '画像は既に保存されています。']);
        }
        if (SyncRun::query()->where('wallpaper_id', $wallpaper->id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['image' => 'Notionの処理中です。完了後に操作してください。']);
        }
    }

    private function proposal(Wallpaper $wallpaper, mixed $proposalId): ?CompositionProposal
    {
        if ($proposalId === null) {
            return null;
        }

        return $wallpaper->proposals()->whereKey($proposalId)->whereIn('status', ['approved', 'proposed'])->firstOrFail();
    }

    private function assertHasComposition(Wallpaper $wallpaper, ?CompositionProposal $proposal): void
    {
        if ($proposal === null && ! collect($wallpaper->only(KigakuPolicy::COMPOSITION_FIELDS))
            ->contains(fn (?string $value): bool => $value !== null && trim($value) !== '')) {
            throw ValidationException::withMessages(['image' => '画像作成に必要な構図の詳細がありません。']);
        }
    }

    private function assertPromptHash(string $expected, string $actual, string $field): void
    {
        if (! hash_equals($expected, $actual)) {
            throw ValidationException::withMessages([$field => '元データが更新されています。プロンプトを再作成してください。']);
        }
    }

    private function promptResponse(array $prepared): array
    {
        return array_intersect_key($prepared, array_flip(['prompt', 'prompt_hash', 'context_hash', 'filename']));
    }
}
