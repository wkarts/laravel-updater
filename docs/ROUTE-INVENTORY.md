# Inventário de rotas e telas — base 0.1.219

Inspeção direta das rotas, controllers e referências de views. Os arquivos originais são mantidos para compatibilidade de nomes externos; nenhum conteúdo visual antigo é renderizado: o creator do namespace troca o caminho da view por um único invólucro antes da avaliação de Blade. A interface usa os dados permitidos por página em `SpaPresenter`.

Base confirmada: **38 templates**, **93 declarações de rotas nomeadas**, **80 nomes distintos**. As declarações repetidas pertencem às alternativas de autenticação interna e middleware do host; não são 93 destinos diferentes.

## Contagem da candidata atual

Os números anteriores pertencem à base 0.1.219 e permanecem conferíveis em `routes/updater.php` e no mapa original abaixo. A candidata conserva os 38 arquivos originais e adiciona quatro invólucros (`spa`, `spa-error`, `api.docs`, `webhooks.index`): são **42 arquivos Blade físicos**, sem 42 interfaces Blade funcionais. No conjunto atual de arquivos `routes/*.php` existem **105 declarações e 92 nomes distintos**: as 12 rotas adicionais estão discriminadas neste inventário. Os números da base não foram substituídos silenciosamente pelos números da candidata.

## Mapa completo dos 38 templates

| Template / página Vue | Uso no pacote | Tratamento |
| --- | --- | --- |
| backups.restore | src/Http/Controllers/OperationsController.php | Vue: página acessível por controller/middleware |
| dashboard | src/Http/Controllers/UpdaterUiController.php | Vue: página acessível por controller/middleware |
| index | Sem referência interna | Legado sem referência interna ativa; preservado para compatibilidade externa |
| layout-auth | incluído por login.blade.php, twofactor.blade.php | Parcial/layout absorvido por componentes Vue |
| layout | incluído por restore.blade.php, dashboard.blade.php, index.blade.php, profile.blade.php, create.blade.php, edit.blade.php, index.blade.php, show.blade.php, admin-users.blade.php, backups.blade.php, logs.blade.php, migrations-show.blade.php, migrations.blade.php, profiles.blade.php, runs.blade.php, security.blade.php, seeds.blade.php, settings.blade.php, sources.blade.php, updates.blade.php, env.blade.php, index.blade.php, create.blade.php, edit.blade.php, index.blade.php | Parcial/layout absorvido por componentes Vue |
| login | src/Http/Controllers/AuthController.php | Vue: página acessível por controller/middleware |
| maintenance | src/Http/Middleware/SoftMaintenanceMiddleware.php | Vue: página acessível por controller/middleware |
| profile | src/Http/Controllers/AuthController.php | Vue: página acessível por controller/middleware |
| profiles.create | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| profiles.edit | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| profiles.form | incluído por create.blade.php, edit.blade.php | Parcial/layout absorvido por componentes Vue |
| profiles.index | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| runs.show | src/Http/Controllers/OperationsController.php | Vue: página acessível por controller/middleware |
| sections.admin-users | Sem referência interna | Legado; seção pública redireciona para CRUD dedicado; preservado sem execução Blade |
| sections.backups | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| sections.logs | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| sections.migrations-show | src/Http/Controllers/OperationsController.php | Vue: página acessível por controller/middleware |
| sections.migrations | src/Http/Controllers/OperationsController.php | Vue: página acessível por controller/middleware |
| sections.profiles | Sem referência interna | Legado; seção pública redireciona para CRUD dedicado; preservado sem execução Blade |
| sections.runs | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| sections.security | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| sections.seeds | src/Http/Controllers/OperationsController.php | Vue: página acessível por controller/middleware |
| sections.settings | Sem referência interna | Legado; seção pública redireciona para CRUD dedicado; preservado sem execução Blade |
| sections.sources | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| sections.updates | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| settings.backup-upload | incluído por index.blade.php | Parcial/layout absorvido por componentes Vue |
| settings.branding | incluído por index.blade.php | Parcial/layout absorvido por componentes Vue |
| settings.config | incluído por index.blade.php | Parcial/layout absorvido por componentes Vue |
| settings.env | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| settings.index | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| settings.security | incluído por index.blade.php | Parcial/layout absorvido por componentes Vue |
| settings.sources | incluído por index.blade.php | Parcial/layout absorvido por componentes Vue |
| settings.tokens | incluído por index.blade.php | Parcial/layout absorvido por componentes Vue |
| twofactor | src/Http/Controllers/AuthController.php | Vue: página acessível por controller/middleware |
| users.create | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| users.edit | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |
| users.form | incluído por create.blade.php, edit.blade.php | Parcial/layout absorvido por componentes Vue |
| users.index | src/Http/Controllers/ManagerController.php | Vue: página acessível por controller/middleware |

## Rotas públicas e ações preservadas

O prefixo abaixo é relativo a `updater.ui.prefix`; nenhuma URL fixa `/_updater` é introduzida. O nome, método, controller e middleware originais continuam responsáveis pela operação. GET de dados/download não vira tela. As rotas de autenticação interna permanecem no grupo web; quando desativada, o grupo usa `updater.ui.middleware` do host.

| Método | Caminho | Nome | Controller / ação | Tipo |
| --- | --- | --- | --- | --- |
| GET | `/health` | `updater.health` | UpdaterHealthController::__invoke | Dados; público, disponível em manutenção |
| GET | `/assets/updater.css` | `updater.asset.css` | UpdaterUiController::assetCss | Dados / arquivo; contrato preservado |
| GET | `/assets/updater.js` | `updater.asset.js` | UpdaterUiController::assetJs | Dados / arquivo; contrato preservado |
| GET | `/assets/branding/logo` | `updater.branding.logo` | UpdaterUiController::brandingLogo | Dados / arquivo; contrato preservado |
| GET | `/assets/branding/favicon` | `updater.branding.favicon` | UpdaterUiController::brandingFavicon | Dados / arquivo; contrato preservado |
| GET | `/assets/branding/maintenance-logo` | `updater.branding.maintenance_logo` | UpdaterUiController::brandingMaintenanceLogo | Dados / arquivo; contrato preservado |
| POST | `/api/trigger` | `updater.api.trigger` | UpdaterUiController::apiTrigger | Ação preservada |
| GET | `/api/status` | `updater.api.status` | UpdaterUiController::apiStatus | Dados / arquivo; contrato preservado |
| GET | `/api/check` | `updater.api.check` | UpdaterUiController::apiCheck | Dados / arquivo; contrato preservado |
| GET | `/api/runs` | `updater.api.runs` | UpdaterUiController::apiRuns | Dados / arquivo; contrato preservado |
| GET | `/login` | `updater.login` | AuthController::showLogin | Página Vue ou redirecionamento para página Vue |
| POST | `/login` | `updater.login.submit` | AuthController::login | Ação preservada |
| GET | `/2fa` | `updater.2fa` | AuthController::showTwoFactor | Página Vue ou redirecionamento para página Vue |
| POST | `/2fa` | `updater.2fa.verify` | AuthController::verifyTwoFactor | Ação preservada |
| POST | `/logout` | `updater.logout` | AuthController::logout | Ação preservada |
| GET | `/` | `updater.index` | UpdaterUiController::index | Página Vue ou redirecionamento para página Vue |
| GET | `/profile` | `updater.profile` | AuthController::profile | Página Vue ou redirecionamento para página Vue |
| POST | `/profile/password` | `updater.profile.password` | AuthController::updatePassword | Ação preservada |
| POST | `/profile/2fa/enable` | `updater.profile.2fa.enable` | AuthController::enableTwoFactor | Ação preservada |
| POST | `/profile/2fa/disable` | `updater.profile.2fa.disable` | AuthController::disableTwoFactor | Ação preservada |
| POST | `/profile/2fa/recovery/regenerate` | `updater.profile.2fa.recovery.regenerate` | AuthController::regenerateRecoveryCodes | Ação preservada |
| GET | `/status` | `updater.status` | UpdaterUiController::status | Dados / arquivo; contrato preservado |
| POST | `/check` | `updater.check` | UpdaterUiController::check | Ação preservada |
| POST | `/trigger-update` | `updater.trigger.update` | UpdaterUiController::triggerUpdate | Ação preservada |
| POST | `/trigger-rollback` | `updater.trigger.rollback` | UpdaterUiController::triggerRollback | Ação preservada |
| POST | `/maintenance/on` | `updater.maintenance.on` | UpdaterUiController::maintenanceOn | Ação preservada |
| POST | `/maintenance/off` | `updater.maintenance.off` | UpdaterUiController::maintenanceOff | Ação preservada |
| GET | `/runs/{id}` | `updater.runs.show` | OperationsController::runDetails | Página Vue ou redirecionamento para página Vue |
| GET | `/runs/{id}/diagnostics/{format}` | `updater.runs.diagnostics.download` | OperationsController::downloadRunDiagnostics | Dados / arquivo; contrato preservado |
| POST | `/updates/execute` | `updater.updates.execute` | OperationsController::executeUpdate | Ação preservada |
| POST | `/updates/recover` | `updater.updates.recover` | OperationsController::recoverUpdate | Ação preservada |
| POST | `/runs/{id}/approve` | `updater.runs.approve` | OperationsController::approveAndExecute | Ação preservada |
| GET | `/updates/progress/status` | `updater.updates.progress.status` | OperationsController::updateProgressStatus | Dados / arquivo; contrato preservado |
| POST | `/backups/{type}/create` | `updater.backups.create` | OperationsController::backupNow | Ação preservada |
| GET | `/backups/log/download` | `updater.backups.log.download` | OperationsController::downloadUpdaterLog | Dados / arquivo; contrato preservado |
| GET | `/backups/progress/status` | `updater.backups.progress.status` | OperationsController::progressStatus | Dados / arquivo; contrato preservado |
| GET | `/backups/{id}/download` | `updater.backups.download` | OperationsController::downloadBackup | Dados / arquivo; contrato preservado |
| POST | `/backups/{id}/upload` | `updater.backups.upload` | OperationsController::uploadBackup | Ação preservada |
| GET | `/backups/upload/progress/status` | `updater.backups.upload.progress.status` | OperationsController::uploadProgressStatus | Dados / arquivo; contrato preservado |
| POST | `/backups/upload/cancel` | `updater.backups.upload.cancel` | OperationsController::cancelUpload | Ação preservada |
| DELETE | `/backups/{id}` | `updater.backups.delete` | OperationsController::deleteBackup | Ação preservada |
| POST | `/backups/cancel` | `updater.backups.cancel` | OperationsController::cancelBackup | Ação preservada |
| GET | `/backups/{id}/restore` | `updater.backups.restore.form` | OperationsController::showRestoreForm | Página Vue ou redirecionamento para página Vue |
| POST | `/backups/{id}/restore` | `updater.backups.restore` | OperationsController::restoreBackup | Ação preservada |
| GET | `/seeds` | `updater.seeds.index` | OperationsController::seedsIndex | Página Vue ou redirecionamento para página Vue |
| POST | `/seeds/reapply` | `updater.seeds.reapply` | OperationsController::reapplySeed | Ação preservada |
| GET | `/migrations` | `updater.migrations.index` | OperationsController::migrationsAuditIndex | Página Vue ou redirecionamento para página Vue |
| GET | `/migrations/{migration}` | `updater.migrations.show` | OperationsController::migrationAuditShow | Página Vue ou redirecionamento para página Vue |
| POST | `/migrations/reapply` | `updater.migrations.reapply` | OperationsController::reapplyMigration | Ação preservada |
| GET | `/users` | `updater.users.index` | ManagerController::usersIndex | Página Vue ou redirecionamento para página Vue |
| GET | `/users/create` | `updater.users.create` | ManagerController::usersCreate | Página Vue ou redirecionamento para página Vue |
| POST | `/users` | `updater.users.store` | ManagerController::usersStore | Ação preservada |
| GET | `/users/{id}/edit` | `updater.users.edit` | ManagerController::usersEdit | Página Vue ou redirecionamento para página Vue |
| PUT | `/users/{id}` | `updater.users.update` | ManagerController::usersUpdate | Ação preservada |
| DELETE | `/users/{id}` | `updater.users.delete` | ManagerController::usersDelete | Ação preservada |
| POST | `/users/{id}/2fa/reset` | `updater.users.2fa.reset` | ManagerController::usersResetTwoFactor | Ação preservada |
| GET | `/profiles` | `updater.profiles.index` | ManagerController::profilesIndex | Página Vue ou redirecionamento para página Vue |
| GET | `/profiles/create` | `updater.profiles.create` | ManagerController::profilesCreate | Página Vue ou redirecionamento para página Vue |
| POST | `/profiles` | `updater.profiles.store` | ManagerController::profilesStore | Ação preservada |
| GET | `/profiles/{id}/edit` | `updater.profiles.edit` | ManagerController::profilesEdit | Página Vue ou redirecionamento para página Vue |
| PUT | `/profiles/{id}` | `updater.profiles.update` | ManagerController::profilesUpdate | Ação preservada |
| DELETE | `/profiles/{id}` | `updater.profiles.delete` | ManagerController::profilesDelete | Ação preservada |
| POST | `/profiles/{id}/activate` | `updater.profiles.activate` | ManagerController::profilesActivate | Ação preservada |
| GET | `/settings` | `updater.settings.index` | ManagerController::settingsIndex | Página Vue ou redirecionamento para página Vue |
| POST | `/settings/branding` | `updater.settings.branding.save` | ManagerController::saveBranding | Ação preservada |
| DELETE | `/settings/branding/{asset}` | `updater.settings.branding.asset.remove` | ManagerController::removeBrandingAsset | Ação preservada |
| POST | `/settings/branding/reset` | `updater.settings.branding.reset` | ManagerController::resetBranding | Ação preservada |
| POST | `/settings/tokens` | `updater.settings.tokens.create` | ManagerController::createApiToken | Ação preservada |
| DELETE | `/settings/tokens/{id}` | `updater.settings.tokens.revoke` | ManagerController::revokeApiToken | Ação preservada |
| POST | `/settings/backup-upload` | `updater.settings.backup-upload.save` | ManagerController::saveBackupUploadSettings | Ação preservada |
| POST | `/settings/config` | `updater.settings.config.save` | ManagerController::saveUpdaterConfig | Ação preservada |
| GET | `/settings/env` | `updater.settings.env.index` | ManagerController::settingsEnvIndex | Página Vue ou redirecionamento para página Vue |
| POST | `/settings/env` | `updater.settings.env.save` | ManagerController::saveUpdaterEnv | Ação preservada |
| POST | `/sources/save` | `updater.sources.save` | ManagerController::saveSource | Ação preservada |
| POST | `/sources/{id}/activate` | `updater.sources.activate` | ManagerController::activateSource | Ação preservada |
| DELETE | `/sources/{id}` | `updater.sources.delete` | ManagerController::deleteSource | Ação preservada |
| POST | `/sources/test` | `updater.sources.test` | ManagerController::testSourceConnection | Ação preservada |
| POST | `/security/git/maintain` | `updater.security.git.maintain` | ManagerController::gitMaintainNow | Ação preservada |
| POST | `/security/lock/clear` | `updater.security.lock.clear` | ManagerController::forceClearUpdateLock | Ação preservada |
| GET | `/{section}` | `updater.section` | ManagerController::section | Página Vue ou redirecionamento para página Vue |

## Consolidações e ausência de telas

- `index.blade.php` não é retornado por controller: a raiz retorna `dashboard`; o arquivo é preservado sem uso visual.
- `sections/profiles.blade.php` referencia `updater.profiles.save`, inexistente na base; a seção `profiles` já redireciona a `updater.profiles.index`. A página Vue usa `profiles.store`/`profiles.update`, sem avaliar esse parcial.
- `sections/admin-users`, `sections/settings` e suas variantes genéricas são legadas: `ManagerController::section` redireciona aos CRUD/configurações dedicados.
- `sections/migrations` é a tela dedicada ativa; `sections/migrations-show` é seu detalhe. O redirect da seção preserva URLs de navegação legadas.
- Não há recuperação de senha por e-mail nem página de confirmação de senha na base. A troca de senha e 2FA ficam em Perfil. Não são inventados fluxos inexistentes.
- Acesso e 2FA são páginas Vue; perfil, usuários/perfis e configurações ficam condicionados às mesmas rotas e permissões existentes.
- Backups não possuem página de detalhe além do formulário de restauração; metadados e ações ficam na lista e na restauração.
- Configuração de armazenamento, identidade, tokens e runtime era composta por includes da página Settings. Agora é composta por componentes Vue sem rotas artificiais obrigatórias.
- `maintenance` permanece resposta 503 da manutenção soft, com o mesmo shell Vue. Respostas 403/404/503 nas rotas UI mapeadas têm página Vue; APIs/arquivos continuam respostas de dados.
- Referências externas à namespace de views não podem ser identificadas dentro do ZIP; os nomes/arquivos são preservados, e o creator impede execução da UI antiga.

## Apresentação e estados

O contrato é `{page,data,context}`. Dados permitidos por página e campos de credenciais são filtrados no servidor; configuração bruta e opções/erros serializados de runs não são publicados. Tokens novos e recovery codes novos são consumidos uma vez da sessão. GET com `X-Updater-SPA: 1` retorna JSON do mesmo controller e stack; GET normal retorna somente montagem e assets. Redirecionamentos e downloads mantêm os contratos.

Dados, vazio, carregamento, sucesso, erro e sem permissão são estados do componente Vue; tabelas adaptam a distribuição para 320/768/1280 px. O inventário não implica validação visual executada: os resultados reais são documentados no relatório de entrega.

## Adições separadas

- `routes/spa.php`: entrega automática de assets compilados do pacote. Não depende de publicação manual nem Node no host.
- `routes/api-additions.php`: documentação OpenAPI/tags e adições API, preservando as rotas legadas.
- `routes/webhooks.php`: administração/histórico e recepção autenticada de webhooks. As páginas adicionadas entram no mesmo creator/middleware Vue.

## Rotas adicionadas pela candidata

Todos os caminhos são relativos ao prefixo configurado. Assets são públicos; API/OpenAPI exigem o token/escopo documentado; o receptor webhook exige HMAC; documentação/consulta de notas e administração preservam a pilha de autenticação selecionada pelo host e as permissões de servidor correspondentes.

| Método | Caminho | Nome | Handler |
| --- | --- | --- | --- |
| GET | `/assets/spa/{file}` | `updater.spa.asset` | SpaAssets::serve |
| GET | `/api/release-notes` | `updater.api.release-notes` | UpdaterUiController::apiReleaseNotes |
| GET | `/api/openapi.json` | `updater.api.openapi` | UpdaterUiController::apiOpenApi |
| GET | `/release-notes` | `updater.release-notes` | UpdaterUiController::releaseNotes |
| GET | `/api-docs` | `updater.settings.api-docs` | UpdaterUiController::apiDocumentation |
| POST | `/api/webhooks/{endpointId}` | `updater.api.webhooks.receive` | WebhookController::receive |
| GET | `/webhooks` | `updater.settings.webhooks.index` | WebhookController::index |
| POST | `/webhooks/endpoints` | `updater.settings.webhooks.save` | WebhookController::save |
| POST | `/webhooks/endpoints/{id}/rotate-secret` | `updater.settings.webhooks.rotate` | WebhookController::rotate |
| POST | `/webhooks/endpoints/{id}/test` | `updater.settings.webhooks.test` | WebhookController::test |
| DELETE | `/webhooks/endpoints/{id}` | `updater.settings.webhooks.delete` | WebhookController::delete |
| POST | `/webhooks/deliveries/{id}/retry` | `updater.settings.webhooks.retry` | WebhookController::retry |

## Verificação executada da ponte Vue

`php vendor/bin/phpunit --filter 'SpaPresentationTest|SpaAuthenticationFlowTest|NativeMaintenancePresentationTest' --testdox`: **19 testes aprovados em Laravel 10 (221 assertions), 11 e 12 (222 assertions cada) com PHP 8.3.6**. O teste de CSRF força a verificação, incluindo o middleware próprio do Testbench 8; não usa o bypass padrão do ambiente de testes.

A navegação inspecionou 17 destinos principais e 5 detalhes/edições pelos controllers reais, com Kernel/Shell/CodeDriver substituídos por mocks para não executar atualizações nem consultas Git externas. Foram verificados login/sessão/logout, 2FA real por TOTP, negação por permissão, prefixo aninhado, cache compilado de rotas, cache antigo com assets legados, CSS/JS de produção servidos sem publicação, override Blade publicado com referência inválida, resposta 503 de manutenção e ausência de credenciais em HTML/JSON. Esta verificação não substitui a revisão visual no navegador nem homologa atualizações reais em uma aplicação hospedeira.

## Disponibilidade com autenticação da aplicação hospedeira

Quando `updater.ui.auth.enabled=false`, o pacote preserva o subconjunto histórico de rotas registrado no grupo `updater.ui.middleware` do host. Não foram adicionadas rotas CRUD administrativas nesse modo nem removidos checks de administrador. As seções genéricas `profiles`, `settings`, `seeds` e `admin-users`, cujos destinos dedicados não são registrados nesse modo, agora respondem **403 com página Vue** em vez de tentar redirecionar para um nome inexistente e provocar 500. A listagem/auditoria dedicada de migrations e o trigger legado de atualização continuam nos caminhos originais, com prefixo padrão ou personalizado. Os controles da interface só oferecem ações com rota disponível.

## Manutenção nativa e carregamento antes do bootstrap

A resposta de manutenção nativa pré-renderizada usa o mesmo invólucro Vue. O provider acrescenta apenas `<prefix>` e `<prefix>/*` à lista de exceções do middleware nativo, preservando a classe e as regras existentes do host. Isso mantém CSS/JS e acesso ao updater disponíveis sem liberar as demais rotas da aplicação. Autenticação, permissões e CSRF das rotas administrativas continuam executando normalmente. A adaptação usa a propriedade de exclusões já existente no Laravel 10/11/12; não depende da opção CLI `down --except`, ausente nas versões verificadas.

O teste executou `Artisan::call('down', ['--render' => 'laravel-updater::maintenance'])` e `up` em armazenamento temporário isolado, verificou host e prefixo semelhante bloqueados com 503, assets 200 e acesso administrativo redirecionado ao login. Também executou o arquivo `maintenance.php` pré-renderizado em processos PHP independentes, antes de carregar o framework: os assets atravessam a exceção e o host recebe a página 503. As evidências focadas estão em `docs/evidence/spa-final-laravel-{10,11,12}.log`.
