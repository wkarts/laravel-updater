<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ (string) config('updater.maintenance.whitelabel.title', config('app.name')) }}</title>
    <style>
        :root { --primary: #3b82f6; }
        body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#0b1220; color:#e5e7eb; }
        .wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .card { width: min(720px, 100%); background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 16px; padding: 28px; box-shadow: 0 18px 55px rgba(0,0,0,.35); }
        h1 { margin:0 0 8px; font-size: 22px; line-height:1.2; }
        p { margin:0; color:#cbd5e1; line-height:1.55; }
        .meta { margin-top: 18px; display:flex; gap:10px; flex-wrap:wrap; }
        .pill { display:inline-flex; align-items:center; gap:8px; padding: 8px 12px; border-radius: 999px; background: rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); font-size: 12px; color:#e2e8f0; }
        .dot { width:8px; height:8px; border-radius:999px; background: var(--primary); box-shadow: 0 0 0 4px rgba(59,130,246,.18); }
        .footer { margin-top: 22px; font-size: 12px; color:#94a3b8; }
        @media (max-width: 420px){ .card{ padding: 20px; } h1{ font-size: 19px; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>{{ (string) config('updater.maintenance.whitelabel.title', config('app.name')) }}</h1>
        <p>{{ (string) config('updater.maintenance.whitelabel.message', 'Estamos realizando uma atualização. Volte em alguns instantes.') }}</p>

        <div class="meta">
            <span class="pill"><span class="dot"></span>Modo manutenção</span>
            <span class="pill">Laravel Updater</span>
        </div>

        <div class="footer">
            {{ (string) config('updater.maintenance.whitelabel.footer', '') }}
        </div>
    </div>
</div>
</body>
</html>
