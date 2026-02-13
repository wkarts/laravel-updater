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

UPDATER_GIT_PATH=/var/www/seu-projeto
UPDATER_GIT_REMOTE=origin
UPDATER_GIT_BRANCH=main
UPDATER_GIT_FF_ONLY=true
# opcional para bootstrap automático quando a pasta ainda não é git
UPDATER_GIT_AUTO_INIT=false
UPDATER_GIT_REMOTE_URL=

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

## Guia rápido de uso de fontes de atualização

### 1) Cadastrar fonte
Na tela **Fontes** (`/_updater/sources`):
- Informe nome, tipo, URL e branch.
- Escolha autenticação (`none`, `token`, `ssh`).

- Para GitHub HTTPS privado, use autenticação com:
  - `auth_mode=token`
  - **Usuário**: `x-access-token` (ou seu usuário Git)
  - **Senha/Token**: seu PAT do GitHub
- Marque **Definir como fonte ativa** se quiser usar imediatamente.

> Você pode cadastrar várias fontes, mas apenas **uma** fica ativa por vez.

### 2) Editar e excluir fonte
Na listagem de fontes:
- Botão **Editar** abre a própria tela com os campos preenchidos.
- Botão **Excluir** remove a fonte após confirmação.

### 3) Testar conexão real
Na tela de fontes, use **Testar conexão real da fonte**.
O teste executa `git ls-remote` para validar acesso e listar versões/tags remotas.

## Guia de atualização (fluxo recomendado)

1. Vá em **Atualizações** (`/_updater/updates`).
2. Selecione o perfil e a fonte.
3. Escolha o modo (`merge`, `ff-only`, `tag`, `full update` se habilitado).
4. Mantenha **Dry-run antes** marcado (padrão).
5. Clique em **Simular (Dry-run)**.
6. Na tela da execução, clique em **Aprovar e executar** e informe a senha de admin.

> O backup FULL é obrigatório antes da atualização real via UI.

## Notificações de atualização (opcional)

As notificações são opcionais e aceitam múltiplos destinatários:

```dotenv
UPDATER_NOTIFY_ENABLED=true
UPDATER_NOTIFY_TO=devops@empresa.com,ti@empresa.com,owner@empresa.com
UPDATER_TRIGGER_DRIVER=auto
```

Também é aceito `;` como separador de e-mails.

### Solução para erro “Diretório atual não é um repositório git válido”

Se a aplicação não estiver na mesma pasta do updater, configure `UPDATER_GIT_PATH` com o diretório real do projeto Laravel versionado em Git.

Exemplo:

```dotenv
UPDATER_GIT_PATH=/home/seu-usuario/htdocs/seu-projeto
UPDATER_GIT_REMOTE=origin
UPDATER_GIT_BRANCH=main
```

Se você quer inicializar um diretório vazio automaticamente (cenário avançado), habilite:

```dotenv
UPDATER_GIT_AUTO_INIT=true
UPDATER_GIT_REMOTE_URL=https://github.com/org/repositorio.git
```

> Recomendado para produção: manter `UPDATER_GIT_AUTO_INIT=false` e usar um diretório já versionado.

> Quando houver uma **fonte ativa** no painel, o updater usa automaticamente a URL/branch dessa fonte para bootstrap git (com `UPDATER_GIT_AUTO_INIT=true`), sem exigir `UPDATER_GIT_REMOTE_URL` manualmente.

Após alterar variáveis do updater em produção, execute também:

```bash
php artisan config:clear
php artisan cache:clear
```
