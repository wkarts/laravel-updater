<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Http\Controllers;

use Argws\LaravelUpdater\Support\AuthStore;
use Argws\LaravelUpdater\Support\ManagerStore;
use Argws\LaravelUpdater\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AuthController extends Controller
{
    public function __construct(private readonly AuthStore $authStore, private readonly Totp $totp, private readonly ManagerStore $managerStore)
    {
    }

    public function showLogin(Request $request)
    {
        if ($this->isAuthenticated($request)) {
            return redirect()->route('updater.index');
        }

        return view('laravel-updater::login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'password.required' => 'Informe a senha.',
        ]);

        $maxAttempts = (int) config('updater.ui.auth.rate_limit.max_attempts', 10);
        $windowSeconds = (int) config('updater.ui.auth.rate_limit.window_seconds', 600);
        $decayMinutes = max(1, (int) ceil($windowSeconds / 60));
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

        $this->managerStore->addAuditLog((int) $user['id'], 'login', ['email' => $user['email']], $request->ip(), $request->userAgent());

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
        $request->validate(['code' => ['required', 'string']], [
            'code.required' => 'Informe o código 2FA ou um recovery code.',
        ]);
        $pendingId = (int) $request->session()->get('updater_pending_user_id', 0);
        if ($pendingId <= 0) {
            return redirect()->route('updater.login');
        }

        $user = $this->authStore->findUserById($pendingId);
        if ($user === null || (int) $user['totp_enabled'] !== 1 || empty($user['totp_secret'])) {
            return redirect()->route('updater.profile')->withErrors(['code' => '2FA não está configurado para este usuário.']);
        }

        $code = (string) $request->input('code');
        $validTotp = $this->totp->verify((string) $user['totp_secret'], $code);
        $validRecovery = !$validTotp && $this->authStore->consumeRecoveryCode((int) $user['id'], $code);

        if (!$validTotp && !$validRecovery) {
            return back()->withErrors(['code' => 'Código 2FA/recovery inválido.']);
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
        $this->managerStore->addAuditLog($this->actorId($request), 'logout', [], $request->ip(), $request->userAgent());

        return redirect()->route('updater.login')->withCookie(cookie('updater_session', '', -1, '/', null, $request->isSecure(), true, false, 'lax'));
    }

    public function profile(Request $request)
    {
        $user = $this->currentUser($request);

        $pendingSecret = (string) $request->session()->get('updater_totp_secret', '');
        if ($pendingSecret === '') {
            $pendingSecret = !empty($user['totp_secret']) ? (string) $user['totp_secret'] : $this->totp->generateSecret();
            $request->session()->put('updater_totp_secret', $pendingSecret);
        }

        $issuer = (string) config('updater.ui.auth.2fa.issuer', 'Argws Updater');
        $otpauthUri = $this->totp->otpauthUri($issuer, (string) $user['email'], $pendingSecret);

        return view('laravel-updater::profile', [
            'user' => $user,
            'pendingTotpSecret' => $pendingSecret,
            'otpauthUri' => $otpauthUri,
            'qrcodeDataUri' => $this->totp->qrcodeDataUri($otpauthUri),
            'recoverySummary' => $this->authStore->recoveryCodesSummary((int) $user['id']),
            'newRecoveryCodes' => $request->session()->get('updater_new_recovery_codes', []),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = $this->currentUser($request);
        $this->authStore->updatePassword((int) $user['id'], $validated['password']);

        return back()->with('status', 'Salvo com sucesso.');
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
            return back()->withErrors(['code' => 'Código 2FA inválido.']);
        }

        $this->authStore->updateTotp((int) $user['id'], $secret, true);
        $codes = $this->authStore->replaceRecoveryCodes((int) $user['id'], 10);
        $request->session()->forget('updater_totp_secret');
        $request->session()->put('updater_new_recovery_codes', $codes);
        $this->managerStore->addAuditLog((int) $user['id'], 'enable2fa', [], $request->ip(), $request->userAgent());

        return back()->with('status', '2FA ativado com sucesso. Guarde seus recovery codes.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $request->validate(['password' => ['required', 'string']]);

        $row = $this->authStore->findUserById((int) $user['id']);
        if ($row === null || !$this->authStore->verifyPassword($row, (string) $request->input('password'))) {
            return back()->withErrors(['password' => 'Senha inválida para regenerar os códigos.']);
        }

        $codes = $this->authStore->replaceRecoveryCodes((int) $user['id'], 10);
        $request->session()->put('updater_new_recovery_codes', $codes);
        $this->managerStore->addAuditLog((int) $user['id'], 'regen_recovery', [], $request->ip(), $request->userAgent());

        return back()->with('status', 'Novos recovery codes gerados com sucesso.');
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $this->authStore->updateTotp((int) $user['id'], null, false);

        return back()->with('status', 'Salvo com sucesso.');
    }

    private function createAuthenticatedRedirect(Request $request, int $userId): RedirectResponse
    {
        $ttl = (int) config('updater.ui.auth.session_ttl_minutes', 120);
        $sessionId = $this->authStore->createSession($userId, $request->ip(), $request->userAgent(), $ttl);

        return redirect()->route('updater.index')->withCookie(cookie('updater_session', $sessionId, $ttl, '/', null, $request->isSecure(), true, false, 'lax'));
    }

    private function isAuthenticated(Request $request): bool
    {
        $sessionId = (string) $request->cookie('updater_session', '');
        return $sessionId !== '' && $this->authStore->findValidSession($sessionId) !== null;
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
            abort(401, 'Acesso negado.');
        }

        return [
            'id' => (int) $session['user_id'],
            'email' => $session['email'],
            'name' => $session['name'] ?? null,
            'is_admin' => (int) $session['is_admin'] === 1,
            'totp_enabled' => (int) $session['totp_enabled'] === 1,
            'totp_secret' => $session['totp_secret'],
        ];
    }

    private function actorId(Request $request): ?int
    {
        $user = $request->attributes->get('updater_user');
        return is_array($user) ? (int) ($user['id'] ?? 0) : null;
    }
}
