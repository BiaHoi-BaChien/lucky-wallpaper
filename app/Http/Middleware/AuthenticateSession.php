<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Session\Middleware\AuthenticateSession as BaseAuthenticateSession;

class AuthenticateSession extends BaseAuthenticateSession
{
    public function handle($request, Closure $next)
    {
        $guard = $this->auth->guard();

        // Existing sessions without an issuance-time hash must authenticate again.
        if ($request->hasSession() && $request->user() && $guard instanceof SessionGuard
            && ! $guard->viaRemember()
            && $request->session()->has($guard->getName())
            && ! $request->session()->has('password_hash_'.$this->auth->getDefaultDriver())) {
            $this->logout($request);
        }

        $response = parent::handle($request, $next);

        // The parent skips its response hook when a guest logs in during $next.
        if ($request->hasSession()) {
            $this->storePasswordHashInSession($request);
        }

        return $response;
    }
}
