# argws/laravel-updater

Pacote Composer para autoatualização segura, idempotente e reversível de aplicações Laravel 11/12.

## Instalação

```bash
composer require argws/laravel-updater
php artisan vendor:publish --tag=updater-config
```

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
