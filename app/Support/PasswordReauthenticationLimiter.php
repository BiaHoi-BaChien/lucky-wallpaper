<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class PasswordReauthenticationLimiter
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function ensureNotRateLimited(Request $request): void
    {
        $key = $this->key($request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }
    }

    public function hit(Request $request): void
    {
        RateLimiter::hit($this->key($request), self::DECAY_SECONDS);
    }

    public function clear(Request $request): void
    {
        RateLimiter::clear($this->key($request));
    }

    private function key(Request $request): string
    {
        return 'password-reauthentication:'.$request->user()->getAuthIdentifier().'|'.$request->ip();
    }
}
