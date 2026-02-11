<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AuthController extends Controller
{
    public function __construct(private readonly AuthStore $authStore, private readonly Totp $totp)
    {
    }

    public function showLogin()
    {
        return view('laravel-updater::login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $maxAttempts = (int) config('updater.ui.auth.rate_limit.max_attempts', 10);
        $decayMinutes = (int) config('updater.ui.auth.rate_limit.decay_minutes', 10);
        $ip = $request->ip();

        if ($this->authStore->isRateLimited($validated['email'], $ip, $maxAttempts, $decayMinutes)) {
            return back()->withInput(['email' => $validated['email']])->withErrors([
                'email' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.',
            ]);
        }

        $user = $this->authStore->findUserByEmail($validated['email']);
        if ($user === null || !$this->authStore->verifyPassword($user, $validated['password']) || (int) $user['is_active'] !== 1) {
            $this->authStore->registerLoginFailure($validated['email'], $ip, $decayMinutes);
            return back()->withInput(['email' => $validated['email']])->withErrors([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        $this->authStore->clearLoginFailures($validated['email'], $ip);

        $twoFaEnabled = (bool) config('updater.ui.auth.2fa.enabled', true);
        $twoFaRequired = (bool) config('updater.ui.auth.2fa.required', false);

        if ($twoFaEnabled && (int) $user['totp_enabled'] === 1) {
            $request->session()->put('updater_pending_user_id', (int) $user['id']);
            return redirect()->route('updater.2fa');
        }

        if ($twoFaEnabled && $twoFaRequired && (int) $user['totp_enabled'] !== 1) {
            $request->session()->put('updater_pending_user_id', (int) $user['id']);
            return redirect()->route('updater.profile')->with('status', 'Configure o 2FA para concluir o login.');
        }

        return $this->createAuthenticatedRedirect($request, (int) $user['id']);
    }

    public function showTwoFactor(Request $request)
    {
        if (!$request->session()->has('updater_pending_user_id')) {
            return redirect()->route('updater.login');
        }

        return view('laravel-updater::twofactor');
    }

    public function verifyTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $pendingId = (int) $request->session()->get('updater_pending_user_id', 0);
        if ($pendingId <= 0) {
            return redirect()->route('updater.login');
        }

        $user = $this->authStore->findUserById($pendingId);
        if ($user === null || (int) $user['totp_enabled'] !== 1 || empty($user['totp_secret'])) {
            return redirect()->route('updater.profile')->withErrors(['code' => '2FA não está configurado para este usuário.']);
        }

        if (!$this->totp->verify((string) $user['totp_secret'], (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'Código 2FA inválido.']);
        }

        $request->session()->forget('updater_pending_user_id');

        return $this->createAuthenticatedRedirect($request, (int) $user['id']);
    }

    public function logout(Request $request): RedirectResponse
    {
        $sessionId = (string) $request->cookie('updater_session', '');
        if ($sessionId !== '') {
            $this->authStore->invalidateSession($sessionId);
        }

        $request->session()->forget('updater_pending_user_id');

        return redirect()->route('updater.login')->withCookie(cookie(
            'updater_session',
            '',
            -1,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax'
        ));
    }

    public function profile(Request $request)
    {
        $user = $this->currentUser($request);

        $pendingSecret = (string) $request->session()->get('updater_totp_secret', '');
        if ($pendingSecret === '') {
            $pendingSecret = $this->totp->generateSecret();
            $request->session()->put('updater_totp_secret', $pendingSecret);
        }

        return view('laravel-updater::profile', [
            'user' => $user,
            'pendingTotpSecret' => $pendingSecret,
            'otpauthUri' => $this->totp->otpauthUri(
                (string) config('updater.ui.auth.2fa.issuer', 'Argws Updater'),
                (string) $user['email'],
                $pendingSecret
            ),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = $this->currentUser($request);
        $this->authStore->updatePassword((int) $user['id'], $validated['password']);

        return back()->with('status', 'Senha alterada com sucesso.');
    }

    public function enableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $this->currentUser($request);
        $secret = (string) $request->session()->get('updater_totp_secret', '');
        if ($secret === '') {
            $secret = $this->totp->generateSecret();
            $request->session()->put('updater_totp_secret', $secret);
        }

        if (!$this->totp->verify($secret, (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'Código TOTP inválido para ativação.']);
        }

        $this->authStore->updateTotp((int) $user['id'], $secret, true);
        $request->session()->forget('updater_totp_secret');

        return back()->with('status', '2FA ativado com sucesso.');
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->authStore->updateTotp((int) $user['id'], null, false);

        return back()->with('status', '2FA desativado com sucesso.');
    }

    private function createAuthenticatedRedirect(Request $request, int $userId): RedirectResponse
    {
        $ttl = (int) config('updater.ui.auth.session_ttl_minutes', 120);
        $sessionId = $this->authStore->createSession($userId, $request->ip(), $request->userAgent(), $ttl);

        return redirect()->route('updater.index')->withCookie(cookie(
            'updater_session',
            $sessionId,
            $ttl,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax'
        ));
    }

    private function currentUser(Request $request): array
    {
        $user = $request->attributes->get('updater_user');
        if (is_array($user)) {
            return $user;
        }

        $sessionId = (string) $request->cookie('updater_session', '');
        $session = $this->authStore->findValidSession($sessionId);

        if ($session === null) {
            abort(401);
        }

        return [
            'id' => (int) $session['user_id'],
            'email' => $session['email'],
            'is_admin' => (int) $session['is_admin'] === 1,
            'totp_enabled' => (int) $session['totp_enabled'] === 1,
            'totp_secret' => $session['totp_secret'],
        ];
    }
}
