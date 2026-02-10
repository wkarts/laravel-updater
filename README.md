# argws/laravel-updater

Pacote Composer para autoatualização segura, idempotente e reversível de aplicações Laravel.

## Compatibilidade

Compatibilidade alvo do pacote:

- PHP: **8.2, 8.3, 8.4**
- Laravel/Illuminate: **10, 11, 12**

A matriz de compatibilidade é validada em CI no GitHub Actions.

## Instalação

```bash
composer require argws/laravel-updater
php artisan vendor:publish --tag=updater-config
```

## Versionamento para Composer (VCS)

Para consumo estável no Composer via repositório VCS, publique tags semânticas no formato:

- `vX.Y.Z` (ex.: `v1.0.0`)

O workflow `release.yml` é disparado automaticamente em `push` de tags `v*`.

## Configuração principal

Arquivo `config/updater.php`:

- Git (branch, remote, ff-only)
- Backup (path, keep, compress)
- Snapshot (path, keep)
- SQLite interno (`storage/app/updater/updater.sqlite`)
- UI (`/_updater`, middleware)
- Trigger (`queue|process|exec`)
- Preflight (git limpo, espaço em disco)
- Paths excluídos do snapshot
- Lock (`file|cache`)

### Variáveis de UI/Autenticação/2FA

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

> Observação: os parâmetros acima já estão disponíveis em `config/updater.php` para integração gradual da autenticação própria da UI.

## Comandos Artisan

```bash
php artisan system:update:check
php artisan system:update:check --allow-dirty

php artisan system:update:run --force
php artisan system:update:run --force --seed
php artisan system:update:run --force --seeder=UsersSeeder --seeder=PermissionsSeeder
php artisan system:update:run --force --seeders=UsersSeeder,PermissionsSeeder
php artisan system:update:run --force --sql-path=database/updates
php artisan system:update:run --force --no-backup --no-snapshot --no-build
php artisan system:update:run --force --allow-dirty

php artisan system:update:rollback
php artisan system:update:rollback --force
php artisan system:update:status
```

## UI padrão

A UI fica em `/_updater` (configurável), protegida por middleware padrão `web,auth`.

Funcionalidades:

- Status atual
- Último run
- Histórico de runs
- Botões para:
  - Checar atualizações
  - Disparar atualização
  - Disparar rollback

## Endpoints

- `GET /_updater/` UI
- `GET /_updater/status` JSON
- `POST /_updater/check` JSON
- `POST /_updater/trigger-update` dispara atualização
- `POST /_updater/trigger-rollback` dispara rollback

> Os endpoints **não executam update pesado em request HTTP**. Eles apenas disparam job/processo em segundo plano.

## SQLite interno do componente

O pacote mantém estado próprio em SQLite (PDO), sem depender do banco da aplicação:

- `runs`
- `patches`
- `artifacts`

Isso permite rollback do último run sem parâmetros e deduplicação real de patches SQL por `sha256`.

## Segurança

- Execução via CLI no kernel
- Lock de concorrência (file ou cache)
- Preflight de binários, permissões e espaço
- Verificação de git limpo (com override)
- Backup com tratamento de credenciais:
  - MySQL com `--defaults-extra-file` temporário 0600
  - PostgreSQL com variável de ambiente `PGPASSWORD`

## Troubleshooting

1. Verifique `storage/logs/updater.log`
2. Verifique sqlite em `storage/app/updater/updater.sqlite`
3. Verifique lock ativo em `storage/framework/cache/updater.lock.*`
4. Rode check:

```bash
php artisan system:update:check --allow-dirty
```

## Boas práticas

- Executar primeiro em homologação
- Manter `backup.enabled=true` e `snapshot.enabled=true`
- Manter seeds idempotentes
- Versionar patches SQL com ordem previsível
