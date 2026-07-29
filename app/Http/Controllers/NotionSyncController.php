<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessNotionSync;
use App\Models\SyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotionSyncController extends Controller
{
    public function store(): RedirectResponse
    {
        $run = DB::transaction(function (): SyncRun {
            $active = SyncRun::query()
                ->where('type', 'notion_import')
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->first();
            if ($active !== null) {
                throw ValidationException::withMessages([
                    'sync' => '実績情報取り込みは既に実行中です。',
                ]);
            }

            return SyncRun::query()->create(['type' => 'notion_import']);
        });

        ProcessNotionSync::dispatch($run->id);

        return back()->with('operationId', $run->id);
    }
}
