# argws/laravel-updater

Pacote Composer para autoatualização segura, idempotente e reversível de aplicações Laravel.

## Compatibilidade

- PHP: **8.2, 8.3, 8.4**
- Laravel/Illuminate: **10, 11, 12**

## CI e Release

- CI valida matrix real: PHP 8.2/8.3/8.4 + Laravel 10/11/12 (com exclusão de 8.4 + 10).
- Biblioteca **não versiona** `composer.lock`.
- `release.yml` mantém publicação por tags `v*`.
- `release-after-ci.yml` cria tag automática `vX.Y.Z` (incremento patch) após CI verde na `main`.
- Notificação no Packagist ocorre somente se `PACKAGIST_USERNAME` e `PACKAGIST_TOKEN` estiverem configurados.

## Instalação

```bash
composer require argws/laravel-updater
php artisan vendor:publish --tag=updater-config
php artisan vendor:publish --tag=updater-assets
```

## UI e autenticação independente

Quando `UPDATER_UI_AUTH_ENABLED=true`, a UI do updater usa autenticação própria em SQLite interno (StateStore), sem depender de guards/auth do app hospedeiro.

### Rotas no modo auth interno

- Públicas:
  - `GET /_updater/login`
  - `POST /_updater/login`
  - `GET /_updater/2fa`
  - `POST /_updater/2fa`
  - `POST /_updater/logout`
- Protegidas (`web` + `updater.auth`):
  - `GET /_updater`
  - `GET /_updater/profile`
  - Demais endpoints de status/check/update/rollback

Quando `UPDATER_UI_AUTH_ENABLED=false`, o pacote mantém compatibilidade e usa `ui.middleware` legado (default `['web','auth']`).

### Variáveis de ambiente

```dotenv
UPDATER_UI_ENABLED=true
UPDATER_UI_PREFIX=_updater

UPDATER_UI_AUTH_ENABLED=false
UPDATER_UI_AUTO_PROVISION_ADMIN=true
UPDATER_UI_DEFAULT_EMAIL=admin@admin.com
UPDATER_UI_DEFAULT_PASSWORD=123456
UPDATER_UI_SESSION_TTL=120
UPDATER_UI_LOGIN_MAX_ATTEMPTS=10
UPDATER_UI_LOGIN_DECAY_MINUTES=10

UPDATER_UI_2FA_ENABLED=true
UPDATER_UI_2FA_REQUIRED=false
UPDATER_UI_2FA_ISSUER="Argws Updater"
```

## Segurança mínima implementada

- Senha com `password_hash/password_verify` (bcrypt).
- Sessão própria em `updater_sessions` com cookie `HttpOnly`, `SameSite=Lax`, `Secure` em HTTPS.
- 2FA TOTP sem dependências externas obrigatórias.
- Rate limit por email+IP em `updater_login_attempts`.

## Tabelas SQLite internas

Além de runs/patches/artifacts, o pacote cria de forma idempotente:

- `updater_users`
- `updater_sessions`
- `updater_login_attempts`

## Comandos Artisan

```bash
php artisan system:update:check
php artisan system:update:run --force
php artisan system:update:rollback --force
php artisan system:update:status
```
