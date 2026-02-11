<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Updater')</title>
    <style>
        :root { --bg:#f3f4f6; --card:#fff; --txt:#111827; --muted:#6b7280; --pri:#1d4ed8; --danger:#b91c1c; }
        body { font-family: Inter, Arial, sans-serif; margin:0; background:var(--bg); color:var(--txt); }
        .container { max-width: 980px; margin: 24px auto; padding: 0 16px; }
        .card { background: var(--card); border-radius: 10px; border:1px solid #e5e7eb; padding: 18px; margin-bottom:16px; }
        .row { display:flex; gap:10px; flex-wrap:wrap; }
        input,button { border:1px solid #d1d5db; border-radius:8px; padding:10px; font-size:14px; }
        button { background: var(--pri); color:#fff; border-color: var(--pri); cursor:pointer; }
        button.danger { background: var(--danger); border-color:var(--danger); }
        table { width:100%; border-collapse: collapse; }
        th,td { text-align:left; border-bottom:1px solid #e5e7eb; padding:8px; font-size:13px; }
        .error { color: var(--danger); font-size: 13px; }
        .muted { color: var(--muted); }
        a { color: var(--pri); text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    @yield('content')
</div>
</body>
</html>
