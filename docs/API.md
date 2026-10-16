# API própria do Laravel Updater

A API existente continua nos caminhos `POST /api/trigger` e `GET /api/status`, `/api/check`, `/api/runs`, sob `updater.ui.prefix` (padrão `/_updater`). Os endpoints novos são `GET /api/release-notes?tag=...` e `GET /api/openapi.json`. A documentação administrativa fica em `/api-docs`, com a mesma autenticação e permissão de configurações do painel. Nenhum endpoint captura rotas da aplicação hospedeira.

A especificação [openapi.json](openapi.json) descreve os contratos. O endpoint de especificação substitui `servers` pelo prefixo real da instalação.

## Autenticação e escopos

Envie **um** dos cabeçalhos `Authorization: Bearer <token>` ou `X-Updater-Token: <token>`. O Bearer tem precedência quando ambos são enviados. O `updater.sync_token` legado permanece válido. Tokens emitidos antes de escopos preservam seu acesso existente. Tokens novos usam hash bcrypt; o segredo aparece somente na resposta de criação e não pode ser recuperado.

| Escopo | Operação |
| --- | --- |
| `status:read` | Estado e especificação OpenAPI |
| `check:read` | Consulta do repositório e atualização disponível |
| `runs:read` | Histórico de execuções |
| `releases:read` | Notas da tag exata selecionada |
| `updates:simulate` | Solicitar simulação |
| `updates:apply` | Solicitar aplicação real com confirmação explícita |

Novos tokens recebem somente os quatro escopos de leitura quando nenhum escopo é informado. Na gestão administrativa, `name`, `scopes[]` e `rotate_token_id` opcional permitem criar ou substituir um token, revogando o anterior somente após a criação do novo. A lista apresenta criação, revogação, escopos e último uso disponível; nunca apresenta o hash nem o segredo.

## Atualizações e confirmação

O POST aceita `profile_id`, `source_id`, `update_mode` (`merge`, `ff-only`, `tag`, `full-update`), `target_tag`, `target_branch`, `dry_run`, `dry_run_before` e `confirmation`. O modo `tag` exige tag exata, inclusive sufixos beta/rc. A fonte e o perfil devem existir quando informados. `full-update` exige configuração habilitada. Backups e comandos confiáveis continuam sob controle da pipeline/perfil no servidor.

`action=simulate`, `dry_run=true`, `dry_run_before=true` **ou perfil configurado para simulação** sempre produzem uma simulação. Não há aplicação posterior automática. Para operação real, envie `confirmation: "ATUALIZAR"` e use token com `updates:apply`.

Uma solicitação não pode aprovar uma simulação por token: `approval_run_id` retorna `409 approval_required`. As aprovações que exigem senha continuam na sessão administrativa do painel. Esta API não oferece restauração, execução arbitrária, alteração de comandos ou atalhos para manutenção/2FA.

Por segurança, valores remotos em `pre_update_commands`, `post_update_commands`, `command`, `commands`, `sql_patch` ou `seed` são rejeitados (`422`). Configure comandos/seeds exclusivamente pelos fluxos administrativos confiáveis.

### Adoção de segurança em integrações existentes

Os caminhos, cabeçalhos de autenticação e estrutura de respostas de sucesso existentes foram preservados. Chamadas antigas que disparavam aplicação real sem confirmação agora devem adicionar `confirmation: "ATUALIZAR"`. Chamadas que forneciam SQL, comandos ou seed arbitrário devem mover essa configuração para o perfil confiável. São endurecimentos deliberados para atender à confirmação de atualização e impedir execução remota arbitrária. Tokens antigos continuam válidos; recomenda-se substituí-los por tokens com escopos mínimos.

## Idempotência e limites

`Idempotency-Key` é opcional e recomendado no POST. Deve ter de 8 a 128 caracteres em `[A-Za-z0-9._:-]`. A reserva persistente SQLite é isolada por token e mantida por 24 horas. A mesma chave com as mesmas opções devolve o resultado original com `Idempotency-Replayed: true`. Opções diferentes devolvem `409 idempotency_conflict`; uma reserva sem resposta confirmada devolve `409 request_in_progress`. Nessa situação, consulte execuções e diagnóstico antes de usar nova chave: o executor pode ter criado a execução antes de uma interrupção.

Limites: 60 leituras/minuto por token, 6 solicitações de trigger/minuto por token e 120 tentativas de autenticação/minuto por IP. O excesso retorna `429` e `Retry-After: 60`. Corpo de trigger limitado a 16 KiB. O limitador usa o cache do Laravel; em múltiplos servidores configure um cache compartilhado para que o limite seja global. Os registros de idempotência usam o armazenamento de estado do updater; o pacote pressupõe uma instalação de atualização por aplicação/estado.

## Respostas

Sucesso de trigger mantém HTTP 200 e `{ok, queued, run_id, options, requested_at}`. `queued` indica aceitação pelo dispatcher; consulte as execuções para o resultado final. Leituras mantêm `{ok, data, requested_at}`; histórico inclui `count` e limita `limit` entre 1 e 100 (padrão 20). Campos com segredos são higienizados antes de retornar. Nunca registre cabeçalhos de autenticação nem corpos de requisições.

| HTTP | Situação |
| --- | --- |
| 401 | Token ausente, inválido ou revogado |
| 403 | Escopo insuficiente ou operação desabilitada |
| 404 | Perfil ou fonte inexistente |
| 409 | Aprovação exigida, idempotência em conflito/pendente ou executor indisponível |
| 413 | Payload muito grande |
| 422 | Validação, confirmação ausente ou opção remota proibida |
| 429 | Limite excedido |
| 503 | Estado/repositório temporariamente indisponível |

Erros apresentam `ok: false`, `code` e mensagem segura quando aplicável; detalhes de comandos, credenciais e stack traces não são incluídos.

## Notas por tag

`GET /api/release-notes?tag=v1.2.0-rc.1` aceita `source_id` opcional para a fonte selecionada (ou usa a ativa) e consulta exclusivamente essa tag na API de releases do GitHub ou GitLab.com, sem fallback para a última versão. O retorno contém `tag`, `name`, `published_at`, `prerelease`, `notes`, `url`, `provider` e `availability` (`available`, `unavailable`, `temporarily_unavailable`). Git genérico ou release sem descrição retornam ausência explícita, sem conteúdo inventado. O link é construído a partir do provedor; credenciais embutidas na URL são removidas.

Consultas usam timeout de 8 segundos, conexão de 3 segundos, redirecionamentos desativados e cache de 5 minutos para notas disponíveis ou 1 minuto para ausência/falha. O cache diferencia credenciais por hash. Renderize `notes` como texto ou Markdown sanitizado; nunca como HTML confiável. A lista de tags existente mantém seu limite de 30 e aceita seleção manual exata.

## Exemplos executáveis

Defina `UPDATER_URL` com a URL da instalação incluindo seu prefixo e `UPDATER_TOKEN` com um token de escopos adequados. Não inclua o segredo em URLs.

```bash
curl --fail-with-body "$UPDATER_URL/api/status" \
  -H "Authorization: Bearer $UPDATER_TOKEN" -H 'Accept: application/json'

curl --fail-with-body "$UPDATER_URL/api/check" \
  -H "X-Updater-Token: $UPDATER_TOKEN" -H 'Accept: application/json'

curl --fail-with-body "$UPDATER_URL/api/runs?limit=20" \
  -H "Authorization: Bearer $UPDATER_TOKEN" -H 'Accept: application/json'

curl --fail-with-body --get "$UPDATER_URL/api/release-notes" \
  --data-urlencode 'tag=v1.2.0-rc.1' \
  -H "Authorization: Bearer $UPDATER_TOKEN" -H 'Accept: application/json'

curl --fail-with-body "$UPDATER_URL/api/trigger" \
  -H "Authorization: Bearer $UPDATER_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: deployment-2026-10-15-simulate' \
  --data '{"dry_run":true,"update_mode":"tag","target_tag":"v1.2.0-rc.1"}'

curl --fail-with-body "$UPDATER_URL/api/trigger" \
  -H "Authorization: Bearer $UPDATER_TOKEN" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: deployment-2026-10-15-apply' \
  --data '{"dry_run":false,"confirmation":"ATUALIZAR","update_mode":"merge"}'
```

Ações web continuam a usar sessão, permissões e CSRF. Os endpoints de máquina autenticados por token não usam sessão e não substituem a aprovação com senha.

## Webhooks

O endpoint recebido `POST /api/webhooks/{endpointId}` usa HMAC-SHA256, timestamp e evento único, com somente ações `status`, `check` e `simulate` permitidas por destino. Não usa o token Bearer como substituto de assinatura. Veja [WEBHOOKS.md](WEBHOOKS.md) para configuração, cálculo da assinatura, replay, filas, tentativas e proteção SSRF dos envios. O contrato recebido está incluído no OpenAPI.

## Caminho de execução em fila

Quando o dispatcher selecionar o driver `queue`, os jobs de atualização e rollback implementam o contrato `ShouldQueue` do Laravel. O worker de atualização resolve o perfil confiável pelo ID da execução, carrega os comandos pré/pós do servidor e preserva as opções explícitas de simulação e seed. A etapa Git usa o ID da fonte da própria execução; uma alteração na fonte globalmente ativa não troca o repositório escolhido. Uma fonte/perfil removido antes do processamento causa falha segura, sem usar outro registro como substituto.

Não são incluídos comandos confiáveis nos payloads retornados pela API. A fila síncrona não habilita atualização real dentro de PHP-FPM: as barreiras CLI existentes permanecem. O dispatcher mantém sua estratégia de processo destacado para atualizações reais originadas por HTTP.

Depois de atualizar o pacote, reinicie os workers que já estavam em execução com o procedimento operacional padrão do Laravel (`php artisan queue:restart`, quando mantidos por um supervisor). Configure timeout e `retry_after` da conexão para a duração prevista das operações do ambiente. A verificação automatizada inclui o Bus real do Laravel com Queue fake e resolução do perfil/fonte com kernel simulado; não executa atualização real de produção.
