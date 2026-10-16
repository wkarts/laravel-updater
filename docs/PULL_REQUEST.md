> Preparação histórica da entrega local. Este documento preserva o escopo e as evidências daquela rodada; branch e status descritos abaixo pertencem à preparação original. A PR remota atual é [#217](https://github.com/wkarts/laravel-updater/pull/217), branch `new-ui-16102026` contra `main`. Sua descrição e seus checks são a referência para a validação remota vigente.

# Pull Request

## Branch

`feat/vue-updater-console` (branch local preparada; PR remota não publicada).

## Título

`feat(updater): reconstrói painel em Vue e adiciona integrações seguras`

## Descrição

### Contexto

A base 0.1.219 usa Blade e permite desacordo entre dry_run_before e aplicação real, além de apresentar segredos armazenados em alguns formulários. A central foi reconstruída integralmente em Vue, preservando os serviços e rotas Laravel, com correção desses controles e novas integrações documentadas.

### Objetivo

Entregar a interface administrativa coerente e responsiva, assets Composer prontos, API própria documentada, webhooks seguros e notas para a fonte/tag exatas.

### Escopo

**Incluído:** todos os destinos de interface inventariados, invólucro Vue, autenticação/permissões/CSRF, forms/ações/estados, segurança de segredos e confirmações, API/OpenAPI, webhooks, release notes, testes, conceitos, galeria e documentação.

**Fora desta execução:** deploy/merge/tag/release remotos e homologação operacional com clientes, storage/HTTPS/fila/repositórios privados reais. Esses itens permanecem pendentes, não são declarados concluídos.

### Decisões técnicas e arquitetura

Vue3/TypeScript/Router/Vite em resources/frontend, manifest e bundle em resources/dist. View creator redireciona as views do pacote para o único shell antes da avaliação Blade; SpaPresenter define o contrato seguro por página. Rotas existentes e controle do servidor são mantidos; não há fallback amplo. Assets são servidos diretamente pelo pacote, inclusive fallback por endpoints legados sob cache antigo.

Claro é padrão, escuro opcional. A composição e os tokens seguem as cinco referências visuais fornecidas: azul vivo, fundo frio, sidebar agrupada, catálogo de versões e contexto de integrações. Templates antigos foram preservados no arquivo por referências externas não inspecionáveis, mas não são a interface operacional. Compactação de perfil mostra ZIP, coerente com o backend que já impunha esse valor.

### Alterações realizadas

- Migração de acesso/2FA/perfil, dashboard, atualizações, execuções, backups/restore, fontes/perfis, migrations/seeds/logs, usuários/segurança/configurações e manutenção/erros para Vue.
- Sistema visual e composições alinhados às referências: dashboard compacto, catálogo/notas, integrações por abas, diálogos de configuração, timeline e auditoria.
- Simulação não se converte em aplicação real; aprovação mantém senha, permissão e estado da simulação; restauração preserva RESTAURAR+senha; migration exige motivo/ação.
- Credenciais cifradas gradualmente e sem leitura pelo navegador; campos vazios preservam e remoção é explícita; TOTP usa QR local.
- API com caminhos/cabeçalhos legados, novos escopos/idempotência/rate limit/rotação e OpenAPI; parâmetros arbitrários bloqueados.
- Webhooks autenticados HMAC, replay/idempotência/allowlist, saídas HTTPS com SSRF/DNS pinning e retries persistentes.
- Notas exatas por fonte/tag, caching e ausência explícita; catálogo com seleção visual e metadados honestos.
- Aprovação aceita DRY_RUN real do kernel, plano preserva alvo sem inventar commit/diff, manutenção nativa carrega assets e preserva autenticação, respostas operacionais higienizam segredos de configuração.

### Arquivos adicionados

- `docs/API.md`
- `docs/DESIGN-CONCEPTS.html`
- `docs/DESIGN.md`
- `docs/PULL_REQUEST.md`
- `docs/RELEASE-NOTES.md`
- `docs/ROUTE-INVENTORY.md`
- `docs/SCREEN-GALLERY.html`
- `docs/SECURITY-UPGRADE.md`
- `docs/UPGRADE-VUE.md`
- `docs/VALIDATION.md`
- `docs/WEBHOOKS.md`
- `docs/design-tokens.json`
- `docs/openapi.json`
- `docs/route-inventory.json`
- `package-lock.json`
- `package.json`
- `resources/dist/.vite/manifest.json`
- `resources/dist/assets/style-Cik7iK-9.css`
- `resources/dist/assets/updater-CFK8JIkP.js`
- `resources/frontend/App.vue`
- `resources/frontend/components/ActionForm.vue`
- `resources/frontend/components/DataTable.vue`
- `resources/frontend/components/Icon.vue`
- `resources/frontend/components/ProgressPanel.vue`
- `resources/frontend/env.d.ts`
- `resources/frontend/main.ts`
- `resources/frontend/pages/AdminPage.vue`
- `resources/frontend/pages/AuthPage.vue`
- `resources/frontend/pages/DashboardPage.vue`
- `resources/frontend/pages/IntegrationsPage.vue`
- `resources/frontend/pages/OperationsPage.vue`
- `resources/frontend/pages/ProfilePage.vue`
- `resources/frontend/pages/SettingsPage.vue`
- `resources/frontend/pages/UpdatesPage.vue`
- `resources/frontend/services/app.ts`
- `resources/frontend/services/format.ts`
- `resources/frontend/styles.css`
- `resources/views/api/docs.blade.php`
- `resources/views/spa-error.blade.php`
- `resources/views/spa.blade.php`
- `resources/views/webhooks/index.blade.php`
- `routes/api-additions.php`
- `routes/spa.php`
- `routes/webhooks.php`
- `src/Http/Controllers/WebhookController.php`
- `src/Http/Middleware/SpaResponseMiddleware.php`
- `src/Jobs/DeliverWebhookJob.php`
- `src/Support/ApiIdempotencyStore.php`
- `src/Support/ApiTokenRegistry.php`
- `src/Support/SecretVault.php`
- `src/Support/SpaAssets.php`
- `src/Support/SpaPresenter.php`
- `src/Webhooks/WebhookDestination.php`
- `src/Webhooks/WebhookDispatcher.php`
- `src/Webhooks/WebhookReceiver.php`
- `src/Webhooks/WebhookSignature.php`
- `src/Webhooks/WebhookStore.php`
- `src/Webhooks/WebhookTransport.php`
- `tests/Feature/ApiCompatibilityTest.php`
- `tests/Feature/DryRunTargetPlanTest.php`
- `tests/Feature/NativeMaintenancePresentationTest.php`
- `tests/Feature/OperationAuthorizationTest.php`
- `tests/Feature/OperationalSecretResponsesTest.php`
- `tests/Feature/QueuedUpdateSelectionTest.php`
- `tests/Feature/SpaAuthenticationFlowTest.php`
- `tests/Feature/SpaPresentationTest.php`
- `tests/Feature/TagNotesTest.php`
- `tests/Feature/UpdateConfirmationTest.php`
- `tests/Feature/WebhookRoutesTest.php`
- `tests/Unit/SecretStorageTest.php`
- `tests/Unit/WebhookDeliveryTest.php`
- `tests/Unit/WebhookSecurityTest.php`
- `tsconfig.json`
- `vite.config.ts`
- `docs/previews/*` — 58 capturas em 29 pares desktop/mobile, mais 10 páginas integrais.
- `docs/evidence/*` — logs, JUnit, JSON de controle e relatórios executados.
- `docs/references/*` — cinco imagens originais, sem alteração, e hashes.
- `docs/visual-before/*`, `docs/design-history/*` — histórico da direção inicial substituída.

### Arquivos modificados

- `.github/workflows/ci.yml`
- `.gitignore`
- `README.md`
- `composer.json`
- `phpunit.xml`
- `src/Http/Controllers/AuthController.php`
- `src/Http/Controllers/ManagerController.php`
- `src/Http/Controllers/OperationsController.php`
- `src/Http/Controllers/UpdaterUiController.php`
- `src/Jobs/RunRollbackJob.php`
- `src/Jobs/RunUpdateJob.php`
- `src/Kernel/UpdaterKernel.php`
- `src/Pipeline/Steps/GitUpdateStep.php`
- `src/Support/AuthStore.php`
- `src/Support/LoggerFactory.php`
- `src/Support/ManagerStore.php`
- `src/Support/ReleaseNotesResolver.php`
- `src/Support/RunDiagnosticsService.php`
- `src/Support/ShellRunner.php`
- `src/Support/StateStore.php`
- `src/Support/Totp.php`
- `src/Support/UiPermission.php`
- `src/UpdaterServiceProvider.php`
- `tests/Migration/SchemaCompatibilityResolverTest.php`

### Arquivos removidos

Nenhum arquivo da base 0.1.219 foi removido.

### Banco de dados

Estado SQLite existente preservado. Tabelas aditivas para endpoints/recebimentos/entregas/observação/rate de webhooks e idempotência API; índices únicos por evento/chave. Metadados de escopos/uso usam runtime settings. Migração gradual de credenciais usa cifragem Laravel com comparação do valor anterior para não sobrescrever rotação concorrente. Sem remoção destrutiva de tabelas. Validar volume/lock/backup na instalação real.

### APIs, contratos e integrações

Quatro rotas legadas continuam; adicionados release-notes/openapi e entrada HMAC. Bearer/X-Updater-Token/token legado permanecem. Aplicação real exige confirmation=ATUALIZAR; comandos/SQL remotos rejeitados. Leia docs/API.md, docs/openapi.json e docs/WEBHOOKS.md para payloads, status, limites, retries e exemplos.

### Dependências e configurações

PHP>=8.2 e Illuminate10/11/12 mantidos. Guzzle^7.5 explícito para Http Client no Laravel 10. Vue/Router/qrcode e toolchain Vite/TS só na manutenção; host usa assets prontos. Webhooks de saída exigem cURL/HTTPS público e scheduler; filas assíncronas usam o worker existente. APP_KEY deve ser preservada. Nenhum segredo real incluído.

### Segurança e privacidade

Allowlist de apresentação, no-store em respostas sensíveis, hash de tokens, criptografia em repouso, remoção/rotação separadas, QR local, sessões/CSRF/permissões, HMAC/timestamp/replay, IPs públicos e DNS fixado sem proxy/redirect, timeouts/limites e logs sem corpos secretos. Leia docs/SECURITY-UPGRADE.md para adoção. Auditoria Composer no framework12 instalado sem avisos; ambiente de compatibilidade10 reporta advisories do framework, preservados na evidência.

### Desempenho e observabilidade

Bundle único comprimível, assets com hash/cache, notas em cache por fonte/tag/credencial, histórico limitado e tabelas com busca/ordenação. Polling desacelera em aba oculta. Outbox de webhooks tem leases e tentativas auditáveis; scheduler observa runs/backups sem alterar contratos do kernel.

### Testes implementados

51 arquivos de teste na entrega contra 37 da base. Novos casos cobrem dry-run/aprovação/restauração/migration, autorização, segredos, API/notas, webhooks e apresentação/sessão/CSRF. Pasta Migration adicionada à suíte; fixture SQL inválida corrigida sem mudar o resolver e caso NOT NULL acrescentado.

### Testes executados

Suítes completas finais com PHP 8.3.6: Laravel 10 — 212 testes / 1.185 assertions; Laravel 11 e 12 — 212 testes / 1.190 assertions cada. Todas com saída 0, JUnit completo e sem falhas, erros ou testes ignorados. Os três ambientes executaram o mesmo snapshot de fontes PHP.

Build Vue/TypeScript/Vite, Composer validate/audit, lint de 159 arquivos PHP, OpenAPI 3.0.3 e git diff --check executados. Navegador no bundle visual final: 74 verificações de páginas/layout, 19 interações, 3 regressões de estado de formulário e 22 verificações adicionais de fluxo/branding/acesso; fluxo do host com CSRF/semântica de aplicação/simulação validado separadamente. Mais 6 verificações de tag/notas/tokens e 7 do componente de integrações passaram; a revisão visual independente das cinco telas principais incluiu capturas completas e correção dos gaps materiais. Sem erros JavaScript ou overflow nos cenários observados. Dados/transportes de QA são identificados como fixtures; nenhuma atualização real de cliente foi executada. Valores de rodadas sobrepostas não são somados como testes distintos. Logs em docs/evidence; detalhes e limites em docs/VALIDATION.md.

### Testes recomendados

Homologar atualização/rollback/restore real, providers privados/nuvem, transporte HTTPS e worker externo, volume/carga e leitor de tela; executar matriz PHP 8.2/CI remota. Nenhum desses foi simulado como produção validada.

### Validação manual

1. Em homologação com backup e APP_KEY preservada, instalar o pacote e limpar/recriar caches.
2. Abrir prefixo padrão e personalizado; testar login/2FA, perfil e permissões com usuários distintos.
3. Conferir fontes/perfis/segredos; simular tag exata e aprovar com senha após conclusão.
4. Conferir histórico, diagnóstico, backups/restore e reaplicação de migration com motivo/ação.
5. Testar tokens/revogação e webhooks assinados; confirmar scheduler/worker e comportamento de retry.
6. Verificar em 320/768/1280px, teclado e erros de sessão/permissão.

### Impactos e compatibilidade

Sem renomear/remover rotas públicas. Endurecimentos intencionais exigem atualização de consumidores que aplicavam sem confirmação, forneciam SQL/comandos/seed remoto ou omitiam motivo/ação da migration. Leitura de credenciais existentes deixa de ser oferecida. GitLab auto-hospedado usa fallback genérico sem corpo de notas. Jobs de atualização/rollback usam ShouldQueue e a execução respeita fonte/perfil solicitados, sem substituir por seleção global. A assinatura handle do job mantém compatibilidade com chamada direta de um argumento. PHP 8.2 não executado localmente; matriz CI mantida. Homologação operacional permanece necessária.

### Procedimento de deploy

1. Backup de código/SQLite/config/APP_KEY; validar precondições e ambiente de homologação.
2. Instalar pacote Composer revisado; nenhum Node/build/publish manual no host.
3. Limpar/recriar caches e reiniciar workers; manter scheduler minuto a minuto.
4. Executar smoke de autenticação, API e simulação; monitorar antes de habilitar aplicação real.

### Procedimento de rollback

Suspender jobs novos, reverter pacote, restaurar o snapshot SQLite/configuração correspondente e preservar APP_KEY. A versão0.1.219 não entende os segredos cifrados novos: rollback somente de código não é suficiente depois da migração. Restaurar estado antigo descarta alterações posteriores; combinar janela e retenção antes de implantar.

### Build, release e distribuição

npm ci + npm run build somente no mantenedor. Manifest/JS/CSS versionados no Composer. CI passa a exigir build e assets reproduzíveis no mesmo workflow que condiciona a publicação automática. ZIP fornecido inclui fontes, assets, evidências e documentação; não inclui vendor/node_modules. Nenhuma publicação remota ou deploy foi executado.

### Documentação

README, UPGRADE-VUE, SECURITY-UPGRADE, RELEASE-NOTES, API/OpenAPI, WEBHOOKS, ROUTE-INVENTORY, DESIGN/tokens, conceitos, galeria e VALIDATION.

### Checklist

- [x] Código, contratos e alterações revisados.
- [x] Assets de produção incluídos e build executado.
- [x] Testes locais e matriz Laravel 10/11/12 executados conforme evidência.
- [x] Sessão/CSRF/permissões, segredos, API/webhooks e responsividade verificados.
- [x] Adoção, breaking changes de segurança e rollback documentados.
- [ ] Homologação operacional em aplicação cliente e infraestrutura externa.
- [ ] PHP 8.2 local/CI remota e revisão assistiva completa.
- [ ] PR/merge/tag/release/deploy remotos.

## Commit sugerido

`feat(updater): reconstrói painel em Vue e adiciona integrações seguras`

## Merge sugerido

`merge: integra feat/vue-updater-console`

## Versão sugerida

`0.2.0`, candidata para revisão e homologação, sem tag/release publicada.
