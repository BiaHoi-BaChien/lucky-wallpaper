<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessNotionSync;
use App\Models\SyncRun;
use App\Services\NotionClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotionSyncController extends Controller
{
    public function store(NotionClient $notion): RedirectResponse
    {
        if (! $notion->isConfigured()) {
            throw ValidationException::withMessages([
                'restore' => 'NOTION_TOKENが未設定のため、バックアップから復元できません。',
            ]);
        }

        $run = DB::transaction(function (): SyncRun {
            $active = SyncRun::query()
                ->where('type', 'notion_import')
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->first();
            if ($active !== null) {
                throw ValidationException::withMessages([
                    'restore' => 'バックアップからの復元は既に実行中です。',
                ]);
            }

            return SyncRun::query()->create(['type' => 'notion_import']);
        });

        ProcessNotionSync::dispatch($run->id);

        return back()->with('operationId', $run->id);
    }
}
