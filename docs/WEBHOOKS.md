# Webhooks do Laravel Updater

O painel **Webhooks** gerencia endpoints de entrada e destinos de saída, segredos, habilitação e histórico. O prefixo dos exemplos é `/_updater`; substitua pelo valor de `updater.ui.prefix`. Nenhum endpoint aceita comandos de shell, seeds, patches SQL ou substituição de comandos pré/pós atualização.

## Administração e segredos

As operações administrativas usam a mesma sessão, CSRF e os mesmos middlewares do painel. Na autenticação interna exigem `settings.manage`; na autenticação da aplicação hospedeira seguem `updater.ui.middleware`, incluindo suas políticas de autorização.

- `GET /webhooks`: tela Vue; `Accept: application/json` sem `X-Updater-SPA` retorna os mesmos dados seguros em JSON.
- `POST /webhooks/endpoints`: cria ou edita com `id`, `name`, `direction`, `enabled`, `events` e `actions`. Em saída exige `url`; em entrada a URL é o endpoint recebido abaixo. A direção não pode ser alterada após a criação. Entradas exigem pelo menos uma ação explicitamente selecionada e saídas exigem pelo menos um evento; para um destino usado somente em testes, selecione `webhook.test`. Não há concessão implícita de ações quando a seleção estiver vazia.
- `POST /webhooks/endpoints/{id}/rotate-secret`: gera um segredo substituto, invalidando imediatamente o anterior.
- `POST /webhooks/endpoints/{id}/test`: agenda `webhook.test`, sem executar atualização.
- `DELETE /webhooks/endpoints/{id}`: remove o segredo e cancela entregas pendentes. O histórico permanece.
- `POST /webhooks/deliveries/{id}/retry`: agenda novamente entrega encerrada, mantendo o mesmo ID de evento para idempotência no destinatário.

Segredos são aleatórios de 256 bits, armazenados com criptografia autenticada do Laravel e `APP_KEY`. **Criação e rotação exibem o segredo somente na resposta daquela operação**, com `Cache-Control: no-store, private`. Não existe recuperação do valor original pela API de leitura. Mantenha o `APP_KEY` estável; sua troca exige recriar/rotacionar os segredos com a chave anterior acessível. Os segredos não entram em logs ou no histórico.

Exemplo de criação de entrada pelo formulário/API autenticada:

```json
{"name":"CI somente simulação","direction":"incoming","actions":["status","check","simulate"],"enabled":true}
```

Exemplo de saída:

```json
{"name":"Monitoramento","direction":"outgoing","url":"https://monitor.suaempresa.com/webhooks/updater","events":["update.completed","update.failed","backup.completed"],"enabled":true}
```

A URL deve usar HTTPS na porta 443, sem credenciais, fragmentos ou parâmetros de consulta. A autenticação do destino utiliza o segredo HMAC. A validação rejeita destinos DNS sem endereço público e qualquer conjunto de respostas que inclua IP reservado/interno.

## Eventos recebidos

`POST /api/webhooks/{endpointId}` é independente de sessão/CSRF e exige HMAC por endpoint. O segredo de webhook não é um token da API e não concede acesso à API administrativa.

Cabeçalhos obrigatórios:

| Cabeçalho | Conteúdo |
| --- | --- |
| `Content-Type` | `application/json` |
| `X-Updater-Timestamp` | Unix timestamp em segundos, exatamente 10 dígitos, tolerância de 300 segundos |
| `X-Updater-Event-Id` | ID único de 16–128 caracteres ASCII: letras, números, `_` ou `-`; UUID é aceito |
| `X-Updater-Signature` | `sha256=` seguido do HMAC-SHA256 hexadecimal |

A mensagem assinada é a concatenação exata `timestamp + "." + event_id + "." + corpo_original`. Não reserialize ou formate o JSON após assinar.

Payloads permitidos:

```json
{"action":"status"}
```

```json
{"action":"check"}
```

```json
{"action":"simulate","profile_id":1}
```

`profile_id` é opcional e só pode ser enviado para `simulate`. A ação também precisa pertencer à lista configurada no endpoint. A simulação força `dry_run=true` no servidor. Atualizações reais continuam pelos fluxos autenticados existentes, com aprovação e confirmação; webhooks não oferecem `apply`.

O corpo deve ter até 65.536 bytes. São permitidas até 60 requisições por IP por minuto e até 30 eventos autenticados por endpoint por minuto. Os contadores são persistidos em SQLite e não dependem do driver de cache. IDs são únicos por endpoint: reenvio idêntico não executa novamente; reutilização com outro corpo retorna conflito. A janela da assinatura também vale para reenvios; gere um timestamp e assinatura novos, mantendo ID e corpo.

Respostas:

| HTTP | Significado |
| --- | --- |
| 202 | Evento autenticado e processado/encaminhado; `run_id` quando houver simulação |
| 200 | Evento já registrado; `duplicate=true`, sem nova execução |
| 401 | Assinatura inválida, vencida, endpoint inexistente ou desabilitado |
| 409 | Mesmo ID com corpo diferente |
| 413 / 415 | Corpo excede o limite / tipo de conteúdo inválido |
| 422 | Ação ou campos não permitidos |
| 429 | Limite de requisições excedido |
| 503 | Falha operacional genérica, registrada como `failed`; nenhuma mensagem interna é retornada |

Uma duplicata de evento anteriormente falho preserva seu resultado; um novo processamento deliberado requer novo ID. `status` e `check` não devolvem configuração, logs ou detalhes internos. Consulte a API própria com token e escopos para dados operacionais.

Exemplo executável com PHP 8.2+ e cURL, depois de definir as variáveis de ambiente `UPDATER_WEBHOOK_URL` e `UPDATER_WEBHOOK_SECRET` localmente:

```php
<?php
$url = getenv('UPDATER_WEBHOOK_URL');
$secret = getenv('UPDATER_WEBHOOK_SECRET');
$body = json_encode(['action' => 'status'], JSON_THROW_ON_ERROR);
$timestamp = (string) time();
$eventId = bin2hex(random_bytes(16));
$signature = 'sha256=' . hash_hmac('sha256', $timestamp.'.'.$eventId.'.'.$body, $secret);
$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Updater-Timestamp: '.$timestamp,
        'X-Updater-Event-Id: '.$eventId,
        'X-Updater-Signature: '.$signature,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($curl);
echo curl_getinfo($curl, CURLINFO_RESPONSE_CODE).PHP_EOL;
echo $response.PHP_EOL;
curl_close($curl);
```

## Eventos enviados

Eventos suportados: `webhook.test`, `update.completed`, `update.failed`, `backup.completed`. A publicação automática observa os registros persistidos de execuções reais e backups. Simulações, rollbacks, execuções de backup e reexecuções de migrations não são apresentados como atualizações concluídas. Backups geram seu próprio `backup.completed` a partir do artefato persistido. Não são enviados eventos anteriores à criação do destino. O observador usa IDs determinísticos e unicidade por destino/evento; uma interrupção entre publicação e registro não duplica a entrega.

Exemplo de corpo:

```json
{"id":"identificador-unico-do-evento","event":"update.completed","occurred_at":"2026-10-16T03:00:00+00:00","data":{"run_id":42,"status":"success"}}
```

`data` aceita somente IDs numéricos de execução/artefato, status enumerado e tipo de backup. Não são transmitidos comandos, URLs de origem, credenciais, caminhos locais, logs, mensagens de exceção ou corpos arbitrários. Cabeçalhos e algoritmo de assinatura são os mesmos dos recebidos. Destinatários devem validar HMAC, janela de tempo e deduplicar o ID de evento.

Cada envio resolve novamente A/AAAA, rejeita todos os endereços não públicos e **fixa o IP validado na conexão cURL**, preservando hostname e verificação TLS. Proxies de ambiente são desativados. Não são seguidos redirecionamentos, nem mesmo para outro destino público. A ausência da extensão PHP cURL interrompe o envio com diagnóstico `transport_unavailable`; nunca é usado cliente alternativo que perca a fixação de DNS.

Timeout de conexão: 4 segundos. Timeout total: 10 segundos. Respostas acima de 64 KiB são abortadas; seus corpos são descartados e nunca persistidos. Qualquer `2xx` confirma entrega. Falhas de transporte, `408`, `429` e `5xx` permitem até cinco tentativas no total, com intervalos de 60, 300, 1.800 e 7.200 segundos. Outros `4xx`, redirecionamentos e rejeições de destino encerram a entrega. A repetição manual exige destino habilitado e revalida DNS e segredo atuais.

O histórico conserva cada tentativa, código HTTP, diagnóstico enumerado, horário e resultado; os filtros `status` e `endpoint_id` se aplicam às listas mais recentes (até 100 entregas/entradas e 500 tentativas relacionadas). Não há retenção automática nesta versão; mantenha cópias e monitore o tamanho do SQLite.

## Processamento e filas

O provider registra `WebhookDispatcher::processPending()` a cada minuto no agendador Laravel. A instalação precisa executar o agendador habitual (`php artisan schedule:run` a cada minuto, ou `schedule:work`). Não é necessário Node ou outro serviço HTTP.

Quando o driver de fila configurado é assíncrono, `DeliverWebhookJob` usa essa fila, com leases persistidos, proteção de concorrência e retomada de jobs interrompidos. Mantenha o worker Laravel ativo. Com `sync`/`null`, o agendador envia diretamente; as requisições do painel apenas enfileiram o teste ou repetição. Backoff e resultado residem no SQLite, independentes das opções de tentativas do worker. Um lease interrompido pode causar nova tentativa: a entrega é **pelo menos uma vez**, exigindo idempotência no destinatário.

As tabelas `updater_webhook_*` são criadas automaticamente no mesmo SQLite operacional do pacote. A aplicação hospedeira não precisa executar migrations adicionais. Os dados existentes não são alterados. Os endpoints administrativos geram registros de auditoria sem segredos; exclusão do endpoint preserva os históricos.
