<div class="card">
    <h3>Segurança</h3>
    <p class="muted">2FA global: <strong>{{ config('updater.ui.auth.2fa.enabled', true) ? 'Ativado' : 'Desativado' }}</strong></p>
    <p class="muted">2FA obrigatório: <strong>{{ config('updater.ui.auth.2fa.required', false) ? 'Sim' : 'Não' }}</strong></p>
    <p class="muted">Sessão (min): <strong>{{ config('updater.ui.auth.session_ttl_minutes', 120) }}</strong></p>
    <p class="muted">Rate limit: <strong>{{ config('updater.ui.auth.rate_limit.max_attempts', 10) }} tentativas / {{ config('updater.ui.auth.rate_limit.window_seconds', 600) }}s</strong></p>
</div>


<div class="card" style="margin-top:12px;">
    <h3>Guia rápido de ambiente</h3>
    <p class="muted"><strong>Produção:</strong> prefira <code>UPDATER_TRIGGER_DRIVER=queue</code> e <code>UPDATER_UI_FORCE_SYNC=false</code>.</p>
    <p class="muted"><strong>Homologação:</strong> use <code>UPDATER_TRIGGER_DRIVER=sync</code> para retorno imediato na interface.</p>
    <p class="muted"><strong>Compatibilidade:</strong> também aceitamos <code>UPDATER_UI_LOGIN_MAX_ATTEMPTS</code> e <code>UPDATER_UI_LOGIN_DECAY_MINUTES</code> como fallback do rate limit.</p>
    <p class="muted">Após alterar variáveis, rode <code>php artisan config:clear</code>.</p>
</div>
