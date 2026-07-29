<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PasskeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/passkeys', [
            'passkeys' => $request->user()
                ->passkeys()
                ->latest()
                ->get(['id', 'name', 'last_used_at', 'created_at']),
        ]);
    }
}
