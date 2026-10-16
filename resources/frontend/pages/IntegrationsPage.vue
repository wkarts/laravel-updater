<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { useRoute } from "vue-router";
import { can, jsonGet, route, state } from "../services/app";
import ActionForm from "../components/ActionForm.vue";
import DataTable from "../components/DataTable.vue";
import Icon from "../components/Icon.vue";
import { statusLabel } from "../services/format";

const props = defineProps<{ data: any; page: string }>();
const current = useRoute();
const webhookPage = computed(() => props.page.includes("webhook"));
const serverConfig = computed(() => props.data.data || props.data);
const metadata = ref<Record<string, any>>({});
const metadataError = ref("");
const metadataFields = ["endpoints", "deliveries", "incoming", "attempt_history", "events", "actions", "filters"];
function acceptMetadata(value: any) {
  const safe: Record<string, any> = {};
  for (const field of metadataFields) {
    if (value?.[field] !== undefined) safe[field] = value[field];
  }
  metadata.value = { ...metadata.value, ...safe };
}
watch(serverConfig, (value) => {
  if (webhookPage.value) acceptMetadata(value);
}, { immediate: true, deep: true });
const config = computed(() => webhookPage.value ? { ...serverConfig.value, ...metadata.value } : serverConfig.value);
async function refreshMetadata() {
  metadataError.value = "";
  try {
    const url = route("updater.settings.webhooks.index", {
      status: config.value.filters?.status,
      endpoint_id: config.value.filters?.endpoint_id,
    });
    const response = await jsonGet(url);
    // Only the read contract is reconciled. Newly generated secrets remain inside their ActionForm.
    if (!Array.isArray(response?.data?.endpoints)) throw new Error("Invalid metadata");
    acceptMetadata(response.data);
  } catch {
    metadataError.value = "A alteração foi salva, mas não foi possível atualizar o histórico. Atualize a página para consultar os dados recentes.";
  }
}
function reconcileEndpoint(result: any) {
  if (result?.endpoint) {
    const rows = Array.isArray(config.value.endpoints) ? config.value.endpoints : [];
    metadata.value.endpoints = [...rows.filter((endpoint: any) => endpoint.id !== result.endpoint.id), result.endpoint];
  }
  void refreshMetadata();
}
function removedEndpoint(id: number) {
  metadata.value.endpoints = configuredEndpoints.value.filter((endpoint) => endpoint.id !== id);
  if (contextEndpointId.value === id) contextEndpointId.value = null;
  void refreshMetadata();
}
const canManage = computed(() => can("settings.manage"));
const activeDirection = computed(() => current.query.direction === "incoming" ? "incoming" : "outgoing");
const historyTab = ref<string>(activeDirection.value);
watch(activeDirection, (value) => { historyTab.value = value; });
const configuredEndpoints = computed<any[]>(() => Array.isArray(config.value.endpoints) ? config.value.endpoints : []);
const visibleEndpoints = computed(() => configuredEndpoints.value.filter((endpoint) => endpoint.direction === activeDirection.value));
const incomingEndpoints = computed(() => configuredEndpoints.value.filter((endpoint) => endpoint.direction === "incoming"));
const contextEndpointId = ref<number | null>(null);
watch(incomingEndpoints, (endpoints) => {
  if (!endpoints.some((endpoint) => endpoint.id === contextEndpointId.value)) {
    contextEndpointId.value = endpoints[0]?.id ?? null;
  }
}, { immediate: true });
const contextEndpoint = computed(() => incomingEndpoints.value.find((endpoint) => endpoint.id === contextEndpointId.value) || incomingEndpoints.value[0]);
const baseUrl = computed(() => `${window.location.origin}${state.context.basePath}`);
const tokensUrl = computed(() => route("updater.settings.index", { tab: "tokens" }));
const docsUrl = computed(() => route("updater.settings.api-docs"));
const webhookUrl = (value: string) => route("updater.settings.webhooks.index", { direction: value });
const incomingUrl = (endpoint: any) => `${baseUrl.value}/api/webhooks/${endpoint.id}`;
const copied = ref(false);
async function copyEndpoint() {
  if (!contextEndpoint.value) return;
  try {
    await navigator.clipboard.writeText(incomingUrl(contextEndpoint.value));
    copied.value = true;
  } catch { copied.value = false; }
}

const editor = ref<HTMLDialogElement>();
const editorVersion = ref(0);
const editing = ref<any>(null);
const endpointName = ref("");
const endpointUrl = ref("");
const direction = ref("outgoing");
const selectedEvents = ref<string[]>([]);
const selectedActions = ref<string[]>([]);
const selectionMissing = computed(() => direction.value === "incoming" ? !selectedActions.value.length : !selectedEvents.value.length);
async function openEditor(endpoint: any = null) {
  editing.value = endpoint;
  endpointName.value = endpoint?.name || "";
  endpointUrl.value = endpoint?.url || "";
  direction.value = endpoint?.direction || activeDirection.value;
  selectedEvents.value = [...(endpoint?.events || [])];
  selectedActions.value = [...(endpoint?.actions || [])];
  editorVersion.value++;
  await nextTick();
  editor.value?.showModal();
  editor.value?.querySelector<HTMLInputElement>('input[name="name"]')?.focus();
}
function savedEndpoint(result: any) {
  // Keep the editor open so a newly generated secret remains available for its one-time copy.
  if (result?.endpoint) editing.value = result.endpoint;
  reconcileEndpoint(result);
}
function toggleSelection(value: string, event: Event) {
  const selected = direction.value === "outgoing" ? selectedEvents : selectedActions;
  selected.value = (event.target as HTMLInputElement).checked
    ? [...new Set([...selected.value, value])]
    : selected.value.filter((item) => item !== value);
}
const example = computed(() => `curl -H "Authorization: Bearer $UPDATER_TOKEN" \\\n  "${baseUrl.value}/api/status"`);
const apiEndpoints = computed(() => Object.entries(props.data.endpoints || {}).flatMap(([path, methods]: [string, any]) =>
  Object.entries(methods).filter(([method]) => ["get", "post", "put", "delete", "patch"].includes(method))
    .map(([method, operation]: [string, any]) => ({ path, method: method.toUpperCase(), ...operation })),
));
const retryable = (delivery: any) => ["failed", "delivered", "cancelled"].includes(delivery.status)
  && configuredEndpoints.value.some((endpoint) => endpoint.id === Number(delivery.endpoint_id) && endpoint.enabled);
</script>

<template>
  <div class="stack integrations-page">
    <p v-if="metadataError" class="notice warning" role="status">{{ metadataError }}</p>
    <div class="integration-toolbar">
      <a class="button subtle" :href="docsUrl"><Icon name="book" :size="17" />Documentação da API</a>
      <a v-if="canManage && tokensUrl" class="button subtle" :href="tokensUrl"><Icon name="key" :size="17" />Gerenciar tokens</a>
    </div>

    <nav class="integration-tabs" aria-label="Integrações">
      <a :href="docsUrl" :class="{ active: !webhookPage }" :aria-current="!webhookPage ? 'page' : undefined"><Icon name="code" :size="19" />API</a>
      <a :href="webhookUrl('outgoing')" :class="{ active: webhookPage && activeDirection === 'outgoing' }" :aria-current="webhookPage && activeDirection === 'outgoing' ? 'page' : undefined"><Icon name="link" :size="19" />Webhooks de saída</a>
      <a :href="webhookUrl('incoming')" :class="{ active: webhookPage && activeDirection === 'incoming' }" :aria-current="webhookPage && activeDirection === 'incoming' ? 'page' : undefined"><Icon name="arrow-right" :size="19" />Webhook de entrada</a>
    </nav>

    <div class="integration-layout">
      <div class="stack integration-main">
        <template v-if="webhookPage">
          <section class="panel">
            <div class="panel-heading integration-heading">
              <div>
                <h2>{{ activeDirection === 'outgoing' ? 'Gerenciar destinos e eventos' : 'Gerenciar endpoints de entrada' }}</h2>
                <p class="section-description">{{ activeDirection === 'outgoing' ? 'Configure destinos para receber eventos do Updater Manager.' : 'Autorize sistemas externos a consultar ou solicitar simulações.' }}</p>
              </div>
              <button v-if="canManage" class="button primary" type="button" @click="openEditor()"><Icon name="plus" :size="17" />Novo webhook</button>
            </div>
            <DataTable
              :rows="visibleEndpoints"
              :search="visibleEndpoints.length > 4"
              :empty="activeDirection === 'outgoing' ? 'Nenhum destino de saída configurado.' : 'Nenhum endpoint de entrada configurado.'"
              :columns="[
                { key: 'url', label: activeDirection === 'incoming' ? 'Endpoint de entrada' : 'URL de destino' },
                { key: 'subscriptions', label: activeDirection === 'incoming' ? 'Ações permitidas' : 'Eventos inscritos' },
                { key: 'secret_configured', label: 'Segredo de assinatura' },
                { key: 'enabled', label: 'Ativo' },
              ]"
            >
              <template #url="{ row }"><strong class="endpoint-name">{{ row.name }}</strong><span class="destination-url" :title="row.direction === 'incoming' ? incomingUrl(row) : row.url">{{ row.direction === 'incoming' ? incomingUrl(row) : row.url }}</span></template>
              <template #subscriptions="{ row }"><div class="event-chips"><span v-for="event in row.direction === 'incoming' ? row.actions : row.events" :key="event" class="event-chip">{{ event }}</span></div></template>
              <template #secret_configured="{ row }"><span class="secret-state"><Icon name="shield" :size="15" />{{ row.secret_configured ? 'Configurado' : 'Ausente' }}</span></template>
              <template #enabled="{ row }">
                <ActionForm v-if="canManage" :action="route('updater.settings.webhooks.save')" @success="reconcileEndpoint">
                  <input type="hidden" name="id" :value="row.id" /><input type="hidden" name="name" :value="row.name" /><input type="hidden" name="direction" :value="row.direction" /><input v-if="row.direction === 'outgoing'" type="hidden" name="url" :value="row.url" />
                  <input v-for="value in row.direction === 'incoming' ? row.actions : row.events" :key="value" type="hidden" :name="row.direction === 'incoming' ? 'actions[]' : 'events[]'" :value="value" />
                  <button class="endpoint-switch" :class="{ enabled: row.enabled }" type="submit" name="enabled" :value="row.enabled ? '0' : '1'" role="switch" :aria-checked="!!row.enabled" :aria-label="`${row.enabled ? 'Desabilitar' : 'Habilitar'} ${row.name}`"><span></span></button>
                </ActionForm>
                <span v-else class="badge" :class="{ success: row.enabled }">{{ row.enabled ? 'Sim' : 'Não' }}</span>
              </template>
              <template #actions="{ row }">
                <ActionForm v-if="canManage && row.direction === 'outgoing' && row.enabled" :action="route('updater.settings.webhooks.test', { id: row.id })" @success="refreshMetadata"><button class="button subtle">Testar entrega</button></ActionForm>
                <details v-if="canManage" class="endpoint-menu">
                  <summary :aria-label="`Mais ações para ${row.name}`"><Icon name="chevron-down" :size="17" /></summary>
                  <div class="endpoint-menu-content">
                    <button class="button subtle" type="button" @click="openEditor(row)">Editar configuração</button>
                    <ActionForm :action="route('updater.settings.webhooks.rotate', { id: row.id })" @success="refreshMetadata" confirm="Rotacionar o segredo? Atualize o sistema conectado para evitar falhas de assinatura."><button class="button subtle">Rotacionar segredo</button></ActionForm>
                    <ActionForm :action="route('updater.settings.webhooks.delete', { id: row.id })" @success="removedEndpoint(row.id)" method="DELETE" confirm="Excluir esta integração de webhook?"><button class="button danger">Excluir webhook</button></ActionForm>
                  </div>
                </details>
              </template>
            </DataTable>
            <button v-if="canManage && visibleEndpoints.length" class="text-button add-destination" type="button" @click="openEditor()"><Icon name="plus" :size="16" />Adicionar {{ activeDirection === 'incoming' ? 'endpoint' : 'destino' }}</button>
          </section>

          <section class="panel">
            <div class="panel-heading history-heading"><div><h2>{{ activeDirection === 'incoming' ? 'Eventos recebidos' : 'Entregas recentes' }}</h2><p class="section-description">Consulte resultados, tentativas e diagnósticos registrados pelo servidor.</p></div><span class="badge">Histórico auditável</span></div>
            <form class="filter-bar" method="GET" :action="route('updater.settings.webhooks.index')">
              <input type="hidden" name="direction" :value="activeDirection" />
              <label>Estado<select name="status" :value="config.filters?.status || ''"><option value="">Todos</option><option v-for="status in ['pending', 'processing', 'retry', 'queued', 'delivered', 'accepted', 'failed', 'cancelled']" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
              <label>Integração<select name="endpoint_id" :value="config.filters?.endpoint_id || ''"><option value="">Todas</option><option v-for="endpoint in configuredEndpoints" :key="endpoint.id" :value="endpoint.id">{{ endpoint.name }}</option></select></label>
              <button class="button subtle">Filtrar</button>
            </form>
            <div class="tabs history-tabs" role="tablist" aria-label="Direção do histórico">
              <button type="button" role="tab" :aria-selected="historyTab === 'outgoing'" @click="historyTab = 'outgoing'">Entregas enviadas</button><button type="button" role="tab" :aria-selected="historyTab === 'incoming'" @click="historyTab = 'incoming'">Eventos recebidos</button><button type="button" role="tab" :aria-selected="historyTab === 'attempts'" @click="historyTab = 'attempts'">Tentativas</button>
            </div>
            <DataTable v-if="historyTab === 'outgoing'" :rows="config.deliveries || []" :search="(config.deliveries || []).length > 4" :columns="[{ key: 'created_at', label: 'Data e hora' }, { key: 'event_type', label: 'Evento' }, { key: 'status', label: 'Resultado' }, { key: 'attempts', label: 'Tentativas' }, { key: 'response_code', label: 'HTTP' }, { key: 'error_code', label: 'Diagnóstico' }, { key: 'next_attempt_at', label: 'Próxima tentativa' }]">
              <template #actions="{ row }"><ActionForm v-if="canManage && retryable(row)" :action="route('updater.settings.webhooks.retry', { id: row.id })" @success="refreshMetadata" confirm="Repetir esta entrega de webhook? A nova tentativa manterá o identificador do evento."><button class="button subtle">Reenviar</button></ActionForm><span v-else class="muted">{{ ['pending', 'queued', 'processing', 'retry'].includes(row.status) ? 'Em andamento' : 'Destino indisponível' }}</span></template>
            </DataTable>
            <DataTable v-else-if="historyTab === 'incoming'" :rows="config.incoming || []" :columns="[{ key: 'received_at', label: 'Recebido em' }, { key: 'event_id', label: 'Evento' }, { key: 'action', label: 'Ação' }, { key: 'status', label: 'Estado' }, { key: 'run_id', label: 'Execução' }]" />
            <DataTable v-else :rows="config.attempt_history || []" :columns="[{ key: 'started_at', label: 'Início' }, { key: 'delivery_id', label: 'Entrega' }, { key: 'attempt', label: 'Tentativa' }, { key: 'status', label: 'Estado' }, { key: 'response_code', label: 'HTTP' }, { key: 'error_code', label: 'Diagnóstico' }, { key: 'finished_at', label: 'Conclusão' }]" />
          </section>
        </template>

        <template v-else>
          <section class="panel">
            <div class="panel-heading"><div><h2>API do Laravel Updater</h2><p class="section-description">Acesse consultas e operações permitidas com autenticação e escopos.</p></div><span class="badge">REST</span></div>
            <label>URL base<code class="api-base-url">{{ baseUrl }}/api</code></label>
            <h3 class="api-example-heading">Começar com uma consulta</h3><pre>{{ example }}</pre><p class="field-hint">Defina UPDATER_TOKEN no seu ambiente. Nunca inclua segredos em URLs ou repositórios.</p>
          </section>
          <section class="panel"><div class="panel-heading"><h2>Endpoints implementados</h2><span class="badge">{{ apiEndpoints.length }} contratos</span></div>
            <div class="stack"><article v-for="endpoint in apiEndpoints" :key="endpoint.method + endpoint.path" class="api-endpoint"><h3><span class="badge">{{ endpoint.method }}</span><code>{{ endpoint.path }}</code></h3><p class="muted">{{ endpoint.summary || endpoint.description }}</p><details><summary>Payloads, respostas e escopos</summary><pre>{{ JSON.stringify(endpoint, null, 2) }}</pre></details></article></div>
          </section>
          <section class="panel"><h2>Contratos OpenAPI</h2><p class="muted">Payloads, respostas, erros, limites, idempotência e escopos correspondem às rotas implementadas.</p><details><summary>Especificação completa</summary><pre>{{ JSON.stringify(data.specification || {}, null, 2) }}</pre></details><p v-if="data.openapiUrl" class="field-hint">Endpoint OpenAPI autenticado: <code class="endpoint-path">{{ data.openapiUrl }}</code></p></section>
        </template>
      </div>

      <aside class="stack integration-context" aria-label="Documentação e contexto das integrações">
        <section class="panel documentation-card"><div class="context-title"><span class="context-icon"><Icon name="book" :size="25" /></span><h2>Documentação da API</h2></div><p class="muted">Saiba como integrar, autenticar e utilizar os recursos da API e dos webhooks do Updater Manager.</p><a class="button subtle full-width" :href="docsUrl">Acessar documentação<Icon name="arrow-right" :size="16" /></a></section>
        <section v-if="webhookPage" class="panel"><div class="context-title"><span class="context-icon muted-icon"><Icon name="arrow-right" :size="24" /></span><h2>Endpoint de entrada</h2></div><p class="muted">Receba eventos de sistemas externos com assinatura e ações explicitamente autorizadas.</p>
          <template v-if="contextEndpoint"><label v-if="incomingEndpoints.length > 1">Integração<select v-model="contextEndpointId"><option v-for="endpoint in incomingEndpoints" :key="endpoint.id" :value="endpoint.id">{{ endpoint.name }}</option></select></label><div class="endpoint-reference"><strong>URL de recebimento</strong><div><code>{{ incomingUrl(contextEndpoint) }}</code><button class="button subtle icon-only" type="button" aria-label="Copiar URL de recebimento" @click="copyEndpoint"><Icon name="copy" :size="16" /></button></div></div><p v-if="copied" class="field-hint" role="status">URL copiada.</p><div class="endpoint-reference"><strong>Assinatura HMAC</strong><div><span class="secret-state"><Icon name="shield" :size="15" />{{ contextEndpoint.secret_configured ? 'Segredo configurado' : 'Sem segredo' }}</span></div></div></template>
          <template v-else><p class="notice">Nenhum endpoint de entrada configurado.</p><a class="button subtle full-width" :href="webhookUrl('incoming')">Configurar entrada<Icon name="arrow-right" :size="16" /></a></template>
          <div class="context-divider"><h3>Idempotência</h3><p class="muted">Eventos duplicados são identificados pelo ID único e não executam a ação novamente.</p><h3>HMAC-SHA256</h3><p class="muted">Timestamp, assinatura e identificador são validados antes do processamento. Entradas permitem consultas e simulações; atualizações reais seguem a aprovação do painel.</p></div>
        </section>
        <section v-else class="panel"><div class="context-title"><span class="context-icon"><Icon name="key" :size="23" /></span><h2>Autenticação e tokens</h2></div><p class="muted">Envie <code>Authorization: Bearer &lt;token&gt;</code> ou <code>X-Updater-Token: &lt;token&gt;</code>. O token legado segue a configuração do servidor.</p><a v-if="canManage && tokensUrl" class="button subtle full-width" :href="tokensUrl">Gerenciar tokens e escopos<Icon name="arrow-right" :size="16" /></a><div class="context-divider"><h3>Uma simulação não aplica mudanças</h3><p class="muted">Operações reais exigem os escopos, aprovações e confirmações do servidor. A API não aceita comandos de shell remotos.</p></div></section>
      </aside>
    </div>

    <dialog v-if="webhookPage" ref="editor" class="integration-dialog" aria-labelledby="webhook-editor-title">
      <div class="panel-heading"><div><h2 id="webhook-editor-title">{{ editing ? 'Editar webhook' : 'Novo webhook' }}</h2><p class="section-description">Configure o destino e as permissões desta integração.</p></div><button class="button subtle icon-only" type="button" aria-label="Fechar configuração de webhook" @click="editor?.close()"><Icon name="close" :size="19" /></button></div>
      <ActionForm :key="editorVersion" :action="route('updater.settings.webhooks.save')" @success="savedEndpoint">
        <input v-if="editing" type="hidden" name="id" :value="editing.id" />
        <label>Nome da integração<input v-model="endpointName" name="name" required maxlength="100" /></label>
        <label>Direção<select v-model="direction" name="direction" :disabled="!!editing"><option value="outgoing">Enviado pela ferramenta</option><option value="incoming">Recebido pela ferramenta</option></select></label><input v-if="editing" name="direction" type="hidden" :value="editing.direction" />
        <label v-if="direction === 'outgoing'">Destino HTTPS<input v-model="endpointUrl" name="url" type="url" required placeholder="https://seu-sistema.com/webhooks/updater" /><small>Use HTTPS na porta 443, sem parâmetros de URL. Destinos internos e redirecionamentos são bloqueados.</small></label>
        <div><h3>{{ direction === 'outgoing' ? 'Eventos enviados' : 'Ações permitidas' }}</h3><div class="checkbox-list"><label v-for="value in direction === 'outgoing' ? config.events || [] : config.actions || []" :key="value" class="toggle"><input type="checkbox" :name="direction === 'outgoing' ? 'events[]' : 'actions[]'" :value="value" :checked="(direction === 'outgoing' ? selectedEvents : selectedActions).includes(value)" @change="toggleSelection(value, $event)" /><span>{{ value }}</span></label></div></div>
        <input type="hidden" name="enabled" value="0" /><label class="toggle"><input name="enabled" value="1" type="checkbox" :checked="editing ? editing.enabled : true" /><span>Integração habilitada.</span></label>
        <div class="notice"><strong>O segredo aparece uma única vez.</strong><p>Ao criar ou rotacionar, copie o segredo para o sistema conectado. Segredos armazenados não podem ser consultados novamente.</p></div>
        <p v-if="selectionMissing" class="field-hint" role="status">Selecione ao menos {{ direction === 'incoming' ? 'uma ação permitida' : 'um evento para enviar' }}.</p>
        <div class="actions"><button class="button primary" :disabled="selectionMissing">{{ editing ? 'Salvar alterações' : 'Criar webhook' }}</button><button class="button subtle" type="button" @click="editor?.close()">Fechar</button></div>
      </ActionForm>
    </dialog>
  </div>
</template>

<style scoped>
.integrations-page { gap: 18px; }
.integration-toolbar { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; }
.integration-tabs { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); border: 1px solid var(--line); border-radius: 8px; background: var(--surface); }
.integration-tabs a { display: flex; align-items: center; justify-content: center; gap: 10px; min-height: 53px; padding: 13px 12px; color: var(--muted); border-top: 2px solid transparent; border-bottom: 2px solid transparent; text-decoration: none; font-size: 13px; }
.integration-tabs a.active { color: var(--primary); border-top-color: var(--primary); border-bottom-color: var(--primary); background: var(--primary-soft); font-weight: 650; }
.integration-layout { display: grid; grid-template-columns: minmax(0,1fr) 280px; gap: 18px; align-items: start; }
.integration-main, .integration-context { min-width: 0; gap: 18px; }
.integration-heading { align-items: flex-start; }
.section-description { color: var(--muted); font-size: 12px; line-height: 1.65; margin: 7px 0 0; }
.integration-heading > .button { flex-shrink: 0; }
.endpoint-name { display: block; font-weight: 550; font-size: 12px; margin-bottom: 4px; }
.destination-url { display: block; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 11px; color: var(--muted); }
.event-chips { display: flex; flex-wrap: wrap; gap: 4px; }
.event-chip { font-size: 10px; padding: 4px 6px; border-radius: 4px; color: var(--ink); background: var(--soft); white-space: nowrap; }
.secret-state { display: inline-flex; align-items: center; gap: 5px; color: var(--muted); font-size: 11px; white-space: nowrap; }
.endpoint-switch { border: 0; width: 32px; height: 19px; background: var(--line); border-radius: 14px; padding: 2px; display: flex; align-items: center; cursor: pointer; }
.endpoint-switch > span { display: block; width: 15px; height: 15px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px #0002; }
.endpoint-switch.enabled { background: #00a979; justify-content: flex-end; }
.endpoint-menu { min-width: 28px; }
.endpoint-menu > summary { display: flex; justify-content: center; align-items: center; border: 1px solid var(--line); border-radius: 5px; padding: 7px; cursor: pointer; list-style: none; }
.endpoint-menu > summary::-webkit-details-marker { display: none; }
.endpoint-menu-content { display: flex; flex-direction: column; align-items: stretch; gap: 7px; margin-top: 9px; min-width: 185px; max-width: 250px; }
.endpoint-menu-content .button { width: 100%; justify-content: flex-start; }
.add-destination { display: inline-flex; align-items: center; gap: 6px; margin-top: 17px; }
.history-tabs { margin-top: 17px; margin-bottom: 4px; }
.context-title { display: flex; align-items: center; gap: 11px; margin-bottom: 15px; }
.context-title h2 { margin: 0; font-size: 14px; line-height: 1.4; }
.context-icon { display: inline-flex; flex: 0 0 38px; width: 38px; height: 38px; align-items: center; justify-content: center; color: var(--primary); background: var(--primary-soft); border-radius: 7px; }
.muted-icon { color: var(--muted); background: var(--soft); }
.integration-context .muted { font-size: 12px; line-height: 1.75; }
.full-width { width: 100%; justify-content: center; margin-top: 9px; }
.context-divider { border-top: 1px solid var(--line); padding-top: 16px; margin-top: 20px; }
.context-divider h3 { font-size: 12px; margin-bottom: 7px; }
.endpoint-reference { border: 1px solid var(--line); border-radius: 7px; overflow: hidden; margin-top: 16px; }
.endpoint-reference > strong { display: block; padding: 10px 11px; font-size: 11px; background: var(--soft); border-bottom: 1px solid var(--line); }
.endpoint-reference > div { display: flex; gap: 7px; align-items: center; padding: 10px; }
.endpoint-reference code { font-size: 10px; line-height: 1.6; overflow-wrap: anywhere; min-width: 0; }
.icon-only { padding: 7px; flex-shrink: 0; }
.api-base-url { display: block; padding: 12px; border: 1px solid var(--line); background: var(--soft); border-radius: 6px; font-size: 12px; overflow-wrap: anywhere; font-weight: 400; }
.api-example-heading { margin-top: 24px; }
.api-endpoint { padding-bottom: 16px; border-bottom: 1px solid var(--line); }
.api-endpoint:last-child { padding-bottom: 0; border: 0; }
.api-endpoint h3 { display: flex; align-items: center; gap: 9px; font-size: 13px; }
.api-endpoint h3 code { overflow-wrap: anywhere; }
.integration-dialog { width: min(610px,calc(100vw - 32px)); max-height: calc(100dvh - 40px); overflow-y: auto; border: 1px solid var(--line); background: var(--surface); color: var(--ink); border-radius: 9px; padding: 25px; box-shadow: 0 20px 70px #10234833; }
.integration-dialog::backdrop { background: #172a4d66; }
.integration-dialog h2 { margin: 0; }
.integration-dialog :deep(.action-form) { margin-top: 15px; }
.integration-main :deep(.row-actions) { gap: 7px; }
.integration-main :deep(.row-actions .button) { white-space: nowrap; font-size: 11px; }
.integration-main :deep(td) { vertical-align: middle; }
.integration-main :deep(th) { font-size: 11px; }
.integration-main :deep(.notice) { overflow-wrap: anywhere; }
@media (max-width:1100px) { .integration-layout { grid-template-columns: minmax(0,1fr); } .integration-context { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); align-items: start; } }
@media (max-width:480px) { .history-heading { flex-wrap: wrap; align-items: flex-start; } .history-heading > div { flex: 1 1 100%; } .history-heading > .badge { flex: 0 0 auto; width: max-content; max-width: 100%; white-space: nowrap; } }
@media (max-width:640px) { .integration-toolbar { justify-content: stretch; } .integration-toolbar .button { flex: 1 1 auto; font-size: 11px; } .integration-tabs a { padding: 12px 6px; gap: 5px; font-size: 11px; flex-direction: column; } .integration-context { display: flex; } .integration-heading { flex-direction: column; } .integration-heading > .button { width: 100%; justify-content: center; } .destination-url { max-width: 100%; white-space: normal; overflow-wrap: anywhere; } .endpoint-menu-content { min-width: 0; } .integration-dialog { padding: 18px; } }
</style>
