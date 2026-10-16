<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { dateTime, modeLabel, statusLabel } from "../services/format";
import { can, jsonGet, route, state } from "../services/app";
import ActionForm from "../components/ActionForm.vue";
import DataTable from "../components/DataTable.vue";
import Icon from "../components/Icon.vue";

const props = defineProps<{ data: any }>();
const last = computed(
  () => props.data.status?.last_run ?? props.data.lastRun ?? {},
);
const progress = ref<any>(null);
const progressError = ref("");
let timer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;
const active = computed(() =>
  progress.value
    ? !!progress.value.active
    : ["running", "queued"].includes(String(last.value.status).toLowerCase()),
);
const failed = computed(
  () => String(last.value.status).toLowerCase() === "failed",
);
const warning = computed(() => String(props.data.status?.warning || ""));
const enabled = computed(
  () => props.data.status?.enabled === true || props.data.status?.enabled === 1,
);
const revision = computed(() => {
  const value =
    props.data.status?.revision ||
    props.data.versionBar?.application?.git_revision;
  return value && !["N/A", "n/d"].includes(String(value))
    ? String(value)
    : "Não disponível";
});
const versionLabel = computed(() =>
  /^v?\d+\.\d+/.test(revision.value) ? "Versão instalada" : "Revisão instalada",
);
const packageVersion = computed(() => {
  const value = props.data.versionBar?.updater?.installed;
  return value && value !== "n/d" ? String(value) : "";
});
const overview = computed(() => {
  if (active.value)
    return {
      title: "Atualização em andamento",
      description: "Acompanhe a execução antes de iniciar outra operação.",
      icon: "refresh",
      tone: "running",
    };
  if (warning.value)
    return {
      title: "Verificação indisponível",
      description: warning.value,
      icon: "alert",
      tone: "warning",
    };
  if (failed.value)
    return {
      title: "Última execução com falha",
      description:
        "Consulte o diagnóstico e revise a falha antes de continuar.",
      icon: "alert",
      tone: "warning",
    };
  if (props.data.status?.enabled === undefined)
    return {
      title: "É necessário consultar o ambiente",
      description: "O estado do atualizador não está disponível.",
      icon: "server",
      tone: "neutral",
    };
  if (!enabled.value)
    return {
      title: "Atualizador desabilitado",
      description:
        "A execução de atualizações está desabilitada na configuração.",
      icon: "server",
      tone: "neutral",
    };
  return {
    title: "Atualizador disponível",
    description: "Integridade completa do ambiente ainda não verificada.",
    icon: "server",
    tone: "neutral",
  };
});
const recentRuns = computed(() => (props.data.runs || []).slice(0, 6));
const percent = computed(() =>
  Math.max(0, Math.min(100, Number(progress.value?.progress || 0))),
);
const backupConfigured = computed(
  () => props.data.activeProfile?.backup_enabled,
);
function isEnabled(value: unknown): boolean {
  return value === true || value === 1 || value === "1";
}
function runType(row: any): string {
  if (isEnabled(row.dry_run) || String(row.status).toLowerCase() === "dry_run")
    return "Simulação";
  const names: Record<string, string> = {
    update: "Atualização",
    rollback: "Rollback",
    manual_backup: "Backup",
    manual_migration: "Migration",
    backup: "Backup",
  };
  return names[String(row.type)] || "Operação";
}
function duration(row: any): string {
  if (!row.started_at || !row.finished_at) return "—";
  const seconds = Math.floor(
    (new Date(row.finished_at).getTime() - new Date(row.started_at).getTime()) /
      1000,
  );
  if (!Number.isFinite(seconds) || seconds < 0) return "—";
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}
async function pollProgress() {
  if (!can("updates.run") || !route("updater.updates.progress.status")) return;
  clearTimeout(timer);
  try {
    progress.value = await jsonGet(route("updater.updates.progress.status"));
    progressError.value = "";
  } catch (error) {
    progressError.value = (error as Error).message;
  } finally {
    if (!disposed)
      timer = setTimeout(pollProgress, document.hidden ? 12000 : 4000);
  }
}
onMounted(pollProgress);
onUnmounted(() => {
  disposed = true;
  clearTimeout(timer);
});
</script>
<template>
  <div class="dashboard stack">
    <section
      class="panel environment-overview"
      aria-labelledby="environment-title"
    >
      <h2 id="environment-title">Visão geral do ambiente</h2>
      <div class="environment-summary">
        <div class="environment-health">
          <span class="health-symbol" :class="overview.tone"
            ><Icon :name="overview.icon" :size="30"
          /></span>
          <div>
            <h3>{{ overview.title }}</h3>
            <p>{{ overview.description }}</p>
          </div>
        </div>
        <div class="installed-version">
          <span class="summary-label">{{ versionLabel }}</span>
          <div class="version-value">
            <strong
              :class="{ revision: versionLabel === 'Revisão instalada' }"
              >{{ revision }}</strong
            ><span class="badge" :class="enabled ? 'success' : 'pending'">{{
              data.status?.enabled === undefined
                ? "Não informado"
                : enabled
                  ? "Ativo"
                  : "Inativo"
            }}</span>
          </div>
          <span class="summary-label last-label">Última execução</span>
          <a
            v-if="last.id && can('runs.view') && route('updater.runs.show')"
            :href="route('updater.runs.show', { id: last.id })"
            class="last-run-link"
            >{{ dateTime(last.finished_at || last.started_at) }}
            <span class="last-result">· {{ statusLabel(last.status) }}</span></a
          >
          <strong v-else-if="last.id" class="last-run-link">{{
            dateTime(last.finished_at || last.started_at)
          }}</strong>
          <span v-else>Nenhuma execução registrada</span>
        </div>
        <div class="overview-action">
          <a
            v-if="can('updates.view') && route('updater.section')"
            class="button primary"
            :href="route('updater.section', { section: 'updates' })"
            >Ver atualizações <Icon name="arrow-right" :size="17"
          /></a>
          <a
            v-else-if="can('runs.view') && route('updater.section')"
            class="button primary"
            :href="route('updater.section', { section: 'runs' })"
            >Ver execuções <Icon name="arrow-right" :size="17"
          /></a>
          <span v-else class="muted">Acesso de consulta</span>
        </div>
      </div>
      <dl class="environment-context">
        <div>
          <dt>Perfil</dt>
          <dd>{{ data.activeProfile?.name || "Padrão" }}</dd>
        </div>
        <div>
          <dt>Fonte</dt>
          <dd>{{ data.activeSource?.name || "Repositório local" }}</dd>
        </div>
        <div>
          <dt>Canal</dt>
          <dd>{{ data.status?.channel || "Não informado" }}</dd>
        </div>
        <div>
          <dt>Modo</dt>
          <dd>{{ modeLabel(data.status?.mode) }}</dd>
        </div>
        <div v-if="packageVersion">
          <dt>Updater</dt>
          <dd>{{ packageVersion }}</dd>
        </div>
      </dl>
    </section>

    <section
      v-if="active || progressError"
      class="panel compact-progress"
      aria-labelledby="dashboard-progress-title"
    >
      <div class="panel-heading">
        <h2 id="dashboard-progress-title">
          {{ active ? "Atualização em andamento" : "Progresso indisponível" }}
        </h2>
        <span v-if="active" class="badge running">{{
          statusLabel(progress?.run?.status || last.status)
        }}</span>
      </div>
      <p v-if="progressError" class="notice warning" role="alert">
        {{ progressError }}
        <button class="text-button" type="button" @click="pollProgress">
          Tentar novamente
        </button>
      </p>
      <template v-else
        ><p role="status">
          {{
            progress?.message ||
            (route("updater.updates.progress.status")
              ? "Consultando o progresso da execução…"
              : "Consulte os detalhes para acompanhar a execução.")
          }}
        </p>
        <progress
          :value="percent"
          max="100"
          aria-label="Progresso da atualização"
        >
          {{ percent }}%
        </progress></template
      >
      <a
        v-if="
          can('runs.view') &&
          (progress?.run?.id || last.id) &&
          route('updater.runs.show')
        "
        class="text-button"
        :href="route('updater.runs.show', { id: progress?.run?.id || last.id })"
        >Acompanhar execução <Icon name="arrow-right" :size="15"
      /></a>
    </section>

    <div class="dashboard-columns">
      <section class="panel recent-runs" aria-labelledby="recent-runs-title">
        <div class="panel-heading">
          <h2 id="recent-runs-title">Execuções recentes</h2>
          <a
            v-if="can('runs.view') && route('updater.section')"
            class="text-button history-link"
            :href="route('updater.section', { section: 'runs' })"
            >Todas as execuções <Icon name="arrow-right" :size="15"
          /></a>
        </div>
        <DataTable
          class="dashboard-runs-table"
          :class="{ 'has-recent-runs': recentRuns.length > 0 }"
          :rows="recentRuns"
          :columns="[
            { key: 'started_at', label: 'Data e hora' },
            {
              key: 'type',
              label: 'Tipo',
              format: (_value: any, row: any) => runType(row),
            },
            {
              key: 'revision_after',
              label: 'Versão',
              format: (value: any, row: any) =>
                String(row.target_tag || value || row.revision_before || '—'),
            },
            { key: 'status', label: 'Status' },
            {
              key: 'duration',
              label: 'Duração',
              format: (_value: any, row: any) => duration(row),
            },
          ]"
          :search="false"
          empty="Nenhuma execução registrada."
        >
          <template #actions="{ row }"
            ><a
              v-if="can('runs.view') && route('updater.runs.show')"
              class="run-detail"
              :href="route('updater.runs.show', { id: row.id })"
              :aria-label="`Ver detalhes da execução #${row.id}`"
              ><Icon name="chevron-right" :size="17" /></a
          ></template>
        </DataTable>
        <ul
          v-if="recentRuns.length"
          class="mobile-runs"
          aria-label="Execuções recentes"
        >
          <li v-for="row in recentRuns" :key="row.id" class="mobile-run">
            <div class="mobile-run-content">
              <div class="mobile-run-primary">
                <time :datetime="row.started_at || undefined">{{
                  dateTime(row.started_at)
                }}</time>
                <span class="badge" :class="String(row.status).toLowerCase()">{{
                  statusLabel(row.status)
                }}</span>
              </div>
              <div class="mobile-run-meta">
                <span>{{ runType(row) }}</span>
                <span
                  class="mobile-run-version"
                  :title="
                    String(
                      row.target_tag ||
                        row.revision_after ||
                        row.revision_before ||
                        '',
                    )
                  "
                  >{{
                    row.target_tag ||
                    row.revision_after ||
                    row.revision_before ||
                    "—"
                  }}</span
                >
                <span :aria-label="`Duração: ${duration(row)}`">{{
                  duration(row)
                }}</span>
              </div>
            </div>
            <a
              v-if="can('runs.view') && route('updater.runs.show')"
              class="run-detail"
              :href="route('updater.runs.show', { id: row.id })"
              :aria-label="`Ver detalhes da execução #${row.id}`"
              ><Icon name="chevron-right" :size="17"
            /></a>
          </li>
        </ul>
      </section>

      <aside
        class="panel environment-checks"
        aria-labelledby="environment-checks-title"
      >
        <h2 id="environment-checks-title">Verificações do ambiente</h2>
        <div class="environment-check">
          <span
            class="check-symbol"
            :class="
              backupConfigured === undefined
                ? 'unknown'
                : isEnabled(backupConfigured)
                  ? 'good'
                  : 'attention'
            "
            ><Icon
              :name="
                backupConfigured === undefined
                  ? 'database'
                  : isEnabled(backupConfigured)
                    ? 'check'
                    : 'alert'
              "
              :size="19"
          /></span>
          <div>
            <h3>Backups</h3>
            <p>
              {{
                backupConfigured === undefined
                  ? "Política não informada."
                  : isEnabled(backupConfigured)
                    ? "Habilitados no perfil ativo."
                    : "Desabilitados no perfil ativo."
              }}
            </p>
            <p class="check-note">Último backup não consultado.</p>
          </div>
          <a
            v-if="can('backups.manage') && route('updater.section')"
            :href="route('updater.section', { section: 'backups' })"
            aria-label="Consultar backups"
            ><Icon name="chevron-right" :size="18"
          /></a>
        </div>
        <div class="environment-check">
          <span class="check-symbol unknown"
            ><Icon name="refresh" :size="19"
          /></span>
          <div>
            <h3>Lock de atualização</h3>
            <p>
              {{
                active
                  ? "Há uma execução em andamento."
                  : "Consulte o estado do bloqueio."
              }}
            </p>
          </div>
          <a
            v-if="can('settings.manage') && route('updater.section')"
            :href="route('updater.section', { section: 'security' })"
            aria-label="Consultar bloqueio de atualização"
            ><Icon name="chevron-right" :size="18"
          /></a>
        </div>
        <div class="environment-check">
          <span class="check-symbol unknown"
            ><Icon name="shield" :size="19"
          /></span>
          <div>
            <h3>Segurança</h3>
            <p>Análise de vulnerabilidades não disponível.</p>
          </div>
          <a
            v-if="can('settings.manage') && route('updater.section')"
            :href="route('updater.section', { section: 'security' })"
            aria-label="Consultar configurações de segurança"
            ><Icon name="chevron-right" :size="18"
          /></a>
        </div>
      </aside>
    </div>
    <div
      v-if="failed && can('runs.view') && route('updater.runs.show')"
      class="notice danger failure-notice"
    >
      <Icon name="alert" :size="21" />
      <div>
        <strong>A última operação precisa de atenção.</strong>
        <p>Consulte o diagnóstico para entender a etapa e o motivo da falha.</p>
      </div>
      <a
        class="button danger"
        :href="route('updater.runs.show', { id: last.id })"
        >Abrir diagnóstico #{{ last.id }}</a
      >
    </div>
    <section
      v-if="can('maintenance.manage') || can('updates.rollback')"
      class="panel"
    >
      <div class="panel-heading">
        <h2>Controles operacionais</h2>
        <span class="badge">Ações auditadas</span>
      </div>
      <div class="two-column">
        <div v-if="can('maintenance.manage')">
          <h3>Modo de manutenção</h3>
          <p class="muted">
            A entrada em manutenção interrompe temporariamente o acesso dos
            usuários à aplicação. Confirme a operação com a palavra solicitada.
          </p>
          <ActionForm
            :action="route('updater.maintenance.on')"
            confirm="Colocar a aplicação em manutenção agora? Os usuários verão a página de indisponibilidade."
            ><label
              >Digite MANTENCAO para ativar<input
                name="maintenance_confirmation"
                required
                pattern="MANTENCAO"
                autocomplete="off" /></label
            ><label
              >Código 2FA, quando exigido<input
                name="maintenance_2fa_code"
                :required="
                  !!state.context.security?.maintenanceTwoFactorRequired
                "
                autocomplete="one-time-code" /></label
            ><button class="button primary" type="submit">
              Ativar manutenção
            </button></ActionForm
          >
          <details class="section-divider">
            <summary>Retirar aplicação da manutenção</summary>
            <ActionForm
              :action="route('updater.maintenance.off')"
              confirm="Retirar a aplicação do modo de manutenção?"
              ><label
                >Digite MANTENCAO para desativar<input
                  name="maintenance_confirmation"
                  required
                  autocomplete="off" /></label
              ><label
                >Código 2FA, quando exigido<input
                  name="maintenance_2fa_code"
                  :required="
                    !!state.context.security?.maintenanceTwoFactorRequired
                  "
                  autocomplete="one-time-code" /></label
              ><button class="button subtle">
                Desativar manutenção
              </button></ActionForm
            >
          </details>
        </div>
        <div v-if="can('updates.rollback')">
          <h3>Rollback</h3>
          <p class="muted">
            Execute o fluxo de rollback configurado. Revise os backups e o
            diagnóstico antes de continuar.
          </p>
          <ActionForm
            :action="route('updater.trigger.rollback')"
            confirm="Executar rollback conforme a configuração atual? A operação pode alterar o código e os dados da aplicação."
            ><button class="button danger" type="submit">
              Executar rollback
            </button></ActionForm
          >
        </div>
      </div>
    </section>
  </div>
</template>
<style scoped>
.dashboard {
  gap: 24px;
}
.environment-overview {
  padding: 22px 24px 0;
}
.environment-overview > h2 {
  margin: 0 0 24px;
  font-size: 18px;
}
.environment-summary {
  display: grid;
  grid-template-columns: minmax(0, 1.6fr) minmax(0, 1.15fr) auto;
  align-items: center;
  padding: 0 0 25px;
  gap: 24px;
}
.environment-health {
  display: flex;
  align-items: center;
  gap: 18px;
  min-width: 0;
}
.health-symbol {
  display: inline-flex;
  flex-shrink: 0;
  justify-content: center;
  align-items: center;
  width: 62px;
  height: 62px;
  border-radius: 50%;
  background: var(--primary-soft);
  color: var(--primary);
}
.health-symbol.neutral {
  background: var(--soft);
  color: var(--muted);
}
.health-symbol.warning {
  background: var(--warning-soft);
  color: var(--warning);
}
.environment-health h3 {
  font-size: 24px;
  line-height: 1.25;
  letter-spacing: -0.5px;
  margin: 0 0 7px;
}
.environment-health p {
  margin: 0;
  color: var(--muted);
  font-size: 13px;
  line-height: 1.5;
}
.installed-version {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  border-left: 1px solid var(--line);
  padding-left: 28px;
  min-width: 0;
}
.summary-label {
  color: var(--muted);
  font-size: 12px;
}
.version-value {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  margin: 3px 0 15px;
}
.version-value > strong {
  font-size: 28px;
  line-height: 1.2;
  overflow-wrap: anywhere;
  letter-spacing: -0.6px;
}
.version-value > strong.revision {
  font-size: 22px;
}
.last-label {
  margin-bottom: 3px;
}
.last-run-link {
  color: var(--ink);
  font-size: 12px;
  font-weight: 550;
}
.last-result {
  display: inline-block;
  color: var(--muted);
  font-weight: 400;
}
.overview-action {
  border-left: 1px solid var(--line);
  padding: 30px 0 30px 24px;
}
.overview-action .button {
  white-space: nowrap;
  display: inline-flex;
  gap: 13px;
  min-height: 42px;
}
.environment-context {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 24px;
  margin: 0;
  padding: 12px 0;
  border-top: 1px solid var(--line);
  font-size: 11px;
}
.environment-context > div {
  display: flex;
  gap: 6px;
  min-width: 0;
}
.environment-context dt {
  color: var(--muted);
}
.environment-context dd {
  margin: 0;
  overflow-wrap: anywhere;
  font-weight: 500;
}
.dashboard-columns {
  display: grid;
  grid-template-columns: minmax(0, 2.35fr) minmax(260px, 1fr);
  gap: 18px;
  align-items: start;
}
.recent-runs {
  padding: 0;
  overflow: hidden;
}
.recent-runs > .panel-heading {
  padding: 20px 22px;
  margin: 0;
  border-bottom: 1px solid var(--line);
}
.dashboard-columns h2 {
  font-size: 17px;
  line-height: 1.4;
}
.history-link {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  font-size: 11px;
  white-space: nowrap;
}
.run-detail {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  min-height: 28px;
}
.mobile-runs {
  display: none;
}
.recent-runs :deep(.row-actions) {
  min-width: 0;
}
.recent-runs :deep(td) {
  padding: 15px 12px;
}
.recent-runs :deep(th) {
  padding: 12px;
}
.recent-runs :deep(table) {
  font-size: 11px;
}
.recent-runs :deep(th:last-child .sort-button) {
  display: none;
}
.environment-checks {
  padding: 20px;
}
.environment-checks > h2 {
  margin: 0 0 10px;
}
.environment-check {
  display: grid;
  grid-template-columns: 32px minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
  border-top: 1px solid var(--line);
  padding: 17px 0;
}
.environment-check:last-child {
  padding-bottom: 2px;
}
.check-symbol {
  display: inline-flex;
  justify-content: center;
  align-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  color: var(--primary);
  background: var(--primary-soft);
}
.check-symbol.unknown {
  color: var(--muted);
  background: var(--soft);
}
.check-symbol.good {
  color: #fff;
  background: #0fa55a;
}
.check-symbol.attention {
  color: var(--warning);
  background: var(--warning-soft);
}
.environment-check h3 {
  font-size: 12px;
  margin: 0 0 3px;
}
.environment-check p {
  font-size: 11px;
  color: var(--muted);
  margin: 0;
  line-height: 1.4;
}
.environment-check .check-note {
  font-size: 10px;
  margin-top: 3px;
}
.environment-check > a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 24px;
  min-height: 32px;
}
.compact-progress {
  padding: 17px 20px;
}
.compact-progress .panel-heading {
  margin-bottom: 7px;
}
.compact-progress h2 {
  font-size: 16px;
}
.compact-progress p {
  font-size: 12px;
  margin: 0 0 8px;
}
.compact-progress progress {
  width: 100%;
  height: 7px;
  accent-color: var(--primary);
}
.compact-progress > a {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  font-size: 12px;
  margin-top: 7px;
}
.failure-notice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 14px;
}
.failure-notice p {
  margin: 3px 0 0;
}
.failure-notice > div {
  flex: 1;
}
@media (max-width: 1279px) {
  .environment-summary {
    grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
  }
  .overview-action {
    grid-column: 1 / -1;
    border-left: 0;
    border-top: 1px solid var(--line);
    padding: 18px 0 0;
  }
  .dashboard-columns {
    grid-template-columns: minmax(0, 1.75fr) minmax(240px, 1fr);
  }
}
@media (max-width: 1050px) {
  .dashboard-columns {
    grid-template-columns: 1fr;
  }
  .environment-checks {
    grid-row: 1;
  }
  .environment-check {
    padding: 13px 0;
  }
}
@media (max-width: 600px) {
  .dashboard {
    gap: 14px;
  }
  .environment-overview {
    padding: 16px 16px 0;
  }
  .environment-overview > h2 {
    font-size: 15px;
    margin-bottom: 19px;
  }
  .environment-summary {
    grid-template-columns: 1fr;
    gap: 19px;
    padding-bottom: 16px;
  }
  .environment-health {
    gap: 13px;
  }
  .health-symbol {
    width: 45px;
    height: 45px;
  }
  .environment-health h3 {
    font-size: 19px;
    letter-spacing: -0.25px;
    margin-bottom: 4px;
  }
  .environment-health p {
    font-size: 11px;
  }
  .installed-version {
    padding: 0;
    border: 0;
  }
  .version-value {
    margin-bottom: 12px;
    gap: 10px;
  }
  .version-value > strong {
    font-size: 27px;
  }
  .version-value > strong.revision {
    font-size: 22px;
  }
  .summary-label {
    font-size: 11px;
  }
  .overview-action {
    grid-column: auto;
    padding: 0;
    border: 0;
  }
  .overview-action .button {
    width: 100%;
    min-height: 39px;
    justify-content: center;
  }
  .environment-context {
    font-size: 10px;
    gap: 5px 14px;
    padding: 10px 0;
  }
  .environment-context > div {
    gap: 4px;
  }
  .environment-context > div:first-child,
  .environment-context > div:nth-child(2) {
    width: 100%;
  }
  .dashboard-columns {
    gap: 14px;
  }
  .environment-checks {
    padding: 15px 16px;
  }
  .dashboard-columns h2 {
    font-size: 15px;
  }
  .environment-checks > h2 {
    margin-bottom: 9px;
  }
  .environment-check h3 {
    font-size: 12px;
  }
  .recent-runs > .panel-heading {
    padding: 15px 16px;
    gap: 8px;
    align-items: center;
  }
  .history-link {
    font-size: 10px;
    gap: 4px;
    white-space: normal;
    text-align: right;
  }
  .history-link svg {
    flex-shrink: 0;
  }
  .dashboard-runs-table.has-recent-runs {
    display: none;
  }
  .mobile-runs {
    display: block;
    list-style: none;
    padding: 0 14px;
    margin: 0;
  }
  .mobile-run {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 69px;
    padding: 12px 0;
    border-bottom: 1px solid var(--line);
  }
  .mobile-run:last-child {
    border-bottom: 0;
  }
  .mobile-run-content {
    flex: 1;
    min-width: 0;
  }
  .mobile-run-primary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    font-size: 11px;
    line-height: 1.3;
  }
  .mobile-run-primary time {
    white-space: nowrap;
    font-weight: 500;
  }
  .mobile-run-primary .badge {
    flex-shrink: 0;
    font-size: 9px;
    padding: 2px 6px;
  }
  .mobile-run-meta {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 8px;
    align-items: start;
    margin-top: 6px;
    color: var(--muted);
    font-size: 10px;
    line-height: 1.4;
  }
  .mobile-run-meta > span:first-child,
  .mobile-run-meta > span:last-child {
    white-space: nowrap;
  }
  .mobile-run-version {
    overflow-wrap: anywhere;
  }
  .mobile-run > .run-detail {
    flex-shrink: 0;
    min-width: 20px;
    min-height: 34px;
  }
  .compact-progress {
    padding: 15px 16px;
  }
  .failure-notice > div {
    min-width: 175px;
  }
  .failure-notice .button {
    width: 100%;
  }
}
</style>
