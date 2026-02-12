# argws/laravel-updater

Pacote Composer para autoatualização segura, idempotente e reversível de aplicações Laravel, agora com camada de **Updater Manager** (painel administrativo, auth independente, branding white-label e API de disparo).

## Compatibilidade

- PHP: **8.2, 8.3, 8.4**
- Laravel/Illuminate: **10, 11, 12**

## Instalação

```bash
composer require argws/laravel-updater
php artisan vendor:publish --tag=updater-config
```

> Os assets do updater são sincronizados automaticamente para `public/vendor/laravel-updater` durante o boot do pacote.
> Assim, você não precisa rodar `php artisan vendor:publish --tag=updater-assets --force` a cada instalação/atualização.

## Rotas principais

- `/_updater`
- `/_updater/login`
- `/_updater/security`
- `/_updater/settings`
- `POST /_updater/api/trigger`

## Variáveis de ambiente (principais)

```dotenv
UPDATER_UI_ENABLED=true
UPDATER_UI_PREFIX=_updater
UPDATER_UI_AUTH_ENABLED=true
UPDATER_UI_AUTO_PROVISION_ADMIN=true
UPDATER_UI_DEFAULT_EMAIL=admin@admin.com
UPDATER_UI_DEFAULT_PASSWORD=123456
UPDATER_UI_DEFAULT_NAME=Admin
UPDATER_UI_SESSION_TTL=120
UPDATER_UI_RATE_LIMIT_MAX=10
UPDATER_UI_RATE_LIMIT_WINDOW=600

UPDATER_UI_2FA_ENABLED=true
UPDATER_UI_2FA_REQUIRED=false
UPDATER_UI_2FA_ISSUER="Argws Updater"

UPDATER_APP_NAME=APP_NAME
UPDATER_APP_SUFIX_NAME=APP_SUFIX_NAME
UPDATER_APP_DESC=APP_DESC
UPDATER_SYNC_TOKEN=
```

## CI e release

- CI valida matrix real: PHP 8.2/8.3/8.4 + Laravel 10/11/12.
- `release-after-ci.yml` cria tag automática após CI verde na `main`.
- Notificação Packagist usa `PACKAGIST_USERNAME` e `PACKAGIST_TOKEN` como secrets.

## Segurança

- Auth interna opcional (`updater.auth`) com SQLite do pacote.
- Sessão própria com cookie HttpOnly + SameSite=Lax + Secure quando HTTPS.
- 2FA TOTP nativo (6 dígitos, janela +-1 step).
- Rate limit de login por email+IP.

## Comandos Artisan

```bash
php artisan system:update:check
php artisan system:update:run --force
php artisan system:update:rollback --force
php artisan system:update:status
```

## Notificação de nova atualização (tag/release)

Habilite no `.env`:

```dotenv
UPDATER_NOTIFY_ENABLED=true
UPDATER_NOTIFY_TO=devops@empresa.com
UPDATER_TRIGGER_DRIVER=auto
```

Agende no `App\Console\Kernel` da aplicação host:

```php
$schedule->command('system:update:notify')->hourly();
```

## Regra de fonte/perfil ativo

Você pode cadastrar várias fontes e perfis, mas apenas **UMA fonte ativa** e **UM perfil ativo** devem ficar selecionados por vez para evitar conflitos.
