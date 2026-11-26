<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

final class ReadOnlySession extends StartSession
{
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        // A status check should neither renew the session nor send an old cookie during sign-in.
        $request->setLaravelSession($this->startSession($request, $session));

        return $next($request);
    }
}
