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
        $sessionId = (string) $request->cookie('updater_session', '');
        if ($sessionId === '') {
            return redirect()->route('updater.login');
        }

        $session = $this->authStore->findValidSession($sessionId);
        if ($session === null) {
            return redirect()->route('updater.login');
        }

        $user = $this->authStore->findUserById((int) $session['user_id']);
        if ($user === null || (int) $user['is_active'] !== 1) {
            return redirect()->route('updater.login');
        }

        $request->attributes->set('updater_user', $user);

        return $next($request);
    }
}
