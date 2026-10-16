<script setup lang="ts">
import { computed, ref } from "vue";
import { dateTime, statusLabel } from "../services/format";
import { can, route } from "../services/app";
import ActionForm from "../components/ActionForm.vue";
import DataTable from "../components/DataTable.vue";
import ProgressPanel from "../components/ProgressPanel.vue";
import Icon from "../components/Icon.vue";
const props = defineProps<{ data: any; page: string }>();
const report = computed(() => props.data.diagnostics || {});
const summary = computed(() => report.value.summary || {});
const selectedStep = ref("");
const eventQuery = ref("");
const stages = computed<any[]>(() =>
  Array.isArray(report.value.stages) ? report.value.stages : [],
);
const selectedStage = computed(
  () =>
    stages.value.find((stage: any) => stage.step === selectedStep.value) ||
    stages.value.find((stage: any) =>
      ["failed", "running"].includes(stage.status),
    ) ||
    stages.value[0] ||
    null,
);
const events = computed<any[]>(() =>
  (Array.isArray(report.value.events) ? report.value.events : []).slice(-300),
);
const operationalLogs = computed<any[]>(() =>
  Array.isArray(props.data.logs) ? props.data.logs : [],
);
const filteredEvents = computed<any[]>(() =>
  events.value.filter(
    (event: any) =>
      !eventQuery.value ||
      [event.timestamp, event.level, event.step, event.message]
        .join(" ")
        .toLocaleLowerCase("pt-BR")
        .includes(eventQuery.value.toLocaleLowerCase("pt-BR")),
  ),
);
const stageEvents = computed<any[]>(() =>
  selectedStage.value
    ? events.value
        .filter((event: any) => event.step === selectedStage.value.step)
        .slice(-8)
    : [],
);
const stageNames: Record<string, string> = {
  lock: "Bloqueio da execução",
  backup_full: "Backup FULL",
  pre_update_commands: "Preparação",
  maintenance_on: "Modo de manutenção",
  git_update: "Atualização do código",
  psr4_sanitize: "Verificação do autoload",
  composer_install: "Composer install",
  migrate: "Migrations",
  seed: "Seeds",
  sql_patch: "Patches SQL",
  build_assets: "Compilação dos assets",
  post_update_commands: "Rotinas posteriores",
  cache_clear: "Limpeza de cache",
  health_check: "Health check",
  maintenance_off: "Liberação da aplicação",
};
const stageName = (step: any) =>
  stageNames[String(step)] || String(step || "Etapa não informada");
const migrationLabels: Record<string, string> = {
  pendente: "Pendente",
  "aplicada com sucesso": "Aplicada",
  "aplicada com ressalvas": "Com ressalvas",
  erro: "Com erro",
  "ignorada por idempotência": "Ignorada por idempotência",
  reconciliada: "Reconciliada",
  reaplicada: "Reaplicada",
  inconsistente: "Inconsistente",
  órfã: "Órfã",
  "ausente no banco": "Ausente no banco",
  "ausente no código": "Ausente no código",
};
const migrationLabel = (value: any) =>
  migrationLabels[String(value)] || statusLabel(value);
const duration = (milliseconds: any) =>
  milliseconds === null ||
  milliseconds === undefined ||
  !Number.isFinite(Number(milliseconds))
    ? "—"
    : Number(milliseconds) >= 60000
      ? `${Math.floor(Number(milliseconds) / 60000)} min ${Math.round((Number(milliseconds) % 60000) / 1000)} s`
      : `${(Number(milliseconds) / 1000).toLocaleString("pt-BR", { maximumFractionDigits: 2 })} s`;
function tone(value: any): string {
  const status = String(value || "").toLocaleLowerCase("pt-BR");
  if (
    [
      "success",
      "completed",
      "finished",
      "delivered",
      "aplicada com sucesso",
      "reconciliada",
      "reaplicada",
      "ignorada por idempotência",
    ].includes(status)
  )
    return "success";
  if (
    ["failed", "error", "erro", "rejected", "cancelled", "canceled"].includes(
      status,
    )
  )
    return "danger";
  if (
    [
      "warning",
      "warn",
      "aplicada com ressalvas",
      "inconsistente",
      "órfã",
      "ausente no banco",
      "ausente no código",
      "pendente",
      "pending",
    ].includes(status)
  )
    return "warning";
  if (["running", "queued", "processing", "dry_run"].includes(status))
    return "info";
  return "neutral";
}
const statusIcon = (value: any) =>
  tone(value) === "success"
    ? "check"
    : ["danger", "warning"].includes(tone(value))
      ? "alert"
      : "clock";
const runTone = computed(() =>
  props.data.run?.status === "success" &&
  Number(summary.value.warning_count) > 0
    ? "warning"
    : tone(props.data.run?.status),
);
const runTitle = computed(() => {
  const status = String(props.data.run?.status || "").toLowerCase();
  if (status === "success")
    return Number(summary.value.warning_count) > 0
      ? "Concluída com alertas"
      : "Execução concluída com sucesso";
  if (status === "failed") return "A execução precisa de atenção";
  if (status === "dry_run") return "Simulação concluída";
  return statusLabel(status);
});
const targetReference = computed(
  () =>
    report.value.options?.target_tag ||
    report.value.options?.target_branch ||
    props.data.run?.revision_after ||
    "—",
);
const pretty = (value: any) =>
  typeof value === "string" ? value : JSON.stringify(value, null, 2);
const metric = (key: string) => props.data.metrics?.[key] ?? "—";
const bool = (value: any) => [true, 1, "1"].includes(value);
const size = (value: any) =>
  value === null || value === undefined
    ? "—"
    : Number(value || 0) >= 1048576
      ? `${(Number(value) / 1048576).toLocaleString("pt-BR", { maximumFractionDigits: 1 })} MB`
      : `${Number(value || 0).toLocaleString("pt-BR")} bytes`;
const migrationStatuses = [
  "pendente",
  "aplicada com sucesso",
  "aplicada com ressalvas",
  "erro",
  "ignorada por idempotência",
  "reconciliada",
  "reaplicada",
  "inconsistente",
  "órfã",
  "ausente no banco",
  "ausente no código",
];
function diagnostics(id: any, format: string) {
  return (
    route("updater.runs.diagnostics.download", { id, format }) ||
    route("updater.runs.show", { id, download: format })
  );
}
</script>
<template>
  <div class="stack">
    <template v-if="page === 'sections.runs'"
      ><section class="panel">
        <div class="panel-heading">
          <h2>Histórico de execuções</h2>
          <span class="badge">Até 100 operações recentes</span>
        </div>
        <DataTable
          :rows="data.runs || []"
          :columns="[
            { key: 'id', label: 'Execução' },
            { key: 'status', label: 'Resultado' },
            { key: 'started_at', label: 'Início' },
            { key: 'finished_at', label: 'Conclusão' },
          ]"
          ><template #actions="{ row }"
            ><a
              class="button subtle"
              :href="route('updater.runs.show', { id: row.id })"
              >Abrir diagnóstico</a
            ><a class="button" :href="diagnostics(row.id, 'json')" data-external
              >JSON</a
            ><a class="button" :href="diagnostics(row.id, 'zip')" data-external
              >ZIP</a
            ></template
          ></DataTable
        >
      </section></template
    >
    <template v-else-if="page === 'runs.show'">
      <section
        class="ops-run-banner"
        :class="runTone"
        aria-label="Situação da execução"
      >
        <span class="ops-status-orb" aria-hidden="true"
          ><Icon
            :name="
              runTone === 'success'
                ? 'check'
                : ['danger', 'warning'].includes(runTone)
                  ? 'alert'
                  : 'clock'
            "
            :size="23"
        /></span>
        <div class="ops-run-heading">
          <h2>{{ runTitle }}</h2>
          <p v-if="data.run?.status === 'failed'">
            {{
              report.error?.message ||
              "Revise as etapas e os eventos para identificar o que aconteceu."
            }}
          </p>
          <p v-else-if="String(data.run?.status).toLowerCase() === 'dry_run'">
            A simulação terminou. Nenhuma atualização real foi aplicada.
          </p>
          <p v-else-if="Number(summary.warning_count) > 0">
            A operação foi finalizada com avisos que merecem sua atenção.
          </p>
          <p v-else>
            Acompanhe o resultado, as etapas e os eventos registrados nesta
            operação.
          </p>
        </div>
        <div class="ops-run-id">
          <strong>#{{ data.run?.id }}</strong
          ><small>{{ dateTime(data.run?.started_at) }}</small>
        </div>
      </section>
      <section
        class="panel ops-run-metadata"
        aria-label="Informações da execução"
      >
        <div>
          <span class="ops-meta-icon"><Icon name="clock" :size="18" /></span>
          <div>
            <span>Início</span
            ><strong>{{ dateTime(data.run?.started_at) }}</strong>
          </div>
        </div>
        <div>
          <span class="ops-meta-icon"><Icon name="clock" :size="18" /></span>
          <div>
            <span>Duração</span
            ><strong>{{ duration(summary.duration_ms) }}</strong>
          </div>
        </div>
        <div>
          <span class="ops-meta-icon"><Icon name="branch" :size="18" /></span>
          <div>
            <span>Revisão de origem</span
            ><code>{{ data.run?.revision_before || "—" }}</code>
          </div>
        </div>
        <div>
          <span class="ops-meta-icon"><Icon name="cube" :size="18" /></span>
          <div>
            <span>Destino solicitado</span><code>{{ targetReference }}</code>
          </div>
        </div>
      </section>
      <div class="ops-run-tools">
        <div class="ops-counter-badges">
          <span
            class="ops-pill"
            :class="Number(summary.error_count) > 0 ? 'danger' : 'neutral'"
            >{{ summary.error_count ?? "—" }} erro(s)</span
          >
          <span
            class="ops-pill"
            :class="Number(summary.warning_count) > 0 ? 'warning' : 'neutral'"
            >{{ summary.warning_count ?? "—" }} aviso(s)</span
          >
          <small v-if="data.run?.finished_at"
            >Finalizada em {{ dateTime(data.run.finished_at) }}</small
          >
        </div>
        <div class="actions">
          <a
            class="button subtle"
            :href="diagnostics(data.run?.id, 'json')"
            data-external
            ><Icon name="download" :size="15" />Baixar JSON</a
          >
          <a
            class="button subtle"
            :href="diagnostics(data.run?.id, 'zip')"
            data-external
            ><Icon name="download" :size="15" />ZIP</a
          >
          <a
            class="button"
            :href="route('updater.section', { section: 'runs' })"
            >Voltar às execuções</a
          >
        </div>
      </div>
      <section
        v-if="data.pendingApproval && can('updates.run')"
        class="panel danger-zone ops-approval"
      >
        <div class="ops-section-lead">
          <span class="ops-soft-icon warning"
            ><Icon name="alert" :size="20"
          /></span>
          <div>
            <h2>Aprovar atualização real</h2>
            <p>
              A simulação não aplicou mudanças. Revise o alvo e confirme a
              operação real com os mesmos parâmetros.
            </p>
          </div>
        </div>
        <ActionForm
          :action="route('updater.runs.approve', { id: data.run.id })"
          confirm="Aprovar e executar a atualização real após esta simulação? O backup seguirá a política configurada no servidor. Confira a configuração antes de aplicar."
        >
          <div class="fields-grid">
            <label
              >Digite ATUALIZAR para confirmar<input
                name="confirm_apply"
                required
                pattern="ATUALIZAR"
                autocomplete="off"
            /></label>
            <label
              >Sua senha de aprovação<input
                name="password"
                type="password"
                required
                autocomplete="current-password"
            /></label>
          </div>
          <button class="button primary">
            <Icon name="play" :size="15" />Aprovar e executar atualização real
          </button>
        </ActionForm>
      </section>
      <ProgressPanel
        v-if="['queued', 'running'].includes(data.run?.status)"
        :endpoint="route('updater.updates.progress.status')"
        title="Acompanhamento da execução"
      />
      <div class="ops-diagnostics-layout">
        <section class="panel ops-timeline-panel">
          <div class="panel-heading">
            <div>
              <h2>Linha do tempo</h2>
              <p class="muted">Etapas e resultados da execução.</p>
            </div>
            <span class="ops-pill neutral">{{ stages.length }} etapa(s)</span>
          </div>
          <ol
            v-if="stages.length"
            class="ops-timeline"
            aria-label="Etapas da execução"
          >
            <li
              v-for="(stage, index) in stages"
              :key="String(stage.step) + index"
              :class="tone(stage.status)"
            >
              <button
                type="button"
                class="ops-timeline-step"
                :class="{ selected: selectedStage?.step === stage.step }"
                :aria-pressed="selectedStage?.step === stage.step"
                @click="selectedStep = String(stage.step)"
              >
                <span class="ops-timeline-dot" aria-hidden="true"
                  ><Icon
                    :name="statusIcon(stage.status)"
                    :size="14"
                    :stroke-width="2.5"
                /></span>
                <span class="ops-timeline-copy"
                  ><strong>{{ stageName(stage.step) }}</strong
                  ><small
                    >{{ statusLabel(stage.status)
                    }}<template
                      v-if="
                        stage.duration_ms !== null &&
                        stage.duration_ms !== undefined
                      "
                    >
                      · {{ duration(stage.duration_ms) }}</template
                    ></small
                  ></span
                >
                <Icon name="chevron-right" :size="15" class="ops-step-arrow" />
              </button>
            </li>
          </ol>
          <div v-else class="empty-state ops-compact-empty">
            <Icon name="clock" :size="28" />
            <h3>Sem etapas detalhadas</h3>
            <p>
              Esta execução ainda não possui etapas registradas no diagnóstico.
            </p>
          </div>
          <p class="field-hint ops-timeline-note">
            Uma rotina de rollback executada não comprova restauração integral
            dos dados.
          </p>
        </section>
        <section
          class="panel ops-stage-panel"
          aria-label="Detalhes da etapa selecionada"
        >
          <div class="panel-heading">
            <h2>Detalhes da etapa</h2>
            <span
              v-if="selectedStage"
              class="ops-pill"
              :class="tone(selectedStage.status)"
              >{{ statusLabel(selectedStage.status) }}</span
            >
          </div>
          <template v-if="selectedStage">
            <div class="ops-stage-title">
              <span class="ops-soft-icon"
                ><Icon name="layers" :size="20"
              /></span>
              <div>
                <h3>{{ stageName(selectedStage.step) }}</h3>
                <code>{{ selectedStage.step }}</code>
              </div>
            </div>
            <dl class="ops-stage-metadata">
              <div>
                <dt>Início</dt>
                <dd>{{ dateTime(selectedStage.started_at) }}</dd>
              </div>
              <div>
                <dt>Conclusão</dt>
                <dd>{{ dateTime(selectedStage.finished_at) }}</dd>
              </div>
              <div>
                <dt>Duração</dt>
                <dd>{{ duration(selectedStage.duration_ms) }}</dd>
              </div>
              <div>
                <dt>Rollback</dt>
                <dd>
                  {{
                    selectedStage.rollback
                      ? statusLabel(selectedStage.rollback)
                      : "Não registrado"
                  }}
                </dd>
              </div>
            </dl>
            <div v-if="selectedStage.status === 'failed'" class="notice danger">
              <strong>Falha nesta etapa</strong>
              <p>
                {{
                  report.error?.message ||
                  "Consulte os eventos técnicos e baixe o diagnóstico para analisar a falha."
                }}
              </p>
            </div>
            <ul v-if="stageEvents.length" class="ops-stage-events">
              <li
                v-for="(event, index) in stageEvents"
                :key="event.id || index"
              >
                <span class="ops-event-indicator" :class="tone(event.level)"
                  ><Icon
                    :name="
                      ['error', 'critical', 'warning', 'warn'].includes(
                        event.level,
                      )
                        ? 'alert'
                        : event.level === 'success'
                          ? 'check'
                          : 'file'
                    "
                    :size="13" /></span
                ><span>{{ event.message }}</span
                ><small>{{ dateTime(event.timestamp) }}</small>
              </li>
            </ul>
            <p v-else class="ops-stage-no-events">
              Nenhum evento individual foi registrado para esta etapa.
            </p>
            <details
              v-if="
                selectedStage.details &&
                Object.keys(selectedStage.details).length
              "
              class="ops-raw-details"
            >
              <summary>Dados do diagnóstico desta etapa</summary>
              <pre>{{ pretty(selectedStage.details) }}</pre>
            </details>
          </template>
          <div v-else class="empty-state ops-compact-empty">
            <Icon name="file" :size="28" />
            <h3>Nenhuma etapa selecionada</h3>
            <p>
              Os detalhes estarão disponíveis quando a execução registrar suas
              etapas.
            </p>
          </div>
        </section>
      </div>
      <section class="panel ops-log-panel">
        <details open>
          <summary class="ops-log-heading">
            <span class="ops-section-lead"
              ><span class="ops-soft-icon"><Icon name="file" :size="19" /></span
              ><span
                ><strong>Eventos técnicos</strong
                ><small>Logs e eventos registrados pelo sistema.</small></span
              ></span
            ><span class="ops-pill neutral">{{ events.length }} evento(s)</span>
          </summary>
          <div class="ops-log-toolbar">
            <label
              ><Icon name="search" :size="15" /><input
                v-model="eventQuery"
                type="search"
                aria-label="Filtrar eventos da execução"
                placeholder="Buscar mensagem, etapa ou nível" /></label
            ><small
              >Últimos 300 eventos · dados sensíveis ocultados pelo
              servidor</small
            >
          </div>
          <div
            class="ops-console"
            role="region"
            aria-label="Eventos técnicos da execução"
            tabindex="0"
          >
            <ol v-if="filteredEvents.length">
              <li
                v-for="(event, index) in filteredEvents"
                :key="event.id || index"
              >
                <span class="ops-line-number" aria-hidden="true">{{
                  index + 1
                }}</span
                ><time>{{ dateTime(event.timestamp) }}</time
                ><span class="ops-log-level" :class="tone(event.level)">{{
                  String(event.level || "info").toUpperCase()
                }}</span>
                <div class="ops-log-message">
                  <span>{{ event.message }}</span>
                  <details
                    v-if="event.context && Object.keys(event.context).length"
                  >
                    <summary>
                      Contexto<template v-if="event.step">
                        · {{ stageName(event.step) }}</template
                      >
                    </summary>
                    <pre>{{ pretty(event.context) }}</pre>
                  </details>
                </div>
              </li>
            </ol>
            <p v-else>
              {{
                eventQuery
                  ? "Nenhum evento corresponde ao filtro informado."
                  : "Nenhum evento técnico foi registrado para esta execução."
              }}
            </p>
          </div>
        </details>
      </section>
      <section v-if="report.migration_attempts?.length" class="panel">
        <div class="panel-heading">
          <h2>Migrations auditadas</h2>
          <span class="ops-pill neutral"
            >{{ report.migration_attempts.length }} tentativa(s)</span
          >
        </div>
        <DataTable
          :rows="report.migration_attempts"
          :columns="[
            { key: 'migration', label: 'Migration' },
            { key: 'status', label: 'Resultado' },
            { key: 'attempt_no', label: 'Tentativa' },
            { key: 'error_message', label: 'Erro' },
          ]"
        />
      </section>
      <section class="panel ops-raw-details">
        <details>
          <summary>Opções da execução</summary>
          <pre>{{ pretty(report.options || {}) }}</pre>
        </details>
      </section>
    </template>
    <template v-else-if="page === 'sections.backups'"
      ><section class="panel">
        <div class="panel-heading">
          <h2>Criar backup manual</h2>
          <a class="text-button" :href="route('updater.settings.index')"
            >Configurar armazenamento</a
          >
        </div>
        <div class="three-column">
          <div>
            <h3>Banco de dados</h3>
            <p class="muted">Cópia dos dados conforme o driver configurado.</p>
            <ActionForm
              :action="route('updater.backups.create', { type: 'database' })"
              ><button class="button primary">
                Criar backup do banco
              </button></ActionForm
            >
          </div>
          <div>
            <h3>Snapshot</h3>
            <p class="muted">
              Cópia dos arquivos da aplicação conforme o perfil.
            </p>
            <ActionForm
              :action="route('updater.backups.create', { type: 'snapshot' })"
              ><button class="button">Criar snapshot</button></ActionForm
            >
          </div>
          <div>
            <h3>Backup completo</h3>
            <p class="muted">
              Executa o fluxo completo de proteção da aplicação.
            </p>
            <ActionForm
              :action="route('updater.backups.create', { type: 'full' })"
              ><button class="button">Criar backup completo</button></ActionForm
            >
          </div>
        </div>
      </section>
      <ProgressPanel
        :endpoint="route('updater.backups.progress.status')"
        title="Progresso dos backups"
        :cancel-route="route('updater.backups.cancel')"
      /><ProgressPanel
        :endpoint="route('updater.backups.upload.progress.status')"
        title="Envios para a nuvem"
        :cancel-route="route('updater.backups.upload.cancel')"
        upload
      />
      <section class="panel">
        <div class="panel-heading">
          <h2>Backups disponíveis</h2>
          <a
            class="text-button"
            :href="route('updater.backups.log.download')"
            data-external
            >Baixar log</a
          >
        </div>
        <DataTable
          :rows="data.backups || []"
          :columns="[
            { key: 'id', label: 'Backup' },
            { key: 'type', label: 'Tipo' },
            { key: 'path', label: 'Arquivo' },
            {
              key: 'cloud_uploaded',
              label: 'Nuvem',
              format: (v) => (bool(v) ? 'Enviado' : 'Somente local'),
            },
            { key: 'size', label: 'Tamanho', format: size },
            { key: 'created_at', label: 'Criado em' },
          ]"
          ><template #actions="{ row }"
            ><a
              class="button subtle"
              :href="route('updater.backups.download', { id: row.id })"
              data-external
              >Baixar</a
            ><a
              class="button danger"
              :href="route('updater.backups.restore.form', { id: row.id })"
              >Restaurar</a
            ><ActionForm
              :action="route('updater.backups.upload', { id: row.id })"
              ><button class="button">
                {{
                  bool(row.cloud_uploaded)
                    ? "Reenviar à nuvem"
                    : "Enviar à nuvem"
                }}
              </button></ActionForm
            ><ActionForm
              :action="route('updater.backups.delete', { id: row.id })"
              method="DELETE"
              confirm="Excluir o arquivo de backup local e seu registro? A operação não pode ser desfeita."
              ><button class="button danger">Excluir</button></ActionForm
            >
            <details>
              <summary>Detalhes do backup</summary>
              <dl class="info-list">
                <div>
                  <dt>Execução</dt>
                  <dd>{{ row.run_id || "—" }}</dd>
                </div>
                <div>
                  <dt>Provedor</dt>
                  <dd>{{ row.cloud_provider || "—" }}</dd>
                </div>
                <div>
                  <dt>Enviado em</dt>
                  <dd>{{ row.cloud_uploaded_at || "—" }}</dd>
                </div>
                <div>
                  <dt>Total de envios</dt>
                  <dd>{{ row.cloud_upload_count || 0 }}</dd>
                </div>
                <div>
                  <dt>Último erro</dt>
                  <dd>{{ row.cloud_last_error || "Nenhum" }}</dd>
                </div>
              </dl>
            </details></template
          ></DataTable
        >
      </section></template
    >
    <template v-else-if="page === 'backups.restore'"
      ><section class="panel danger-zone">
        <h2>Restaurar backup #{{ data.backup?.id }}</h2>
        <div class="notice warning">
          <strong
            >Esta operação pode substituir dados e arquivos atuais.</strong
          >
          <p>
            O acesso é restrito a administradores e a operação será registrada
            na auditoria.
          </p>
        </div>
        <dl class="info-list">
          <div>
            <dt>Tipo</dt>
            <dd>{{ data.backup?.type }}</dd>
          </div>
          <div>
            <dt>Arquivo</dt>
            <dd>
              <code>{{ data.backup?.path }}</code>
            </dd>
          </div>
          <div>
            <dt>Criado em</dt>
            <dd>{{ dateTime(data.backup?.created_at) }}</dd>
          </div>
        </dl>
        <ActionForm
          :action="route('updater.backups.restore', { id: data.backup?.id })"
          confirm="Restaurar este backup agora? Os dados e arquivos atuais poderão ser substituídos."
          ><div class="fields-grid section-divider">
            <label
              >Digite RESTAURAR<input
                name="confirmacao"
                pattern="RESTAURAR"
                required
                autocomplete="off" /></label
            ><label
              >Senha de administrador<input
                type="password"
                name="password"
                required
                autocomplete="current-password"
            /></label>
          </div>
          <div class="actions">
            <button class="button danger">Confirmar restauração</button
            ><a
              class="button subtle"
              :href="route('updater.section', { section: 'backups' })"
              >Cancelar</a
            >
          </div></ActionForm
        >
      </section></template
    >
    <template v-else-if="page === 'sections.logs'"
      ><section class="panel">
        <form
          class="filter-bar"
          method="GET"
          :action="route('updater.section', { section: 'logs' })"
        >
          <label
            >Execução<input
              name="run_id"
              type="number"
              min="1"
              :value="data.filters?.run_id || ''" /></label
          ><label
            >Nível<select name="level" :value="data.filters?.level || ''">
              <option value="">Todos</option>
              <option
                v-for="level in ['debug', 'info', 'warn', 'error']"
                :key="level"
              >
                {{ level }}
              </option>
            </select></label
          ><label
            >Mensagem<input
              name="q"
              :value="data.filters?.q || ''"
              type="search" /></label
          ><button class="button primary">Filtrar registros</button>
        </form>
      </section>
      <section class="panel ops-log-panel">
        <div class="panel-heading">
          <div class="ops-section-lead">
            <span class="ops-soft-icon"><Icon name="file" :size="20" /></span>
            <div>
              <h2>Registros encontrados</h2>
              <p>
                Eventos próprios da ferramenta, com credenciais ocultadas pelo
                servidor.
              </p>
            </div>
          </div>
          <span class="ops-pill neutral"
            >{{ operationalLogs.length }} registro(s)</span
          >
        </div>
        <div
          class="ops-console"
          role="region"
          aria-label="Logs do Updater"
          tabindex="0"
        >
          <ol v-if="operationalLogs.length">
            <li v-for="(log, index) in operationalLogs" :key="log.id || index">
              <span class="ops-line-number" aria-hidden="true">{{
                index + 1
              }}</span
              ><time>{{ dateTime(log.created_at) }}</time
              ><span class="ops-log-level" :class="tone(log.level)">{{
                String(log.level || "info").toUpperCase()
              }}</span>
              <div class="ops-log-message">
                <a
                  v-if="log.run_id"
                  class="ops-console-run"
                  :href="route('updater.runs.show', { id: log.run_id })"
                  >#{{ log.run_id }}</a
                >{{ log.message }}
                <details v-if="log.context_json || log.context">
                  <summary>Contexto do evento</summary>
                  <pre>{{ pretty(log.context_json || log.context) }}</pre>
                </details>
              </div>
            </li>
          </ol>
          <p v-else>Nenhum registro encontrado para os filtros informados.</p>
        </div>
      </section>
    </template>
    <template v-else-if="page === 'sections.seeds'"
      ><section class="panel">
        <h2>Registro de seeds</h2>
        <DataTable
          :rows="data.seeds || []"
          :columns="[
            { key: 'seeder_class', label: 'Seeder' },
            { key: 'checksum', label: 'Checksum' },
            { key: 'applied_at', label: 'Aplicado em' },
            { key: 'app_revision', label: 'Revisão' },
          ]"
        />
      </section>
      <section v-if="can('seeds.manage')" class="panel">
        <h2>Reaplicar um seeder</h2>
        <p class="muted">
          Confirme a classe exata. O seeder poderá alterar os dados da
          aplicação.
        </p>
        <ActionForm
          :action="route('updater.seeds.reapply')"
          confirm="Reaplicar este seeder no banco da aplicação?"
          ><label
            >Classe do seeder<input
              name="seeder_class"
              required
              placeholder="Database\Seeders\MeuSeeder" /></label
          ><button class="button primary">Reaplicar seeder</button></ActionForm
        >
      </section></template
    >
    <template v-else-if="page === 'sections.migrations'">
      <section
        class="panel ops-integrity"
        aria-label="Resumo da integridade das migrations"
      >
        <div class="ops-section-lead">
          <span class="ops-soft-icon ops-integrity-icon"
            ><Icon name="database" :size="25"
          /></span>
          <div>
            <h2>Integridade</h2>
            <p>
              Situação atual das migrations no ambiente.<span
                v-if="
                  data.metrics?.integrity_percent !== undefined &&
                  data.metrics?.integrity_percent !== null
                "
                class="ops-integrity-percent"
              >
                {{ data.metrics.integrity_percent }}% de integridade</span
              >
            </p>
          </div>
        </div>
        <div class="ops-integrity-stats">
          <div class="ops-audit-count neutral">
            <span>Total</span><strong>{{ metric("total") }}</strong>
          </div>
          <div class="ops-audit-count success">
            <span>Aplicadas</span><strong>{{ metric("success") }}</strong>
          </div>
          <div class="ops-audit-count warning">
            <span>Pendentes</span><strong>{{ metric("pending") }}</strong>
          </div>
          <div class="ops-audit-count danger">
            <span>Com erro</span><strong>{{ metric("error") }}</strong>
          </div>
        </div>
      </section>
      <section class="panel ops-filters">
        <div class="panel-heading">
          <div class="ops-section-lead">
            <span class="ops-soft-icon"><Icon name="search" :size="20" /></span>
            <div>
              <h2>Filtros</h2>
              <p>Refine a exibição das migrations.</p>
            </div>
          </div>
          <a class="button subtle" :href="route('updater.migrations.index')"
            >Limpar filtros</a
          >
        </div>
        <form
          class="ops-audit-filter-grid"
          method="GET"
          :action="route('updater.migrations.index')"
        >
          <label
            >Status<select name="status" :value="data.filters?.status || ''">
              <option value="">Todos</option>
              <option v-for="status in migrationStatuses" :key="status">
                {{ status }}
              </option>
            </select></label
          >
          <label
            >Nome da migration<span class="ops-search-field"
              ><Icon name="search" :size="16" /><input
                name="q"
                :value="data.filters?.q || ''"
                type="search"
                placeholder="Buscar pelo nome…" /></span
          ></label>
          <label
            >Execução<select name="run_id" :value="data.filters?.run_id || ''">
              <option value="">Todas</option>
              <option
                v-for="run in data.runs || []"
                :key="run.id"
                :value="run.id"
              >
                #{{ run.id }} · {{ statusLabel(run.status) }}
              </option>
            </select></label
          >
          <button class="button primary" type="submit">Aplicar filtros</button>
          <label class="toggle ops-inconsistent-toggle"
            ><input
              type="checkbox"
              name="inconsistent"
              value="1"
              :checked="data.filters?.inconsistent"
            />Somente inconsistentes<span
              v-if="data.metrics?.inconsistent !== undefined"
              class="ops-pill warning"
              >{{ data.metrics.inconsistent }}</span
            ></label
          >
        </form>
      </section>
      <section class="panel ops-migrations-table">
        <div class="panel-heading ops-audit-table-heading">
          <h2>Auditoria de migrations</h2>
          <span class="muted">{{ data.rows?.length || 0 }} registro(s)</span>
        </div>
        <DataTable
          :rows="data.rows || []"
          :search="false"
          :columns="[
            { key: 'migration', label: 'Nome da migration' },
            { key: 'status', label: 'Status' },
            { key: 'last_execution_at', label: 'Última execução' },
            { key: 'attempt_count', label: 'Tentativas' },
            { key: 'last_run_id', label: 'Execução' },
          ]"
        >
          <template #migration="{ row }"
            ><code class="ops-migration-name">{{
              row.migration
            }}</code></template
          >
          <template #status="{ row }"
            ><span class="ops-pill" :class="tone(row.status)">{{
              migrationLabel(row.status)
            }}</span></template
          >
          <template #last_run_id="{ row }"
            ><a
              v-if="row.last_run_id"
              :href="route('updater.runs.show', { id: row.last_run_id })"
              >#{{ row.last_run_id }}</a
            ><span v-else class="muted">—</span></template
          >
          <template #actions="{ row }"
            ><a
              class="text-button ops-row-detail"
              :href="
                route('updater.migrations.show', { migration: row.migration })
              "
              >Ver detalhes<Icon name="chevron-right" :size="13" /></a
            ><a
              class="ops-icon-action"
              :href="
                route('updater.section', { section: 'logs', q: row.migration })
              "
              :aria-label="'Ver logs de ' + row.migration"
              title="Ver logs"
              ><Icon name="file" :size="16" /></a
          ></template>
        </DataTable>
        <div class="ops-table-footer">
          <small
            >Exibindo {{ data.rows?.length || 0
            }}<template v-if="data.metrics?.total !== undefined">
              de {{ data.metrics.total }}</template
            >
            migrations.</small
          ><a
            class="text-button"
            :href="route('updater.section', { section: 'runs' })"
            >Histórico de execuções<Icon name="arrow-right" :size="13"
          /></a>
        </div>
        <details class="ops-extra-metrics">
          <summary>Outros indicadores de auditoria</summary>
          <dl class="info-list">
            <div
              v-for="key in [
                'reapplied',
                'reconciled',
                'idempotent_skipped',
                'last_run_id',
                'last_error_migration',
              ]"
              :key="key"
            >
              <dt>
                {{
                  {
                    reapplied: "Reaplicadas",
                    reconciled: "Reconciliadas",
                    idempotent_skipped: "Ignoradas por idempotência",
                    last_run_id: "Última execução",
                    last_error_migration: "Último erro",
                  }[key]
                }}
              </dt>
              <dd>{{ data.metrics?.[key] ?? "—" }}</dd>
            </div>
          </dl>
        </details>
      </section>
    </template>
    <template v-else-if="page === 'sections.migrations-show'"
      ><section class="panel ops-migration-detail">
        <div class="panel-heading">
          <h2 class="ops-migration-title">
            <code>{{ data.item?.migration }}</code>
          </h2>
          <span class="ops-pill" :class="tone(data.item?.status)">{{
            migrationLabel(data.item?.status)
          }}</span>
        </div>
        <dl class="info-list">
          <div>
            <dt>Arquivo</dt>
            <dd>{{ data.item?.file_path || "—" }}</dd>
          </div>
          <div>
            <dt>Estado consolidado</dt>
            <dd>{{ data.item?.status }}</dd>
          </div>
          <div>
            <dt>Última execução</dt>
            <dd>{{ data.item?.last_execution_at || "—" }}</dd>
          </div>
          <div>
            <dt>Tentativas</dt>
            <dd>{{ data.item?.attempt_count || 0 }}</dd>
          </div>
        </dl>
      </section>
      <section id="consistencia" class="panel">
        <h2>Consistência: código, banco e histórico</h2>
        <div class="permissions-grid">
          <p
            v-for="[key, label] in [
              ['exists_in_code', 'Presente no código'],
              ['exists_in_migrations_table', 'Presente no banco'],
              ['is_inconsistent', 'Inconsistente'],
              ['reconciled', 'Reconciliada'],
              ['reapplied', 'Reaplicada'],
              ['idempotent_skipped', 'Ignorada por idempotência'],
            ]"
            :key="key"
          >
            <span
              class="ops-pill"
              :class="
                key === 'is_inconsistent'
                  ? bool(data.item?.[key])
                    ? 'warning'
                    : 'success'
                  : bool(data.item?.[key])
                    ? 'success'
                    : 'neutral'
              "
              >{{ bool(data.item?.[key]) ? "Sim" : "Não" }}</span
            >
            {{ label }}
          </p>
        </div>
      </section>
      <section class="panel danger-zone">
        <h2>Reexecução controlada</h2>
        <p class="muted">
          Informe o motivo e a ação. A solicitação será auditada e usará o fluxo
          idempotente da ferramenta.
        </p>
        <ActionForm
          :action="route('updater.migrations.reapply')"
          confirm="Solicitar a reaplicação desta migration conforme o modo escolhido?"
          ><input
            type="hidden"
            name="migration"
            :value="data.item?.migration"
          /><input type="hidden" name="redirect_to" value="show" /><label
            >Motivo da reaplicação<input
              name="reason"
              required
              minlength="3"
              maxlength="1000"
              placeholder="Descreva a inconsistência ou a necessidade operacional" /></label
          ><label
            >Ação<select name="action_type" required>
              <option value="" disabled selected>Selecione uma ação</option>
              <option value="run_now">Executar agora</option>
              <option value="queue">Adicionar à fila de reaplicações</option>
            </select></label
          >
          <div class="actions">
            <button class="button primary">Confirmar reaplicação</button
            ><a class="button subtle" :href="route('updater.migrations.index')"
              >Voltar à auditoria</a
            >
          </div></ActionForm
        >
      </section>
      <section id="historico" class="panel">
        <h2>Histórico de tentativas</h2>
        <DataTable
          :rows="data.attempts || []"
          :columns="[
            { key: 'created_at', label: 'Data' },
            { key: 'status', label: 'Resultado' },
            { key: 'attempt_no', label: 'Tentativa' },
            { key: 'run_id', label: 'Execução' },
            { key: 'origin', label: 'Origem' },
            { key: 'requested_by', label: 'Solicitante' },
            { key: 'reason', label: 'Motivo' },
            { key: 'error_message', label: 'Erro' },
          ]"
        />
      </section>
      <section class="panel">
        <h2>Eventos de reconciliação</h2>
        <DataTable
          :rows="data.reconciliations || []"
          :columns="[
            { key: 'reconciled_at', label: 'Data' },
            { key: 'strategy', label: 'Estratégia' },
            { key: 'run_id', label: 'Execução' },
            { key: 'reason', label: 'Motivo' },
          ]"
        />
      </section>
      <section class="panel">
        <h2>Fila e histórico de reaplicações</h2>
        <DataTable
          :rows="data.reapplyQueue || []"
          :columns="[
            { key: 'requested_at', label: 'Solicitada em' },
            { key: 'status', label: 'Estado' },
            { key: 'run_id', label: 'Execução' },
            { key: 'requested_by', label: 'Solicitante' },
            { key: 'reason', label: 'Motivo' },
          ]"
        />
      </section>
      <section class="panel">
        <h2>Logs relacionados</h2>
        <DataTable
          :rows="data.logs || []"
          :columns="[
            { key: 'created_at', label: 'Data' },
            { key: 'level', label: 'Nível' },
            { key: 'message', label: 'Mensagem' },
            { key: 'run_id', label: 'Execução' },
          ]"
        /></section
    ></template>
    <template v-else-if="page === 'sections.security'"
      ><div class="two-column">
        <section class="panel">
          <h2>Autenticação e recuperação</h2>
          <p class="muted">
            Use um autenticador em dois fatores e guarde os códigos de
            recuperação em local seguro.
          </p>
          <a class="button primary" :href="route('updater.profile')"
            >Gerenciar meu acesso</a
          >
        </section>
        <section class="panel">
          <h2>Saúde do repositório</h2>
          <p class="muted">
            A manutenção Git organiza e remove objetos desnecessários conforme a
            configuração.
          </p>
          <p>
            <strong>{{ size(data.gitSizeBytes) }}</strong> no diretório .git
          </p>
          <ActionForm
            :action="route('updater.security.git.maintain')"
            confirm="Executar a manutenção do repositório Git agora?"
            ><button
              class="button"
              :disabled="data.gitMaintenanceEnabled === false"
            >
              Executar manutenção Git
            </button></ActionForm
          >
        </section>
      </div>
      <section class="panel">
        <h2>Bloqueio de atualização</h2>
        <p class="muted">
          O bloqueio impede atualizações simultâneas. O servidor verifica os
          sinais de atividade antes de liberar uma operação órfã.
        </p>
        <dl class="info-list">
          <div>
            <dt>Driver</dt>
            <dd>{{ data.lockInfo?.driver || "Não informado" }}</dd>
          </div>
          <div>
            <dt>Processo</dt>
            <dd>{{ data.lockInfo?.pid || "—" }}</dd>
          </div>
          <div>
            <dt>Arquivo ou chave</dt>
            <dd>{{ data.lockInfo?.path || data.lockInfo?.key || "—" }}</dd>
          </div>
        </dl>
        <div
          v-if="data.recoveryState?.active"
          class="notice warning"
          style="margin-top: 18px"
        >
          <strong>Execução ativa #{{ data.recoveryState.run?.id }}</strong>
          <p>{{ data.recoveryState.reason }}</p>
          <p>
            Etapa: {{ data.recoveryState.current_step || "—" }} · Última
            atividade: {{ data.recoveryState.run?.heartbeat_at || "—" }}
          </p>
        </div>
        <div class="actions">
          <ActionForm
            :action="route('updater.security.lock.clear')"
            confirm="Solicitar a liberação do bloqueio? O servidor recusará se existir uma operação ativa."
            ><button class="button danger">
              Limpar bloqueio residual
            </button></ActionForm
          ><ActionForm
            v-if="data.recoveryState?.recoverable"
            :action="route('updater.updates.recover')"
            confirm="Recuperar a execução órfã e liberar a manutenção?"
            ><button class="button">Recuperar execução órfã</button></ActionForm
          >
        </div>
      </section></template
    >
    <template v-else
      ><section class="panel empty-state">
        <h2>Esta página não está disponível.</h2>
        <p class="muted">Verifique seu acesso ou retorne à visão geral.</p>
        <a class="button primary" :href="route('updater.index')"
          >Ir para visão geral</a
        >
      </section></template
    >
  </div>
</template>

<style scoped>
.ops-section-lead {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.ops-section-lead h2,
.ops-section-lead h3 {
  margin: 0 0 3px;
}
.ops-section-lead p,
.ops-section-lead small {
  margin: 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.5;
}
.ops-section-lead small {
  display: block;
}
.ops-soft-icon,
.ops-meta-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  width: 35px;
  height: 35px;
  color: var(--primary);
  background: #eef4ff;
  border-radius: 50%;
}
.ops-soft-icon.warning {
  color: #bb7800;
  background: #fff2d6;
}
.ops-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 20px;
  font-size: 10px;
  line-height: 1.5;
  font-weight: 600;
  white-space: nowrap;
}
.ops-pill.success {
  color: #087d49;
  background: #d9f4e7;
}
.ops-pill.danger {
  color: #d32d49;
  background: #ffe3e8;
}
.ops-pill.warning {
  color: #a76800;
  background: #fff1cf;
}
.ops-pill.info {
  color: var(--primary);
  background: #e9f0ff;
}
.ops-pill.neutral {
  color: #5b6d8e;
  background: #f0f4fa;
}
.ops-run-banner {
  display: flex;
  align-items: center;
  gap: 15px;
  padding: 17px 20px;
  border: 1px solid #dce6f5;
  background: #f2f6ff;
  border-radius: 8px;
}
.ops-run-banner.success {
  background: #f1fbf6;
  border-color: #cceadb;
}
.ops-run-banner.warning {
  background: #fffbef;
  border-color: #f5dfab;
}
.ops-run-banner.danger {
  background: #fff3f5;
  border-color: #f2cdd5;
}
.ops-status-orb {
  width: 43px;
  height: 43px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  color: #fff;
  background: var(--primary);
  border-radius: 50%;
  box-shadow: 0 0 0 5px #e8efff;
}
.success .ops-status-orb {
  background: #159452;
  box-shadow: 0 0 0 5px #e2f4e9;
}
.warning .ops-status-orb {
  background: #edaa0a;
  box-shadow: 0 0 0 5px #fff4d6;
}
.danger .ops-status-orb {
  background: #db4561;
  box-shadow: 0 0 0 5px #ffe8ed;
}
.ops-run-heading {
  flex: 1;
  min-width: 0;
}
.ops-run-heading h2 {
  margin: 0 0 3px;
  font-size: 16px;
}
.ops-run-heading p {
  margin: 0;
  font-size: 12px;
  color: var(--muted);
  overflow-wrap: anywhere;
}
.ops-run-banner.warning h2 {
  color: #b56b00;
}
.ops-run-banner.success h2 {
  color: #087c49;
}
.ops-run-banner.danger h2 {
  color: #c6304c;
}
.ops-run-id {
  display: flex;
  flex-direction: column;
  gap: 3px;
  text-align: right;
  color: #526488;
}
.ops-run-id small {
  white-space: nowrap;
  font-size: 10px;
}
.ops-run-metadata {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0;
  padding: 18px 14px;
}
.ops-run-metadata > div {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  padding: 0 15px;
}
.ops-run-metadata > div + div {
  border-left: 1px solid var(--line);
}
.ops-run-metadata > div > div {
  min-width: 0;
}
.ops-run-metadata strong,
.ops-run-metadata code {
  display: block;
  font-size: 11px;
  font-weight: 500;
  overflow-wrap: anywhere;
}
.ops-run-metadata div > span:not(.ops-meta-icon) {
  display: block;
  font-size: 11px;
  font-weight: 600;
  margin-bottom: 3px;
}
.ops-meta-icon {
  width: 30px;
  height: 30px;
}
.ops-run-tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: -3px;
}
.ops-run-tools .actions {
  margin: 0;
  gap: 7px;
}
.ops-counter-badges {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 7px;
}
.ops-counter-badges small {
  font-size: 11px;
}
.ops-approval .ops-section-lead {
  margin-bottom: 17px;
}
.ops-diagnostics-layout {
  display: grid;
  grid-template-columns: minmax(235px, 0.9fr) minmax(0, 1.35fr);
  gap: 16px;
  align-items: stretch;
}
.ops-diagnostics-layout > section {
  min-width: 0;
}
.ops-diagnostics-layout .panel-heading {
  align-items: flex-start;
  margin-bottom: 15px;
}
.ops-diagnostics-layout h2 {
  margin: 0 0 3px;
  font-size: 14px;
}
.ops-diagnostics-layout .panel-heading p {
  margin: 0;
  font-size: 11px;
}
.ops-timeline {
  margin: 0;
  padding: 0;
  list-style: none;
}
.ops-timeline li {
  position: relative;
}
.ops-timeline li:not(:last-child)::after {
  content: "";
  position: absolute;
  left: 20px;
  top: 37px;
  bottom: -7px;
  width: 1px;
  background: #d8e5df;
}
.ops-timeline-step {
  display: grid;
  grid-template-columns: 26px minmax(0, 1fr) 15px;
  align-items: center;
  gap: 10px;
  padding: 10px 8px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--ink);
  width: 100%;
  text-align: left;
}
.ops-timeline-step:hover,
.ops-timeline-step.selected {
  background: #eef4ff;
}
.ops-timeline-dot {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 25px;
  height: 25px;
  border: 2px solid #fff;
  border-radius: 50%;
  color: #667a9c;
  background: #e6edf6;
}
.ops-timeline .success .ops-timeline-dot {
  background: #159452;
  color: #fff;
}
.ops-timeline .danger .ops-timeline-dot {
  background: #dc4560;
  color: #fff;
}
.ops-timeline .warning .ops-timeline-dot {
  background: #eba814;
  color: #fff;
}
.ops-timeline .info .ops-timeline-dot {
  background: var(--primary);
  color: #fff;
}
.ops-timeline-copy {
  min-width: 0;
}
.ops-timeline-copy strong {
  display: block;
  font-size: 12px;
}
.ops-timeline-copy small {
  display: block;
  color: var(--muted);
  font-size: 10px;
  margin-top: 2px;
}
.ops-step-arrow {
  color: #8090ab;
}
.ops-timeline-note {
  margin: 15px 0 0;
  padding-top: 12px;
  border-top: 1px solid var(--line);
  font-size: 10px;
}
.ops-stage-title {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 4px 0 15px;
}
.ops-stage-title h3 {
  margin: 0;
  font-size: 14px;
}
.ops-stage-title code {
  color: var(--muted);
  font-size: 10px;
}
.ops-stage-metadata {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 13px 20px;
  margin: 0 0 18px;
  padding: 13px 15px;
  background: #f7f9fd;
  border: 1px solid #e9eef7;
  border-radius: 6px;
}
.ops-stage-metadata dt {
  color: var(--muted);
  font-size: 10px;
  margin-bottom: 3px;
}
.ops-stage-metadata dd {
  margin: 0;
  font-size: 11px;
  overflow-wrap: anywhere;
}
.ops-stage-events {
  list-style: none;
  padding: 0;
  margin: 0;
}
.ops-stage-events li {
  display: grid;
  grid-template-columns: 17px minmax(0, 1fr) auto;
  gap: 8px;
  align-items: flex-start;
  padding: 9px 0;
  border-bottom: 1px solid #edf1f7;
  font-size: 11px;
}
.ops-stage-events li > span:not(.ops-event-indicator) {
  overflow-wrap: anywhere;
}
.ops-stage-events small {
  font-size: 9px;
}
.ops-event-indicator {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 17px;
  height: 17px;
  color: #3e74ba;
  background: #eaf2ff;
  border-radius: 50%;
}
.ops-event-indicator.success {
  color: #159452;
  background: #e8f5ee;
}
.ops-event-indicator.warning {
  color: #ac770c;
  background: #fff2d7;
}
.ops-event-indicator.danger {
  color: #d23954;
  background: #ffeaf0;
}
.ops-stage-no-events {
  font-size: 12px;
  padding: 15px 0;
  color: var(--muted);
}
.ops-compact-empty {
  padding: 24px 12px;
}
.ops-compact-empty > svg {
  color: #8397b9;
  margin-bottom: 10px;
}
.ops-compact-empty h3 {
  margin: 0 0 5px;
  font-size: 13px;
}
.ops-compact-empty p {
  margin: 0;
  font-size: 11px;
}
.ops-raw-details {
  font-size: 12px;
}
.ops-raw-details summary {
  margin-top: 12px;
  color: var(--primary);
  cursor: pointer;
}
.ops-raw-details.panel summary {
  margin-top: 0;
}
.ops-raw-details pre {
  background: #f5f8fd;
  font-size: 11px;
}
.ops-log-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  cursor: pointer;
  list-style: none;
}
.ops-log-heading::-webkit-details-marker {
  display: none;
}
.ops-log-heading .ops-section-lead strong {
  font-size: 13px;
}
.ops-log-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 14px 0 10px;
}
.ops-log-toolbar label {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 310px;
  max-width: 100%;
  padding: 0 10px;
  border: 1px solid var(--line);
  border-radius: 6px;
  color: #8495b1;
}
.ops-log-toolbar label:focus-within {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}
.ops-log-toolbar input {
  width: 100%;
  min-width: 0;
  border: 0;
  background: transparent;
  padding: 7px 0;
  font-size: 11px;
  box-shadow: none;
}
.ops-log-toolbar input:focus-visible {
  outline: none;
}
.ops-log-toolbar small {
  font-size: 10px;
}
.ops-console {
  max-height: 440px;
  overflow: auto;
  padding: 13px 14px;
  color: #d6e1f1;
  background: #1d293a;
  border: 1px solid #26344a;
  border-radius: 6px;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  font-size: 11px;
  line-height: 1.75;
}
.ops-console ol {
  margin: 0;
  padding: 0;
  list-style: none;
}
.ops-console li {
  display: grid;
  grid-template-columns: 23px 125px 58px minmax(0, 1fr);
  gap: 8px;
  padding: 3px 0;
  align-items: start;
}
.ops-console time {
  color: #b8c8e0;
  font-size: 10px;
  white-space: nowrap;
}
.ops-console p {
  margin: 0;
  color: #b8c8e0;
}
.ops-line-number {
  color: #7f90a9;
  text-align: right;
  padding-right: 4px;
  user-select: none;
}
.ops-log-level {
  color: #bed5fa;
  font-size: 10px;
  font-weight: 600;
}
.ops-log-level.warning {
  color: #f3c25d;
}
.ops-log-level.danger {
  color: #ff91a2;
}
.ops-log-level.success {
  color: #80d8ac;
}
.ops-log-message {
  min-width: 0;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.ops-log-message summary {
  color: #98bcef;
  font-size: 10px;
  cursor: pointer;
  white-space: normal;
}
.ops-log-message pre {
  background: #162132;
  color: #c4d3e9;
  border: 1px solid #34435b;
  padding: 10px;
  font-size: 10px;
  margin: 5px 0;
}
.ops-console-run {
  color: #a2c1ff;
  margin-right: 9px;
}
.ops-log-panel > .panel-heading {
  margin-bottom: 14px;
}
.ops-integrity {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  padding: 18px 20px;
}
.ops-integrity-icon {
  height: 43px;
  width: 43px;
}
.ops-integrity h2 {
  font-size: 14px;
}
.ops-integrity-percent {
  display: block;
  font-size: 10px;
  margin-top: 3px;
  color: #4e6b97;
}
.ops-integrity-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(75px, 1fr));
  gap: 10px;
  flex: 0 1 410px;
}
.ops-audit-count {
  padding: 9px 8px;
  min-width: 0;
  text-align: center;
  border-radius: 5px;
  background: #f0f4ff;
}
.ops-audit-count span {
  display: block;
  font-size: 10px;
  color: #5d6d8e;
}
.ops-audit-count strong {
  display: block;
  font-size: 23px;
  line-height: 1.25;
  color: #1b2c5d;
}
.ops-audit-count.success {
  background: #edf9f2;
}
.ops-audit-count.success strong {
  color: #118d50;
}
.ops-audit-count.warning {
  background: #fff9ea;
}
.ops-audit-count.warning strong {
  color: #db9304;
}
.ops-audit-count.danger {
  background: #fff0f3;
}
.ops-audit-count.danger strong {
  color: #d83a57;
}
.ops-filters .panel-heading {
  margin-bottom: 16px;
}
.ops-filters h2 {
  font-size: 14px;
}
.ops-audit-filter-grid {
  display: grid;
  grid-template-columns:
    minmax(135px, 0.85fr) minmax(200px, 1.8fr) minmax(130px, 0.9fr)
    auto;
  align-items: end;
  gap: 12px;
}
.ops-audit-filter-grid label {
  min-width: 0;
  font-size: 11px;
}
.ops-audit-filter-grid input,
.ops-audit-filter-grid select {
  height: 35px;
  font-size: 11px;
}
.ops-search-field {
  display: flex;
  align-items: center;
  min-width: 0;
  position: relative;
}
.ops-search-field > svg {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: #8397b9;
  pointer-events: none;
}
.ops-search-field input {
  padding-left: 33px;
  width: 100%;
}
.ops-audit-filter-grid .ops-inconsistent-toggle {
  grid-column: 1 / -1;
  margin: 0;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 7px;
  font-weight: 400;
  color: var(--muted);
}
.ops-audit-filter-grid .ops-inconsistent-toggle input {
  height: 14px;
  width: 14px;
  margin: 0;
}
.ops-migrations-table {
  padding: 0;
  overflow: hidden;
}
.ops-audit-table-heading {
  padding: 15px 18px;
  margin: 0;
  border-bottom: 1px solid var(--line);
}
.ops-audit-table-heading h2 {
  margin: 0;
  font-size: 14px;
}
.ops-audit-table-heading > span {
  font-size: 11px;
}
.ops-migrations-table :deep(table) {
  font-size: 11px;
}
.ops-migrations-table :deep(th),
.ops-migrations-table :deep(td) {
  padding: 12px 14px;
}
.ops-migrations-table :deep(th:first-child),
.ops-migrations-table :deep(td:first-child) {
  padding-left: 18px;
}
.ops-migrations-table :deep(.row-actions) {
  gap: 8px;
  flex-wrap: nowrap;
}
.ops-migration-name {
  font-size: 10px;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.ops-row-detail {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  font-size: 11px;
  white-space: nowrap;
}
.ops-icon-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #6c7f9f;
  padding: 3px;
  border-radius: 4px;
}
.ops-icon-action:hover {
  background: #eef4ff;
  color: var(--primary);
}
.ops-table-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 14px 18px;
  border-top: 1px solid var(--line);
}
.ops-table-footer small,
.ops-table-footer a {
  font-size: 10px;
}
.ops-table-footer a {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.ops-extra-metrics {
  border-top: 1px solid var(--line);
  padding: 12px 18px;
  font-size: 11px;
}
.ops-extra-metrics summary {
  color: var(--muted);
  cursor: pointer;
}
.ops-migration-title {
  min-width: 0;
  margin: 0;
}
.ops-migration-title code {
  font-size: 13px;
  overflow-wrap: anywhere;
}
.ops-migration-detail .panel-heading {
  align-items: flex-start;
}
@media (max-width: 1100px) {
  .ops-integrity {
    flex-wrap: wrap;
    gap: 14px;
  }
  .ops-integrity-stats {
    flex: 1 1 100%;
  }
  .ops-run-metadata {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px 0;
  }
  .ops-run-metadata > div:nth-child(3) {
    border-left: 0;
  }
  .ops-audit-filter-grid {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr);
  }
  .ops-audit-filter-grid .ops-inconsistent-toggle {
    grid-column: 1 / -1;
  }
}
@media (max-width: 720px) {
  .ops-diagnostics-layout {
    grid-template-columns: minmax(0, 1fr);
  }
  .ops-run-tools {
    align-items: flex-start;
  }
  .ops-run-tools .actions {
    width: 100%;
    flex-wrap: wrap;
  }
  .ops-run-banner {
    padding: 15px;
    align-items: flex-start;
    gap: 12px;
  }
  .ops-run-banner {
    flex-wrap: wrap;
  }
  .ops-run-id {
    display: flex;
    flex-direction: row;
    align-items: center;
    text-align: left;
    gap: 8px;
    width: 100%;
    padding-left: 46px;
  }
  .ops-run-heading h2 {
    font-size: 14px;
  }
  .ops-run-heading p {
    font-size: 11px;
  }
  .ops-status-orb {
    width: 34px;
    height: 34px;
  }
  .ops-log-toolbar {
    flex-wrap: wrap;
    gap: 8px;
  }
  .ops-log-toolbar label {
    width: 100%;
  }
  .ops-console {
    padding: 10px;
  }
  .ops-console li {
    grid-template-columns: 20px minmax(0, 1fr) 58px;
    gap: 1px 7px;
    padding: 7px 0;
    border-bottom: 1px solid #304058;
  }
  .ops-console .ops-line-number {
    grid-row: span 2;
  }
  .ops-console .ops-log-message {
    grid-column: 2 / -1;
  }
  .ops-stage-events li {
    grid-template-columns: 17px minmax(0, 1fr);
  }
  .ops-stage-events small {
    grid-column: 2;
  }
  .ops-migrations-table :deep(.row-actions) {
    flex-wrap: wrap;
  }
  .ops-migrations-table :deep(th),
  .ops-migrations-table :deep(td) {
    width: auto;
  }
  .ops-migrations-table :deep(td:first-child) {
    padding-left: 14px;
  }
  .ops-table-footer {
    align-items: flex-start;
    flex-wrap: wrap;
  }
}
@media (max-width: 440px) {
  .ops-run-metadata {
    grid-template-columns: minmax(0, 1fr);
    gap: 13px;
    padding: 15px;
  }
  .ops-run-metadata > div {
    border: 0 !important;
    padding: 0;
  }
  .ops-audit-filter-grid {
    grid-template-columns: minmax(0, 1fr);
  }
  .ops-audit-filter-grid .ops-inconsistent-toggle {
    flex-wrap: wrap;
  }
  .ops-integrity {
    padding: 16px;
  }
  .ops-integrity-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }
  .ops-stage-metadata {
    grid-template-columns: minmax(0, 1fr);
  }
  .ops-log-heading {
    align-items: flex-start;
  }
  .ops-log-heading .ops-pill {
    font-size: 9px;
    padding: 3px 6px;
  }
}
</style>
