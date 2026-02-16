<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Middleware;

use Argws\LaravelUpdater\Support\UiPermission;
use Closure;
use Illuminate\Http\Request;

class UpdaterAuthorizeMiddleware
{
    public function __construct(private readonly UiPermission $permission)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (!(bool) config('updater.ui.auth.enabled', false)) {
            return $next($request);
        }

        $user = (array) $request->attributes->get('updater_user', []);
        $required = $this->permission->requiredPermissionForRoute($request->route()?->getName());

        if ($required !== null && !$this->permission->has($user, $required)) {
            abort(403, 'Sem permissão para acessar este recurso.');
        }

        if (($request->route()?->getName() ?? '') === 'updater.section') {
            $section = (string) $request->route('section');
            if (!$this->permission->canAccessSection($user, $section)) {
                abort(403, 'Sem permissão para acessar esta seção.');
            }
        }

        return $next($request);
    }
}
