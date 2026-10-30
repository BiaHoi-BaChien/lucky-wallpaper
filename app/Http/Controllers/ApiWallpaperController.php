<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateWallpaper;
use App\Models\ApiRun;
use App\Models\Wallpaper;
use App\Services\KigakuPolicy;
use App\Services\OpenAiClient;
use App\Services\WallpaperPromptService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApiWallpaperController extends Controller
{
    public function store(Request $request, OpenAiClient $openai, WallpaperPromptService $prompts): RedirectResponse
    {
        $validated = $request->validate(['target_date' => ['required', 'date_format:Y-m-d']]);
        $date = $validated['target_date'];
        if (! $openai->isConfigured()) {
            throw ValidationException::withMessages(['api_generation' => 'API連携が未設定です。サーバーのOpenAI APIキーを設定してください。']);
        }
        if ((int) config('queue.connections.database.retry_after') <= 420) {
            throw ValidationException::withMessages(['api_generation' => 'DB_QUEUE_RETRY_AFTERを900秒以上に設定してから自動作成を利用してください。']);
        }
        try {
            Cache::lock('wallpaper-generation:'.$date, 10)->block(3, function () use ($date, $prompts): void {
                if (Wallpaper::query()->where('target_date', $date)->exists()) {
                    throw ValidationException::withMessages(['api_generation' => 'この日付の壁紙は登録済みです。']);
                }
                $prepared = $prompts->composition($date, jsonOnly: true);
                if (ApiRun::query()->where('type', ApiRun::WALLPAPER_GENERATION)
                    ->where('input_hash', $prepared['input_hash'])->whereIn('status', ['queued', 'running'])->exists()) {
                    return;
                }
                $run = ApiRun::query()->create([
                    'type' => ApiRun::WALLPAPER_GENERATION,
                    'model' => config('lucky.openai.text_model').' / '.config('lucky.openai.image_model'),
                    'prompt_version' => KigakuPolicy::VERSION,
                    'input_hash' => $prepared['input_hash'],
                    'status' => 'queued',
                ]);
                try {
                    GenerateWallpaper::dispatch($run->id, $date)->afterCommit();
                } catch (Throwable) {
                    $run->update(['status' => 'failed', 'error_code' => 'generation_dispatch_failed', 'finished_at' => now()]);
                    throw ValidationException::withMessages(['api_generation' => '自動作成を開始できませんでした。キューの設定を確認してください。']);
                }
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['api_generation' => '開始処理中です。少し待ってから再読み込みしてください。']);
        }

        return to_route('wallpapers.create', ['date' => $date]);
    }
}
