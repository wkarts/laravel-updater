<div class="card">
    <h3>Segurança</h3>
    <p class="muted">2FA global: <strong>{{ config('updater.ui.auth.2fa.enabled', true) ? 'Ativado' : 'Desativado' }}</strong></p>
    <p class="muted">2FA obrigatório: <strong>{{ config('updater.ui.auth.2fa.required', false) ? 'Sim' : 'Não' }}</strong></p>
    <p class="muted">Sessão (min): <strong>{{ config('updater.ui.auth.session_ttl_minutes', 120) }}</strong></p>
    <p class="muted">Rate limit: <strong>{{ config('updater.ui.auth.rate_limit.max_attempts', 10) }} tentativas / {{ config('updater.ui.auth.rate_limit.window_seconds', 600) }}s</strong></p>
</div>
