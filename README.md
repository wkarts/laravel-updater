# argws/laravel-updater

Pacote Composer para autoatualização segura, idempotente e reversível de aplicações Laravel 11 e 12.

## Instalação

```bash
composer require argws/laravel-updater
php artisan vendor:publish --tag=updater-config
```

## Configuração

Edite `config/updater.php` para definir branch, backup, snapshot, lock, healthcheck e logs.

## Permissões

- Usuário de execução precisa de permissão de escrita em `storage/`.
- Permissão de execução para `git`, `php`, `composer` e utilitários de backup (`mysqldump`, `pg_dump`).

## Comandos

```bash
php artisan system:update:check
php artisan system:update:status
php artisan system:update:run --force
php artisan system:update:rollback --backup=/path/backup.sql --snapshot=/path/snapshot.tar.gz --revision=<hash>
```

## Atualização manual

1. Executar check.
2. Executar run com `--force`.
3. Validar saúde.

## Atualização automática

Agende via cron chamando um comando customizado que execute `system:update:check` e `system:update:run --force` em janela de manutenção.

## Rollback

Use `system:update:rollback` com confirmação interativa. O pacote restaura banco, snapshot e revisão Git disponível no contexto.

## Troubleshooting

- Verifique `storage/logs/updater.log`.
- Verifique lock em `storage/framework/cache/updater.lock.*`.
- Valide conexão de banco e permissões do sistema.

## Segurança

- Execução apenas via CLI.
- Lock de concorrência.
- Comandos encapsulados em `ShellRunner` com sanitização por `escapeshellarg`.
- Rollback exige confirmação.

## Boas práticas

- Use branch estável.
- Teste em homologação antes de produção.
- Ative backup e snapshot sempre.
- Mantenha seeds idempotentes.
