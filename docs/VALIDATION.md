# Evidências de validação

## PR #217: origem das evidências e correção de empacotamento

A [PR #217](https://github.com/wkarts/laravel-updater/pull/217) usa a branch `new-ui-16102026` contra `main`. Em 16/10/2026, o head inicial `c79abcf95c9222da9b8ddcbaf738f5af3f01abcf` continha 240 arquivos idênticos aos da candidata local, mas omitia toda a pasta `docs`. O CI [38852486694](https://github.com/wkarts/laravel-updater/actions/runs/38852486694) aprovou frontend/assets e falhou nos dois testes de documentação/OpenAPI por ausência de `docs/openapi.json` (HTTP 500). O lint [38852486631](https://github.com/wkarts/laravel-updater/actions/runs/38852486631) passou.

Esta correção restaura a documentação, a especificação exigida em runtime, as referências visuais e as evidências. Acrescenta um job `package` que gera um arquivo Composer e verifica nele a especificação JSON, os guias de upgrade/segurança e cada asset referenciado pelo manifesto. Os testes de API e navegação existentes continuam exigindo respostas 200; nenhuma asserção foi desabilitada.

Após restaurar `docs/`, foi executada uma nova validação local no clone corrigido `/workspace/scratch/db3f733b96d2/pr217-review`, com autoload regenerado no próprio clone e PHP 8.3.6, Laravel 12.70.0 e PHPUnit 11.5.57. O foco `ApiCompatibilityTest|SpaAuthenticationFlowTest` passou com **16 testes e 217 assertions**; a suíte completa passou com **212 testes e 1.190 assertions**, código de saída 0 e JUnit com zero falhas, erros ou testes ignorados. As evidências preservam os comandos, diretórios executados e caminhos originais: [log focado](evidence/pr217-laravel12-focused.log), [controle focado](evidence/pr217-laravel12-focused.json), [log completo](evidence/pr217-laravel12-full.log) e [controle completo](evidence/pr217-laravel12-full.json). Esses resultados são locais e não afirmam aprovação do CI do head corrigido.

Os resultados abaixo são evidências da entrega local anterior, com seu ambiente e limites. A validação remota da branch corrigida deve ser conferida nos checks do head atual da PR; resultados locais ou do head inicial não substituem os checks desse commit.


## Base e ambiente

Pacote inicial 0.1.219: 38 templates Blade, 93 declarações de rotas (80 nomes únicos), 37 arquivos de teste. Relatório pré-implementação foi lido e confrontado com os arquivos. Referências visuais foram inspecionadas como estilo.

Executado neste ambiente: PHP 8.3.6, Composer 2.7.1, Node 24.19.0, npm 11.9.0, Chromium headless 153 via Playwright. O runtime PHP e extensões foram provisionados para os testes; não existiam inicialmente. A distribuição final não contém esses runtimes de QA.

## Testes PHP executados

| Laravel | PHPUnit / Testbench | Suíte completa final | Saída |
|---|---|---|---|
| 10.50.3 | PHPUnit 10.5.66 / Testbench 8.39 | 212 testes / 1.185 assertions | 0; sem falhas, erros ou ignorados |
| 11.57.0 | PHPUnit 11.5.57 / Testbench 9.18 | 212 testes / 1.190 assertions | 0; sem falhas, erros ou ignorados |
| 12.70.0 | PHPUnit 11.5.57 / Testbench 10.12 | 212 testes / 1.190 assertions | 0; sem falhas, erros ou ignorados |

As três suítes finais executaram os mesmos arquivos PHP congelados. A conclusão foi conferida pelo código de saída, resumo OK, contagem de `--list-tests` e JUnit completo, sem falhas/erros/ignorados. Logs, XML e JSON de controle estão em `evidence/final-full-laravel-{10,11,12}.*`. Rodadas focadas e snapshots anteriores também estão preservados como histórico; não somar execuções sobrepostas. Todos usaram PHP 8.3.6. **PHP 8.2 não foi executado localmente**; permanece na matriz CI do pacote.

Comandos executados: `php vendor/bin/phpunit --log-junit <arquivo>`; `php vendor/bin/phpunit --list-tests`; rodadas focadas nos arquivos de API/notas, webhooks, SchemaCompatibilityResolver, SpaPresentation e SpaAuthenticationFlow; `composer validate --strict --no-check-lock`; `composer audit --format=json`; lint PHP de 159 arquivos em src/routes/config/tests, sem erros de sintaxe.

Cobertura relevante: dry_run_before+apply; action simulate; aplicação sem confirmação; aprovação com senha e simulação concluída; permissões do servidor; RESTAURAR+senha; motivo/ação de migration; criptografia/migração/remoção de segredos; TOTP local/recovery; API Bearer/X-Updater-Token/legado/hash/revogação/escopos/idempotência; notas exatas por fonte/tag; HMAC/replay/limites/SSRF/DNS pinning/timeout/retries/leases; sessão/login/logout/CSRF realmente imposto; manutenção nativa com artisan down --render e resposta antecipada; fidelidade do alvo no plano de simulação; cache de rotas; 22 destinos Vue por controladores reais; assets sem publicação e fallback legado.

A pasta tests/Migration não fazia parte do phpunit.xml inicial; foi incluída. Um teste tinha SQL com o texto literal\x60 em string PHP de aspas simples em vez de backticks reais. A fixture foi corrigida após reprodução nos 3 frameworks, sem alterar o resolver operacional; foi acrescentado caso conservador NOT NULL.

## Build e navegador

`npm run build` executou `vue-tsc --noEmit` e Vite com sucesso. Manifesto e JS/CSS finais estão em resources/dist. Logs e hashes do pacote complementam os artefatos. `git diff --check` passou.

Navegador com aplicação Laravel/Testbench real, prefixo `/reviewed/panel`, autenticação/sessão/CSRF e fixtures identificadas como QA: 24 destinos em 320/768/1280px, total 72 visitas; mais navegação Vue e verificação dos campos de senha, total 74 verificações. Sem erros de página/JavaScript/overflow horizontal. Dados Git, operações de atualização e infraestrutura foram isolados por mocks para evitar efeitos reais.

Interações reais: 12 visitas às abas de configuração nas 3 larguras, menu mobile, criação/exibição única de token e ocultação após recarga, criação/exibição única de segredo webhook e lista persistente, cancelamento em diálogo interno e associação de rótulos. 19 verificações, sem erros JavaScript e sem campos visíveis não rotulados no formulário observado. O resultado completo está em evidence/ui-interactions.json. As capturas em previews usam somente dados fictícios e mostram a primeira área visível.

Regressão adicional de renderização verificou preservação de texto/checkbox após busca, prefixo ao trocar provider e nome ao mudar direção/ação webhook. A falha encontrada no navegador foi corrigida usando estado controlado para nomes/seleções e defaults para campos não controlados. Senhas permanecem fora do estado Vue e são limpas após sucesso.

51 arquivos de teste compõem o pacote final (37 na base).

O fluxo adicional `evidence/ui-update-flow.json` registra 22 verificações aprovadas no bundle final: simulação com run DRY_RUN, progresso e diagnóstico, bloqueio de aplicação sem confirmação no navegador/servidor, logos e fallbacks, 2FA/manutenção/403 em 320 e 1280 px. Nenhuma atualização real foi executada. `evidence/frontend-host-mode.json` valida o contrato do fluxo legado do host, preservando CSRF e a política de simulação e sem enviar seletores ignorados. A galeria reúne 29 pares desktop/mobile (58 imagens), além de 10 capturas integrais das telas principais, incluindo essas fixtures claramente identificadas.

A especificação OpenAPI 3.0.3 com 7 caminhos foi validada integralmente com @apidevtools/swagger-parser; referências e schema aprovados.

## Segurança das dependências

Composer audit no Laravel 12 instalado não encontrou advisories/abandonados. O ambiente Laravel 10.50.3 de compatibilidade reportou 4 advisories do framework; não foram ocultados nem tratados como corrigidos pelo pacote. O suporte às famílias 10/11 foi preservado conforme requisito, mas a política de atualização do framework hospedeiro deve considerar esses avisos. Consulte o JSON de evidência. Não foi realizado pentest externo ou análise de cadeia de fornecimento completa.

## Pendências de homologação

- Atualização e rollback/restauração reais numa cópia representativa de uma aplicação cliente, incluindo banco/arquivos e permissões do servidor.
- Credenciais e releases de repositórios privados reais; GitLab auto-hospedado é tratado como Git genérico, sem consulta de corpo de notas.
- Destino HTTPS externo real, certificados/DNS efetivos e worker de fila externo; transporte/retries foram exercitados com doubles e testes negativos.
- Dropbox/Google Drive/S3/MinIO reais e carga/volume de backups do cliente.
- Revisão assistiva com leitor de tela e teste prolongado de teclado em todos os fluxos. PHP 8.2 não foi executado localmente; sua validação remota depende dos checks do head atual da PR.
- Merge, tag, release e deploy: não executados. A PR #217 já está publicada; consulte os checks do head atual para o estado remoto.

Essas pendências não são apresentadas como validação concluída. Código, assets, documentação e testes foram produzidos; a implantação produtiva requer a homologação operacional acima.

## Revisão visual segundo as imagens fornecidas

A primeira composição visual foi substituída para seguir as cinco referências originais: azul vivo #155bff, fundo frio #f4f7fc, navy #0b1945, sidebar agrupada e ícones SVG. Dashboard, catálogo/notas, integrações, timeline e auditoria foram reorganizados. A comparação em DESIGN-CONCEPTS.html separa referências, capturas atuais e adaptações exigidas pelos contratos reais.

Sobre o bundle final `updater-CFK8JIkP.js`, o build TypeScript/Vite passou e foram repetidas as 74 verificações de navegação/layout, 19 interações, 22 verificações de simulação/branding/acesso, 3 regressões de formulário e o fluxo legado. Mais 6 verificações cobrem seleção de tag/notas e link para tokens nas três larguras; o componente de integrações teve 7 checks de atualização imediata de tabela e preservação do segredo após leitura de metadados. Nenhuma atualização real foi executada.

A revisão visual independente examinou capturas completas em 320 e 1280 px das cinco telas principais; foram corrigidos os gaps materiais encontrados. Isso não afirma equivalência pixel a pixel. A implementação conserva os dados disponíveis e os controles reais, sem preencher indicadores com informações ilustrativas das imagens. O corpo de notas na captura demonstrativa é explicitamente um fixture.

Fontes PHP, rotas, configuração e testes backend permaneceram idênticos à base da matriz final de 212 testes por framework; não foi alegada uma nova execução completa dessa matriz nesta rodada visual. Confira visual-backend-unchanged.json. As automações de navegador foram executadas no harness temporário de QA; os relatórios e capturas estão incluídos na entrega.

O helper de URLs foi corrigido para não transformar nomes de rotas indisponíveis em links somente com query string. O fallback de diagnóstico sob cache antigo e o ocultamento do acesso a tokens quando a rota administrativa não existe têm regressão dedicada de interface; nenhuma rota PHP foi alterada.
