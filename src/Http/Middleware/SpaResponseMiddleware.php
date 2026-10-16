<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Middleware;

use Argws\LaravelUpdater\Support\SpaPresenter;
use Argws\LaravelUpdater\Support\SpaAssets;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

/** Wraps the existing route stack: authorization, sessions and CSRF still execute normally. */
class SpaResponseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $assets = app(SpaAssets::class);
        if ($assets->isCachedRouteRequest($request)) {
            return $assets->serveFromQuery($request);
        }
        try {
            $response = $next($request);
        } catch (ValidationException $exception) {
            if ($request->header('X-Updater-SPA') === '1') {
                return response()->json(['message' => 'Confira os campos informados.', 'errors' => $exception->errors()], 422)->header('Cache-Control', 'private, no-store');
            }
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            if ($request->isMethod('GET') && !$this->isDataRoute($request)) {
                $status = $exception->getStatusCode();
                $message = match ($status) { 403 => 'Você não tem permissão para acessar este recurso.', 404 => 'A página solicitada não foi encontrada.', 419 => 'Sua sessão expirou. Atualize a página.', 503 => 'O serviço está temporariamente indisponível.', default => 'Não foi possível concluir esta solicitação.' };
                $response = response()->view('laravel-updater::spa-error', ['status' => $status, 'message' => $message], $status, $exception->getHeaders());
            } else { throw $exception; }
        } catch (\Throwable $exception) {
            if (!$request->isMethod('GET') || $this->isDataRoute($request)) { throw $exception; }
            // Never expose exception messages/stack traces to the product interface.
            $response = response()->view('laravel-updater::spa-error', ['status' => 500, 'message' => 'Não foi possível carregar esta página. Tente novamente ou consulte o administrador.'], 500);
        }
        // Laravel's routing pipeline may already have converted middleware exceptions
        // to responses before this wrapper resumes (notably auth 403/404).
        if ($request->isMethod('GET') && !$this->isDataRoute($request) && $response->getStatusCode() >= 400) {
            $status = $response->getStatusCode();
            $message = match ($status) { 403 => 'Você não tem permissão para acessar este recurso.', 404 => 'A página solicitada não foi encontrada.', 419 => 'Sua sessão expirou. Atualize a página.', 503 => 'O serviço está temporariamente indisponível.', default => 'Não foi possível carregar esta página. Tente novamente ou consulte o administrador.' };
            $response = response()->view('laravel-updater::spa-error', ['status' => $status, 'message' => $message], $status);
        }
        $original = method_exists($response, 'getOriginalContent') ? $response->getOriginalContent() : null;
        if ($original instanceof View && isset($original->getData()['__updaterBootstrap'])) {
            $payload = $original->getData()['__updaterBootstrap'];
            if ($request->header('X-Updater-SPA') === '1') { $response = response()->json($payload, $response->getStatusCode()); }
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->setVary(['X-Updater-SPA', 'Accept'], false);
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        } elseif ($response instanceof JsonResponse && $request->isMethod('GET') && !$this->isApiRoute($request)) {
            $response->setData(app(SpaPresenter::class)->sanitizeResponse($response->getData(true)));
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        return $response;
    }

    private function isApiRoute(Request $request): bool { return str_starts_with((string) $request->route()?->getName(), 'updater.api.'); }
    private function isDataRoute(Request $request): bool
    {
        $name = (string) $request->route()?->getName();
        return in_array($name, ['updater.health', 'updater.release-notes', 'updater.spa.entry'], true) || str_starts_with($name, 'updater.branding.') || in_array($request->query('download'), ['json', 'zip'], true) || $this->isApiRoute($request) || str_contains($name, '.asset') || str_contains($name, '.download') || str_contains($name, '.progress.') || $name === 'updater.status';
    }
}
