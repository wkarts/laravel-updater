<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Middleware;

use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\UiPermission;
use Closure;
use Illuminate\Http\Request;

class UpdaterAuthMiddleware
{
    public function __construct(private readonly AuthStore $authStore, private readonly UiPermission $permission)
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

        $permissions = [];
        if (!empty($session['permissions_json'])) {
            $decoded = json_decode((string) $session['permissions_json'], true);
            $permissions = is_array($decoded) ? $decoded : [];
        }

        $user = [
            'id' => (int) $session['user_id'],
            'email' => $session['email'],
            'name' => $session['name'] ?? null,
            'is_admin' => (int) $session['is_admin'] === 1,
            'permissions' => $permissions,
            'permissions_json' => $session['permissions_json'] ?? null,
            'totp_enabled' => (int) $session['totp_enabled'] === 1,
            'totp_secret' => $session['totp_secret'],
        ];

        $user['is_master'] = $this->permission->isMaster($user);

        $request->attributes->set('updater_user', $user);

        return $next($request);
    }
}
