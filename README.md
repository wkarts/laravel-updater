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
### Publicação automática de config/views (equivalente ao --force)
A partir desta versão, o updater pode sincronizar automaticamente (em execução de console) os arquivos publicados de `config` e `views` para manter o pacote atualizado, com comportamento equivalente ao `vendor:publish --force`.

Controle por `.env`:

```dotenv
UPDATER_AUTO_PUBLISH_ENABLED=true
UPDATER_AUTO_PUBLISH_CONFIG=true
UPDATER_AUTO_PUBLISH_VIEWS=true
```

Se quiser desativar a sincronização automática, ajuste para `false`.


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

# Composer (somente se necessário)
# Em alguns servidores (Supervisor/cron), o PATH pode vir reduzido. O updater tenta normalizar o PATH
# e também detecta composer em locais comuns. Se ainda assim falhar, informe o caminho completo.
UPDATER_COMPOSER_BIN=/usr/bin/composer

# Se você edita arquivos em produção (ex.: config/updater.php), isso pode deixar o repositório "dirty".
# Defina quais caminhos podem ficar sujos sem bloquear a atualização.
UPDATER_GIT_DIRTY_ALLOWLIST="config/updater.php,.env,storage/,bootstrap/cache/"

UPDATER_APP_NAME="Fersoft ERP"      # opcional (default: APP_NAME)
UPDATER_APP_SUFIX_NAME="Homolog"   # opcional
UPDATER_APP_DESC="Sistema em atualização" # opcional

# Whitelabel (opcional) - URLs diretas (sem upload)
UPDATER_BRAND_LOGO_URL="https://seu-dominio.com.br/assets/logo.png"
UPDATER_BRAND_FAVICON_URL="https://seu-dominio.com.br/assets/favicon.ico"
UPDATER_BRAND_PRIMARY_COLOR="#0d6efd"
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


## .env do updater sem sobrescrever seu .env atual

Para reaproveitar o `.env` atual da aplicação (sem perder chaves já existentes), o pacote inclui:

- `stubs/env/updater.default.env.example`
- `stubs/env/updater.production.env.example`
- `stubs/env/updater.homolog.env.example`

E um comando de sincronização não destrutiva:

```bash
# apenas lista chaves UPDATER_* ausentes
php artisan system:update:env-sync --profile=default

# adiciona somente as chaves ausentes no .env atual
php artisan system:update:env-sync --profile=production --write
```

Perfis disponíveis em `--profile`:
- `default`
- `production`
- `homolog`

Esse fluxo mantém o `.env` existente e só acrescenta parâmetros do updater que ainda não existem.


### Seed pós-update (comportamento padrão)
Por padrão, após cada update o updater tenta executar **somente**:

```bash
php artisan db:seed --class=Database\Seeders\ReformaTributariaSeeder --force
```

Se a classe não existir na aplicação host, o updater apenas registra log e segue o pipeline.

As seeds padrão do sistema (`DatabaseSeeder`) **não** rodam por padrão. Para instalação inicial (zero), habilite explicitamente:

```bash
php artisan system:update:run --seed --install-seed-default --force
```

Ou via `.env`:

```dotenv
UPDATER_SEED_ALLOW_DEFAULT_DATABASE_SEEDER=true
```

Também é possível trocar a classe da seed específica:

```dotenv
UPDATER_SEED_REFORMA_TRIBUTARIA_SEEDER="Database\Seeders\ReformaTributariaSeeder"
```

## CI e release

- CI valida matrix real: PHP 8.2/8.3/8.4 + Laravel 10/11/12.
- `release-after-ci.yml` cria tag automática após CI verde na `main` (evento `push`).
- Para evitar erro 403 no push de tag, configure o secret `RELEASE_TOKEN` com permissão de `contents:write` (fallback automático para `GITHUB_TOKEN`).
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

---

### Erro no `composer install`: "The HOME or COMPOSER_HOME environment variable must be set"

Isso acontece quando o updater roda em um ambiente sem variáveis de usuário (muito comum em **cron**, **supervisor**
ou quando o PHP é executado com um usuário sem shell).

✅ A partir desta versão, o updater faz **fallback automático**:
- define `HOME` quando não existe;
- define `COMPOSER_HOME` e `COMPOSER_CACHE_DIR` automaticamente;
- cria os diretórios necessários se não existirem.

Se você quiser forçar valores específicos (opcional), pode exportar antes de rodar o comando:

```bash
export HOME=/home/seu-usuario
export COMPOSER_HOME=/home/seu-usuario/.composer
php artisan system:update:run --force
```

---

## Página de manutenção (503) e Whitelabel

Durante uma atualização, o updater ativa o *maintenance mode* e exibe uma página 503 própria.

### Default (sem .env)

Se você não configurar nada, o updater usa automaticamente a view padrão do pacote:

- `laravel-updater::maintenance`

### Whitelabel por `.env` (URLs diretas)

Você pode apontar logo/ícone por URL (sem upload/Storage):

```dotenv
UPDATER_BRAND_LOGO_URL=https://seu-dominio.com.br/assets/logo.png
UPDATER_BRAND_FAVICON_URL=https://seu-dominio.com.br/assets/favicon.ico
UPDATER_BRAND_PRIMARY_COLOR=#0d6efd
```

### Whitelabel por painel (upload)

No painel `/_updater/settings` você pode configurar:

- textos de manutenção;
- cor primária;
- upload de logo/favicon.

Se existir upload no painel, ele tem prioridade sobre as URLs do `.env`.

## Atualização de arquivos publicados (config/views)

O Laravel não sobrescreve automaticamente arquivos publicados em `config/` e `resources/views/`.
Se você publicou `config/updater.php` ou views e quer atualizar para a versão mais recente do pacote (atenção: isso pode sobrescrever alterações), rode:

```bash
php artisan vendor:publish --tag=updater-config --force
php artisan vendor:publish --tag=updater-views --force
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

> Dica: por padrão, o updater **não bloqueia o CHECK** por working tree dirty (somente leitura).
> Se você quiser bloquear também, configure:

```dotenv
UPDATER_GIT_ALLOW_DIRTY_CHECK=false
```

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

## Migrador idempotente definitivo (`updater:migrate`)

### Por que acontece o erro `SQLSTATE[42S01]` / errno `1050`
Esse erro indica que a migration tentou criar uma tabela/view que já existe no banco (`Base table or view already exists`).
Em ambientes reais isso ocorre por drift entre o banco e a tabela `migrations` (dump restore, merge manual, execução parcial/interrompida, ou banco adiantado).

### Como a reconciliação evita a falha
O comando `updater:migrate` roda **exatamente uma migration por vez** e, quando a falha é classificada como `ALREADY_EXISTS` segura:
1. confere se a migration já está na tabela `migrations`;
2. se não estiver, reconcilia registrando a migration com batch correto;
3. para constraints, valida via `information_schema.TABLE_CONSTRAINTS/KEY_COLUMN_USAGE` (MySQL/MariaDB);
4. continua para a próxima migration.

No modo `strict`, qualquer dúvida de inferência/compatibilidade interrompe com erro orientando intervenção manual.

### Configuração
```dotenv
UPDATER_MIGRATE_IDEMPOTENT=true
UPDATER_MIGRATE_MODE=tolerant
UPDATER_MIGRATE_RETRY_LOCKS=2
UPDATER_MIGRATE_RETRY_SLEEP_BASE=3
UPDATER_MIGRATE_DRY_RUN=false
UPDATER_MIGRATE_LOG_CHANNEL=stack
UPDATER_MIGRATE_RECONCILE_ALREADY_EXISTS=true
UPDATER_MIGRATE_REPORT_PATH="storage/logs/updater-migrate-{timestamp}.log"
```

### Comandos
```bash
# execução real (tolerante)
php artisan updater:migrate --force

# modo estrito
php artisan updater:migrate --force --mode=strict

# dry-run (não executa SQL)
php artisan updater:migrate --force --dry-run
```

### Retry/backoff de lock/deadlock
Para falhas `LOCK_RETRYABLE` (deadlock, lock wait timeout, metadata lock), o updater aplica retry com backoff progressivo
(`retry_sleep_base * 2^(tentativa-1) + (tentativa-1)`), por exemplo base=3: `3s`, `7s`, `15s`.

### Quando usar modo strict
Use `strict` em ambientes novos/limpos, onde drift não é esperado. Em produção com histórico heterogêneo,
`mode=tolerant` tende a reduzir falhas por objetos já existentes sem editar migrations antigas.



### Nota sobre manutenção whitelabel
A view `laravel-updater::maintenance` agora possui fallback seguro: se o armazenamento de branding não estiver acessível no momento do `artisan down --render`, ela renderiza com valores de config (`updater.app.*` e `updater.maintenance.*`) em vez de falhar. Isso evita o cenário em que a aplicação não entra corretamente em manutenção por erro de renderização da view.

### Caso idempotente adicional: DROP INDEX inexistente (MySQL errno 1091)
O migrador idempotente trata `Can't DROP ... check that column/key exists` (`errno 1091`) como drift idempotente no modo tolerante. Nesse caso, valida no `information_schema.STATISTICS` se o índice já está ausente, reconcilia a migration e segue o pipeline sem editar migrations antigas.


### Correção de entrada em manutenção (REQUEST_URI no CLI)
Quando o host dispara erro `Undefined array key "REQUEST_URI"` durante `artisan down --render`, o updater agora injeta variáveis de servidor mínimas (`REQUEST_URI`, `HTTP_HOST`, `SERVER_NAME`, `SERVER_PORT`, `HTTPS`) no comando de manutenção. Com isso a aplicação volta a entrar em manutenção e exibir a view whitelabel do pacote.

### Caso idempotente adicional: tabela inexistente em DROP (SQLSTATE 42S02 / errno 1146)
O classificador considera `42S02/1146` como idempotente **somente** quando o SQL indica operação de remoção segura (`drop table`, `drop index`, `alter table ... drop`, etc.).
Se for consulta/uso normal (`select`, `update`, etc.), permanece `NON_RETRYABLE` para não mascarar erro real.


### Correção definitiva para erro de `ENCRYPTION_KEY` no `route:cache`
Em alguns projetos, providers/helpers consultam `ENCRYPTION_KEY` via `env()/getenv()` durante comandos Artisan. Em execução não-interativa do updater, isso pode falhar mesmo com chave no `.env`.

O `ShellRunner` do pacote agora preserva o ambiente do processo e também faz fallback de leitura do `.env` (chaves `ENCRYPTION_KEY` e `APP_KEY`) para o comando filho. Isso evita quebra no `cache_clear` por falso negativo de chave ausente.

### Tratamento de falha de `route:cache` por nome de rota duplicado
Se o host tiver rotas com nomes duplicados, o Laravel lança `LogicException` no `route:cache`.
O updater agora pode seguir o pipeline sem abortar o update: registra warning e executa `route:clear`.

Controle por `.env`:

```dotenv
UPDATER_CACHE_IGNORE_ROUTE_CACHE_DUPLICATE_NAME=true
```



### Healthcheck em localhost durante update
Para evitar falso negativo em ambientes onde `APP_URL`/healthcheck fica em `localhost`, o updater ignora healthcheck local por padrão.

Controle por `.env`:

```dotenv
UPDATER_HEALTHCHECK_SKIP_LOCALHOST=true
```
