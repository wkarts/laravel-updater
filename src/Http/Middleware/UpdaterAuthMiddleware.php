<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Middleware;

use Argws\LaravelUpdater\Support\AuthStore;
use Closure;
use Illuminate\Http\Request;

class UpdaterAuthMiddleware
{
    public function __construct(private readonly AuthStore $authStore)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (!(bool) config('updater.ui.auth.enabled', false)) {
            return $next($request);
        }

        $sessionId = (string) $request->cookie('updater_session', '');
        if ($sessionId === '') {
            return redirect()->route('updater.login');
        }

        $session = $this->authStore->findValidSession($sessionId);
        if ($session === null) {
            return redirect()->route('updater.login');
        }

        $request->attributes->set('updater_user', [
            'id' => (int) $session['user_id'],
            'email' => $session['email'],
            'is_admin' => (int) $session['is_admin'] === 1,
            'totp_enabled' => (int) $session['totp_enabled'] === 1,
            'totp_secret' => $session['totp_secret'],
        ]);

        return $next($request);
    }
}
