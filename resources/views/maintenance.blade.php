<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title ?? config('updater.maintenance.default_title') }}</title>

    @php
        $branding = [];
        try {
            $branding = app(\Argws\LaravelUpdater\Support\ManagerStore::class)->resolvedBranding();
        } catch (\Throwable $e) {
            $branding = [];
        }

        $primary = $branding['primary_color'] ?? (string) config('updater.app.primary_color', '#0d6efd');

        // Mesma parametrização de branding do painel, com prioridade para logo dedicado da manutenção.
        $logoUrl = null;
        if (!empty($branding['maintenance_logo_path'])) {
            try {
                $logoUrl = route('updater.branding.maintenance_logo');
            } catch (\Throwable $e) {
                $logoUrl = null;
            }
        }

        if (empty($logoUrl)) {
            $logoUrl = $branding['maintenance_logo_url'] ?? ((string) config('updater.maintenance.logo_url', '') ?: null);
        }

        if (empty($logoUrl) && !empty($branding['logo_path'])) {
            try {
                $logoUrl = route('updater.branding.logo');
            } catch (\Throwable $e) {
                $logoUrl = null;
            }
        }

        if (empty($logoUrl)) {
            $logoUrl = $branding['logo_url'] ?? ((string) config('updater.app.logo_url', '') ?: null);
        }

        $faviconUrl = $branding['favicon_url'] ?? ((string) config('updater.app.favicon_url', '') ?: null);
        $appName = $branding['app_name_full'] ?? ($branding['app_name'] ?? config('app.name', 'Aplicação'));
        $appDesc = $branding['app_desc'] ?? (string) config('updater.app.desc', '');
        $message = $branding['maintenance_message'] ?? config('updater.maintenance.default_message');
        $footer  = $branding['maintenance_footer']  ?? config('updater.maintenance.default_footer');
    @endphp

    @if(!empty($faviconUrl))
        <link rel="icon" href="{{ $faviconUrl }}" />
    @endif
    <meta name="theme-color" content="{{ $primary }}" />

    <style>
        :root { --primary: {{ $primary }}; --bg: #0b1220; --card: rgba(255,255,255,.06); --text: rgba(255,255,255,.92); --muted: rgba(255,255,255,.65); }
        body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial; background: radial-gradient(1200px 600px at 20% 10%, rgba(13,110,253,.25), transparent 55%), radial-gradient(1200px 600px at 80% 70%, rgba(88,199,250,.18), transparent 55%), var(--bg); color: var(--text); }
        .wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding: 24px; }
        .card { width: 100%; max-width: 520px; background: var(--card); border: 1px solid rgba(255,255,255,.10); border-radius: 18px; padding: 22px; box-shadow: 0 20px 70px rgba(0,0,0,.35); }
        .top { display:flex; gap: 14px; align-items:center; }
        .logo { width: 52px; height: 52px; border-radius: 14px; background: rgba(255,255,255,.08); display:flex; align-items:center; justify-content:center; overflow:hidden; flex: 0 0 52px; }
        .logo img { width: 100%; height: 100%; object-fit: cover; }
        .name { font-weight: 700; font-size: 18px; line-height: 1.15; }
        .desc { color: var(--muted); font-size: 13px; margin-top: 2px; }
        h1 { margin: 18px 0 8px; font-size: 20px; }
        p { margin: 0; color: var(--muted); line-height: 1.45; }
        .bar { height: 10px; border-radius: 999px; background: rgba(255,255,255,.08); margin-top: 16px; overflow:hidden; }
        .bar > i { display:block; height:100%; width: 35%; background: linear-gradient(90deg, var(--primary), rgba(255,255,255,.35)); border-radius: 999px; animation: ind 1.2s ease-in-out infinite; }
        @keyframes ind { 0% { transform: translateX(-30%); } 50% { transform: translateX(140%); } 100% { transform: translateX(-30%); } }
        .foot { margin-top: 14px; font-size: 12px; color: rgba(255,255,255,.55); }
        .pill { display:inline-flex; align-items:center; gap:8px; padding: 8px 10px; border-radius: 999px; border: 1px solid rgba(255,255,255,.10); background: rgba(255,255,255,.06); margin-top: 12px; }
        .dot { width: 8px; height: 8px; border-radius: 999px; background: var(--primary); box-shadow: 0 0 0 6px rgba(13,110,253,.15); }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="top">
            <div class="logo">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}" alt="logo">
                @else
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 2l8 4.5v11L12 22l-8-4.5v-11L12 2z" stroke="rgba(255,255,255,.85)" stroke-width="1.6"/>
                        <path d="M12 7v10" stroke="rgba(255,255,255,.65)" stroke-width="1.6"/>
                        <path d="M7.5 9.5L12 7l4.5 2.5" stroke="rgba(255,255,255,.65)" stroke-width="1.6"/>
                    </svg>
                @endif
            </div>
            <div>
                <div class="name">{{ $appName }}</div>
                @if($appDesc)
                    <div class="desc">{{ $appDesc }}</div>
                @endif
            </div>
        </div>

        <div class="pill"><span class="dot"></span><span>Modo manutenção</span></div>

        <h1>{{ $title ?? config('updater.maintenance.default_title') }}</h1>
        <p>{{ $message }}</p>

        <div class="bar" aria-hidden="true"><i></i></div>

        <div class="foot">{{ $footer }}</div>

        <div id="updater-live" class="foot" style="margin-top:10px;">Verificando andamento da atualização…</div>
    </div>
</div>
<script>
(function () {
    const live = document.getElementById('updater-live');
    const prefix = '{{ trim((string) config('updater.ui.prefix', '_updater'), '/') }}';
    const statusUrl = '/' + prefix + '/status';
    const started = Date.now();

    function tick() {
        const secs = Math.floor((Date.now() - started) / 1000);
        if (live) {
            live.textContent = 'Atualização em andamento… ' + secs + 's';
        }

        fetch(statusUrl, { credentials: 'same-origin' })
            .then((r) => r.ok ? r.json() : null)
            .then((data) => {
                if (!data || !data.last_run) return;
                const st = String(data.last_run.status || '').toLowerCase();
                if (st === 'success' || st === 'dry_run') {
                    window.location.reload();
                }
                if (st === 'failed' && live) {
                    live.textContent = 'Falha na atualização detectada. Aguarde nova tentativa/rollback.';
                }
            })
            .catch(() => {
                // rota pode estar protegida; fallback: recarregar periodicamente
            });
    }

    setInterval(tick, 5000);
    setInterval(() => window.location.reload(), 30000);
    tick();
})();
</script>
</body>
</html>
