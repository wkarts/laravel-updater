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

    public function loginForm()
    {
        return view('laravel-updater::auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');
        $ip = (string) $request->ip();

        if ($this->authStore->tooManyAttempts($email, $ip)) {
            return back()->withErrors(['email' => 'Muitas tentativas. Aguarde alguns minutos.']);
        }

        $user = $this->authStore->findUserByEmail($email);
        if ($user === null || !$this->authStore->verifyPassword($user, $password)) {
            $this->authStore->registerFailedAttempt($email, $ip);
            return back()->withErrors(['email' => 'Credenciais inválidas.']);
        }

        $this->authStore->clearAttempts($email, $ip);

        $twoFactorEnabled = (bool) config('updater.ui.two_factor.enabled', false);
        $twoFactorRequired = (bool) config('updater.ui.two_factor.required', false);
        $userTotpEnabled = (int) ($user['totp_enabled'] ?? 0) === 1;

        if ($twoFactorEnabled && ($twoFactorRequired || $userTotpEnabled)) {
            session(['updater_pending_user_id' => (int) $user['id']]);
            return redirect()->route('updater.2fa');
        }

        return $this->createSessionAndRedirect($request, (int) $user['id']);
    }

    public function twoFactorForm(Request $request)
    {
        if (!$request->session()->has('updater_pending_user_id')) {
            return redirect()->route('updater.login');
        }

        return view('laravel-updater::auth.twofactor');
    }

    public function twoFactor(Request $request): RedirectResponse
    {
        $userId = (int) $request->session()->get('updater_pending_user_id', 0);
        $user = $this->authStore->findUserById($userId);
        if ($user === null) {
            return redirect()->route('updater.login');
        }

        $secret = (string) ($user['totp_secret'] ?? '');
        $code = (string) $request->input('code', '');
        if ($secret === '' || !$this->totp->verify($secret, $code)) {
            return back()->withErrors(['code' => 'Código 2FA inválido.']);
        }

        $request->session()->forget('updater_pending_user_id');

        return $this->createSessionAndRedirect($request, $userId);
    }

    public function logout(Request $request): RedirectResponse
    {
        $sessionId = (string) $request->cookie('updater_session', '');
        if ($sessionId !== '') {
            $this->authStore->deleteSession($sessionId);
        }

        return redirect()->route('updater.login')->cookie(cookie()->forget('updater_session'));
    }

    public function profile(Request $request)
    {
        $user = (array) $request->attributes->get('updater_user');

        return view('laravel-updater::auth.profile', [
            'user' => $user,
            'issuer' => (string) config('updater.ui.two_factor.issuer', 'Argws Updater'),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = (array) $request->attributes->get('updater_user');
        $userId = (int) ($user['id'] ?? 0);

        $password = (string) $request->input('password', '');
        if ($password !== '') {
            $this->authStore->updatePassword($userId, $password);
        }

        $enable2fa = (bool) $request->boolean('enable_2fa');
        if ($enable2fa) {
            $secret = $this->totp->generateSecret();
            $this->authStore->updateTotp($userId, $secret, true);
        } else {
            $this->authStore->updateTotp($userId, null, false);
        }

        return back()->with('status', 'Perfil atualizado com sucesso.');
    }

    private function createSessionAndRedirect(Request $request, int $userId): RedirectResponse
    {
        $ttl = (int) config('updater.ui.auth.session_ttl', 120);
        $sessionId = $this->authStore->createSession($userId, (string) $request->ip(), (string) $request->userAgent(), $ttl);

        return redirect()->route('updater.index')->cookie('updater_session', $sessionId, $ttl, '/', null, false, true, false, 'lax');
    }
}
