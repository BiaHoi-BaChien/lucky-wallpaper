<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InitialSetupController extends Controller
{
    public function create(): Response
    {
        abort_if(User::query()->exists(), 404);

        return Inertia::render('auth/setup');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'setup_key' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:80', 'alpha_dash:ascii'],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $configuredKey = (string) config('lucky.setup_key');
        if ($configuredKey === '' || ! hash_equals($configuredKey, $validated['setup_key'])) {
            throw ValidationException::withMessages([
                'setup_key' => 'セットアップキーが一致しません。',
            ]);
        }

        $user = DB::transaction(function () use ($validated): User {
            if (User::query()->lockForUpdate()->exists()) {
                abort(404);
            }

            return User::query()->create([
                'username' => $validated['username'],
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
            ]);
        }, attempts: 3);

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
