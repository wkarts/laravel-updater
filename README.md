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

### Atualização do config após update do pacote

Por padrão, o `vendor:publish` **não sobrescreve** `config/updater.php` se ele já existir no seu projeto.
Se uma nova versão do pacote trouxer chaves novas no config, você tem 2 opções:

```bash
# atualizar o arquivo de config do projeto com a versão do pacote (sobrescreve)
php artisan vendor:publish --tag=updater-config --force
```

> Dica: se você personaliza `config/updater.php`, recomendo versionar esse arquivo no seu repositório.

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

UPDATER_GIT_DEFAULT_UPDATE_MODE=merge
UPDATER_SOURCES_ALLOW_MULTIPLE=false

# Composer (quando "composer" não está no PATH do processo PHP)
UPDATER_COMPOSER_BIN=/usr/bin/composer

# Se você edita arquivos em produção (ex.: config/updater.php), isso pode deixar o repositório "dirty".
# Defina quais caminhos podem ficar sujos sem bloquear a atualização.
UPDATER_GIT_DIRTY_ALLOWLIST="config/updater.php,.env,storage/,bootstrap/cache/"

UPDATER_APP_NAME=APP_NAME
UPDATER_APP_SUFIX_NAME=APP_SUFIX_NAME
UPDATER_APP_DESC=APP_DESC
UPDATER_SYNC_TOKEN=
```


## Padrão recomendado de `.env` por cenário

> **Importante:** após qualquer alteração no `.env`, execute `php artisan config:clear`.

### 1) Produção (recomendado)

```dotenv
UPDATER_TRIGGER_DRIVER=queue
UPDATER_UI_FORCE_SYNC=false

UPDATER_GIT_PATH=/var/www/sua-aplicacao
UPDATER_GIT_REMOTE=origin
UPDATER_GIT_BRANCH=main
UPDATER_GIT_FF_ONLY=true
UPDATER_GIT_AUTO_INIT=false
UPDATER_GIT_DEFAULT_UPDATE_MODE=ff-only
```

Quando usar:
- aplicação com worker de fila ativo;
- deploy previsível e com menor bloqueio da requisição HTTP.

### 2) Homologação / teste manual pelo painel

```dotenv
UPDATER_TRIGGER_DRIVER=sync
UPDATER_UI_FORCE_SYNC=true

UPDATER_GIT_PATH=/home/seu-usuario/htdocs/seu-projeto
UPDATER_GIT_REMOTE=origin
UPDATER_GIT_BRANCH=main
UPDATER_GIT_AUTO_INIT=true
UPDATER_GIT_REMOTE_URL=https://github.com/wkarts/seu-repo.git
UPDATER_GIT_DEFAULT_UPDATE_MODE=tag
```

Quando usar:
- você quer ver resultado imediato no painel;
- ambiente com menor volume de acesso;
- diretório pode precisar de bootstrap git automático.

### 3) Compatibilidade de variáveis antigas de login

O updater aceita os dois formatos abaixo para rate limit do login UI:

```dotenv
# formato novo (preferencial)
UPDATER_UI_RATE_LIMIT_MAX=10
UPDATER_UI_RATE_LIMIT_WINDOW=600

# formato legado (compatível)
UPDATER_UI_LOGIN_MAX_ATTEMPTS=10
UPDATER_UI_LOGIN_DECAY_MINUTES=10
```

Se ambos existirem, o formato novo (`UPDATER_UI_RATE_LIMIT_*`) tem prioridade.

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


## Troubleshooting (erros mais comuns)

### Erro: `Falha ao aplicar atualização: O updater só pode ser executado via CLI.`

Causa comum:
- execução sincronizada disparada pela UI sem permitir contexto HTTP.

Como resolver:
1. Atualize para a versão mais recente do pacote (esta versão já trata o fluxo UI com `--allow-http`).
2. Confirme `UPDATER_TRIGGER_DRIVER=sync` (homologação) ou `queue` (produção).
3. Rode:

```bash
php artisan config:clear
php artisan cache:clear
```

### Erro: `.git` não é criado automaticamente

Checklist:
1. `UPDATER_GIT_AUTO_INIT=true`
2. `UPDATER_GIT_REMOTE_URL` preenchida e acessível
3. `UPDATER_GIT_PATH` aponta para a pasta correta e com permissão de escrita do usuário do PHP
4. `git` disponível no servidor (`git --version`)

### Não aparecem atualizações disponíveis, mas o teste de conexão da fonte funciona

Isso normalmente indica divergência entre:
- a **fonte ativa no painel** (usada no teste de conexão), e
- `UPDATER_GIT_PATH` local (usado para calcular revisão local/remota).

Valide:
1. pasta de `UPDATER_GIT_PATH` é o mesmo projeto conectado à fonte ativa;
2. branch da fonte ativa bate com `UPDATER_GIT_BRANCH`;
3. repositório local possui upstream correto (`origin/main` por exemplo);
4. execute `php artisan system:update:check` para comparar com o status da UI.

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
