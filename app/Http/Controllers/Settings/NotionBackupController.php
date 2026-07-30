<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use Inertia\Inertia;
use Inertia\Response;

class NotionBackupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/notion-backup', [
            'latestRestore' => SyncRun::query()
                ->where('type', 'notion_import')
                ->latest()
                ->first(),
        ]);
    }
}
