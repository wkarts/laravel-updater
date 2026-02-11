# argws/laravel-updater

Pacote Composer para autoatualização segura, idempotente e reversível de aplicações Laravel.

## Compatibilidade

- PHP: **8.2, 8.3, 8.4**
- Laravel/Illuminate: **10, 11, 12**

## Instalação

```bash
composer require argws/laravel-updater
php artisan vendor:publish --tag=updater-config
```

## Versionamento para Composer (VCS)

Publique tags semânticas no formato `vX.Y.Z` (ex.: `v1.0.0`).

## Autenticação independente do pacote (UI)

Quando `UPDATER_UI_AUTH_ENABLED=true`, o pacote usa autenticação própria no SQLite interno e **não depende do guard/auth da aplicação hospedeira**.

### Variáveis de ambiente

```dotenv
UPDATER_UI_ENABLED=true
UPDATER_UI_PREFIX=/_updater

UPDATER_UI_AUTH_ENABLED=true
UPDATER_UI_AUTO_PROVISION_ADMIN=true
UPDATER_UI_DEFAULT_EMAIL=admin@admin.com
UPDATER_UI_DEFAULT_PASSWORD=123456
UPDATER_UI_SESSION_TTL=120

UPDATER_UI_2FA_ENABLED=true
UPDATER_UI_2FA_REQUIRED=false
UPDATER_UI_2FA_ISSUER="Argws Updater"
```

### Tabelas internas no SQLite do updater

- `updater_users`
- `updater_sessions`
- `updater_login_attempts`
- `runs`
- `patches`
- `artifacts`

## Fluxo da UI

- `GET /_updater/login` -> formulário de login
- `POST /_updater/login` -> valida usuário/senha e rate limit
- `GET /_updater/2fa` e `POST /_updater/2fa` -> validação TOTP (6 dígitos)
- `GET /_updater/` -> dashboard protegido
- `GET /_updater/profile` e `POST /_updater/profile` -> senha e 2FA
- `POST /_updater/logout` -> encerra sessão

Quando `UPDATER_UI_AUTO_PROVISION_ADMIN=true`, o pacote cria automaticamente o admin padrão se não existir.

## Comandos Artisan

```bash
php artisan system:update:check
php artisan system:update:run --force
php artisan system:update:rollback --force
php artisan system:update:status
```

## Segurança

- Execução de update via CLI
- Lock de concorrência
- Preflight de binários/permissões/espaço
- Sessão independente do app via cookie `updater_session`
- 2FA TOTP com tolerância de janela ±1 (step 30s)

## CI/Release

- CI matrix: PHP 8.2/8.3/8.4 e Laravel 10/11/12
- Release automática em tags `v*`
