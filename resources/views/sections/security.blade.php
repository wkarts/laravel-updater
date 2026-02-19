@extends('laravel-updater::layout')
@section('page_title', 'Segurança')

@section('content')
<div class="grid">
    <div class="card">
        <h3>2FA e recovery</h3>
        <p class="muted">Acesse seu perfil para ativar/desativar 2FA, visualizar QRCode no setup e regenerar recovery codes.</p>
        <a class="btn btn-primary" href="{{ route('updater.profile') }}">Abrir perfil e segurança</a>
    </div>

    <div class="card">
        <h3>Boas práticas</h3>
        <ul>
            <li>Ative 2FA para administradores.</li>
            <li>Guarde os recovery codes em local seguro.</li>
            <li>Revogue tokens e sessões periodicamente.</li>
        </ul>
    </div>

    <div class="card">
        <h3>Manutenção do Git</h3>
        <p class="muted">
            O Updater mantém o repositório saudável (prune/gc) para evitar crescimento do <code>.git</code> no servidor.
            Isso <strong>não</strong> substitui deploy por artefatos, mas aumenta estabilidade no modo <em>inplace</em>.
        </p>
        <p><strong>Tamanho atual do .git:</strong> {{ number_format(($gitSizeBytes ?? 0) / 1024 / 1024, 2, ',', '.') }} MB</p>

        <form method="POST" action="{{ route('updater.security.git.maintain') }}" style="margin-top: 10px;">
            @csrf
            <button class="btn btn-primary" type="submit" {{ !($gitMaintenanceEnabled ?? true) ? 'disabled' : '' }}>Executar manutenção agora</button>
            @if(!($gitMaintenanceEnabled ?? true))
                <span class="muted" style="margin-left: 8px;">(desabilitado em config)</span>
            @endif
        </form>
    </div>

    <div class="card">
        <h3>Lock de atualização</h3>
        <p class="muted">
            O lock evita duas atualizações/rollbacks simultâneos (UI, jobs, CRON) corrompendo o diretório e o banco.
            Se uma execução travar e deixar lock preso, você pode limpá-lo aqui.
        </p>

        @php($lock = $lockInfo ?? [])
        <div class="muted" style="font-size: 13px;">
            <div><strong>Driver:</strong> {{ $lock['driver'] ?? 'n/a' }}</div>
            @if(($lock['driver'] ?? '') === 'file')
                <div><strong>Arquivo:</strong> {{ $lock['path'] ?? '' }}</div>
                <div><strong>PID:</strong> {{ $lock['pid'] ?? 'n/a' }}</div>
                <div><strong>Modificado em:</strong> {{ isset($lock['mtime']) && $lock['mtime'] ? date('Y-m-d H:i:s', (int)$lock['mtime']) : 'n/a' }}</div>
            @elseif(($lock['driver'] ?? '') === 'cache')
                <div><strong>Chave:</strong> {{ $lock['key'] ?? '' }}</div>
                <div><strong>Meta:</strong> {{ is_array($lock['meta'] ?? null) ? json_encode($lock['meta'], JSON_UNESCAPED_UNICODE) : 'n/a' }}</div>
            @endif
        </div>

        <form method="POST" action="{{ route('updater.security.lock.clear') }}" style="margin-top: 10px;" onsubmit="return confirm('Tem certeza? Se houver uma execução em andamento, ela poderá falhar.');">
            @csrf
            <button class="btn" type="submit">Limpar lock de atualização</button>
        </form>
    </div>
</div>
@endsection
