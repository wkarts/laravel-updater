<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import { can, jsonGet, route } from "../services/app";
import { dateTime, modeLabel } from "../services/format";
import ActionForm from "../components/ActionForm.vue";
import Icon from "../components/Icon.vue";
import ProgressPanel from "../components/ProgressPanel.vue";

interface VersionRow {
  tag: string;
  name?: string;
  published_at?: string | null;
  prerelease?: boolean | null;
}
type VersionKind = "stable" | "prerelease" | "unknown";
interface NoteBlock {
  kind: "heading" | "paragraph" | "list" | "code" | "quote";
  text: string;
  items?: string[];
  ordered?: boolean;
}
const props = defineProps<{ data: any }>();
const selectedProfile = ref(props.data.activeProfile?.id || props.data.profiles?.[0]?.id || "");
const selectedSource = ref(props.data.activeSource?.id || props.data.sources?.[0]?.id || "");
const mode = ref(props.data.defaultUpdateMode || "merge");
const targetTag = ref("");
const targetBranch = ref("");
const tagField = ref<HTMLInputElement>();
const notes = ref<any>(null);
const loading = ref(false);
const noteError = ref("");
const selection = ref("simulate");
const search = ref("");
const filter = ref<"all" | "stable" | "prerelease">("all");
const notesCache = new Map<string, { until: number; value: any }>();
let timeout: ReturnType<typeof setTimeout>;
let request = 0;
const status = computed(() => props.data.statusCheck || {});
const hasExecutionPrerequisites = computed(() => !!props.data.profiles?.length && !!props.data.sources?.length);
const legacyMode = computed(() => !route("updater.updates.execute") && !!route("updater.trigger.update"));
const legacyProfileSimulates = computed(() => [true, 1, "1", "true"].includes(props.data.activeProfile?.dry_run));
const sourceSelection = computed(() => (props.data.sources || []).find((item: any) => String(item.id) === String(selectedSource.value)));
const catalogMatchesSource = computed(() => !props.data.activeSource?.id || String(selectedSource.value) === String(props.data.activeSource.id));
const catalogRows = computed<VersionRow[]>(() => {
  if (!catalogMatchesSource.value) return [];
  const seen = new Set<string>();
  return (props.data.availableTags || []).flatMap((item: any) => {
    const tag = typeof item === "string" ? item : String(item?.tag || item?.tag_name || "");
    if (!tag || seen.has(tag)) return [];
    seen.add(tag);
    return [{ ...(typeof item === "object" && item !== null ? item : {}), tag }];
  });
});
function suffixIsPrerelease(tag: string): boolean {
  return /(?:^|[-.])(?:alpha|beta|rc|pre|preview|dev|nightly|snapshot)(?:[.\-]?\d*)?(?:[.+\-][A-Za-z0-9.\-]+)?$/i.test(tag);
}
function verifiedRelease(value: any): boolean {
  return !!value && (value.availability === "available" || !!value.published_at || (!!value.name && value.name !== value.tag));
}
function metadataFor(row: VersionRow): any {
  const resolved = notes.value?.tag === row.tag ? notes.value : null;
  if (!resolved) return row;
  return {
    ...row,
    ...resolved,
    name: resolved.name && resolved.name !== resolved.tag ? resolved.name : row.name || resolved.name,
    published_at: resolved.published_at || row.published_at || null,
    prerelease: verifiedRelease(resolved) ? resolved.prerelease : typeof row.prerelease === "boolean" ? row.prerelease : resolved.prerelease,
  };
}
function versionKind(row: VersionRow): VersionKind {
  const value = metadataFor(row);
  if (value.prerelease === true) return "prerelease";
  if (value.prerelease === false && (typeof row.prerelease === "boolean" || verifiedRelease(value))) return "stable";
  return suffixIsPrerelease(row.tag) ? "prerelease" : "unknown";
}
function kindLabel(kind: VersionKind): string {
  return kind === "stable" ? "Estável" : kind === "prerelease" ? "Pré-lançamento" : "Tag Git";
}
const versionCounts = computed(() => ({
  all: catalogRows.value.length,
  stable: catalogRows.value.filter((row) => versionKind(row) === "stable").length,
  prerelease: catalogRows.value.filter((row) => versionKind(row) === "prerelease").length,
}));
const filteredVersions = computed(() => catalogRows.value.filter((row) => {
  const query = search.value.trim().toLocaleLowerCase("pt-BR");
  const value = metadataFor(row);
  return (!query || `${row.tag} ${value.name || ""}`.toLocaleLowerCase("pt-BR").includes(query))
    && (filter.value === "all" || versionKind(row) === filter.value);
}));
const selectedKind = computed(() => versionKind(catalogRows.value.find((row) => row.tag === targetTag.value) || { tag: targetTag.value }));
const selectionSummary = computed(() => {
  const profile = (props.data.profiles || []).find((item: any) => String(item.id) === String(selectedProfile.value));
  return `Perfil: ${profile?.name || "não selecionado"}. Fonte: ${sourceSelection.value?.name || "não selecionada"}. Modo: ${modeLabel(mode.value)}${mode.value === "tag" ? ". Tag: " + (targetTag.value || "não informada") : ". Branch: " + (targetBranch.value || sourceSelection.value?.branch || "configurada no servidor")}.`;
});
watch(legacyProfileSimulates, (enabled) => { if (enabled && legacyMode.value) selection.value = "simulate"; });
function chooseVersion(tag: string) {
  mode.value = "tag";
  targetTag.value = tag;
}
async function focusManualTag() {
  mode.value = "tag";
  await nextTick();
  tagField.value?.focus();
  tagField.value?.scrollIntoView({ block: "center", behavior: "smooth" });
}
function loadNotes(force = false) {
  const value = targetTag.value.trim();
  const sourceId = selectedSource.value;
  const id = ++request;
  const cacheKey = `${sourceId}\0${value}`;
  clearTimeout(timeout);
  notes.value = null;
  noteError.value = "";
  loading.value = false;
  if (!value) return;
  const endpoint = route("updater.release-notes", { tag: value, source_id: sourceId });
  if (!endpoint) { noteError.value = "A consulta de notas não está disponível nesta instalação."; return; }
  const cached = notesCache.get(cacheKey);
  if (!force && cached && cached.until > Date.now()) { notes.value = cached.value; return; }
  loading.value = true;
  timeout = setTimeout(async () => {
    try {
      const payload = await jsonGet(endpoint);
      if (payload?.tag !== value) throw new Error("Tag divergente");
      if (id === request && value === targetTag.value.trim() && sourceId === selectedSource.value) {
        notes.value = payload;
        notesCache.set(cacheKey, { until: Date.now() + (payload.availability === "available" ? 300000 : 60000), value: payload });
      }
    } catch {
      if (id === request) noteError.value = "Não foi possível consultar as notas desta tag.";
    } finally {
      if (id === request) loading.value = false;
    }
  }, force ? 0 : 350);
}
watch([targetTag, selectedSource], () => loadNotes());
onBeforeUnmount(() => { clearTimeout(timeout); ++request; });
const noteBlocks = computed<NoteBlock[]>(() => {
  const blocks: NoteBlock[] = [];
  let paragraph: string[] = [];
  let code: string[] | null = null;
  const flush = () => { if (paragraph.length) blocks.push({ kind: "paragraph", text: paragraph.join("\n") }); paragraph = []; };
  for (const line of String(notes.value?.notes || "").split(/\r?\n/)) {
    if (/^\s*```/.test(line)) {
      flush();
      if (code === null) code = []; else { blocks.push({ kind: "code", text: code.join("\n") }); code = null; }
      continue;
    }
    if (code !== null) { code.push(line); continue; }
    if (!line.trim()) { flush(); continue; }
    const heading = /^#{1,6}\s+(.+)$/.exec(line);
    if (heading) { flush(); blocks.push({ kind: "heading", text: heading[1] || "" }); continue; }
    const list = /^\s*([-*+] |\d+[.)] )(.+)$/.exec(line);
    if (list) {
      flush();
      const ordered = /^\d/.test((list[1] || "").trim());
      const previous = blocks[blocks.length - 1];
      if (previous?.kind === "list" && previous.ordered === ordered) previous.items?.push(list[2] || "");
      else blocks.push({ kind: "list", text: "", ordered, items: [list[2] || ""] });
      continue;
    }
    if (/^>\s?/.test(line)) { flush(); blocks.push({ kind: "quote", text: line.replace(/^>\s?/, "") }); continue; }
    paragraph.push(line);
  }
  flush();
  if (code !== null) blocks.push({ kind: "code", text: code.join("\n") });
  return blocks;
});
</script>
<template>
  <div class="stack updates-workspace">
    <div class="updates-guidance">
      <Icon name="shield" :size="18" />
      <p>{{ legacyMode ? "Consulte as versões e revise a configuração da instalação antes de executar." : "Revise as notas da versão, simule a operação e confirme quando estiver pronto para atualizar." }}</p>
      <a v-if="route('updater.settings.api-docs') && can('settings.manage')" :href="route('updater.settings.api-docs')">Documentação <Icon name="external" :size="14" /></a>
    </div>
    <section v-if="data.recoveryState?.active" class="panel recovery-panel">
      <div class="panel-heading"><h2>Execução em andamento #{{ data.recoveryState.run?.id }}</h2><span class="badge pending">Em execução</span></div>
      <p>{{ data.recoveryState.reason || "O executor apresenta sinais de atividade." }}</p>
      <p class="muted">Etapa: {{ data.recoveryState.current_step || "—" }} · Última atividade: {{ data.recoveryState.run?.heartbeat_at || "—" }}</p>
      <ActionForm v-if="data.recoveryState.recoverable && can('updates.run')" :action="route('updater.updates.recover')" confirm="Encerrar esta execução órfã como falha e liberar o bloqueio e a manutenção?"><button class="button danger">Recuperar execução interrompida</button></ActionForm>
    </section>
    <div class="version-workspace">
      <section class="panel version-catalog" aria-labelledby="available-versions-heading">
        <div class="catalog-heading"><h2 id="available-versions-heading">Versões disponíveis</h2><span class="catalog-count">{{ catalogRows.length }} tags</span></div>
        <p class="muted catalog-description">Selecione uma versão do catálogo ou informe uma tag exata.</p>
        <div class="version-search"><Icon name="search" :size="17" /><label class="sr-only" for="version-search">Buscar versão ou tag</label><input id="version-search" v-model="search" type="search" placeholder="Buscar versão ou tag" autocomplete="off" /></div>
        <div class="version-filters" role="group" aria-label="Filtrar versões">
          <button type="button" :class="{ active: filter === 'all' }" :aria-pressed="filter === 'all'" @click="filter = 'all'">Todas <span>({{ versionCounts.all }})</span></button>
          <button type="button" :class="{ active: filter === 'stable' }" :aria-pressed="filter === 'stable'" @click="filter = 'stable'">Estáveis <span>({{ versionCounts.stable }})</span></button>
          <button type="button" :class="{ active: filter === 'prerelease' }" :aria-pressed="filter === 'prerelease'" @click="filter = 'prerelease'">Pré-lançamentos <span>({{ versionCounts.prerelease }})</span></button>
        </div>
        <p v-if="!catalogMatchesSource" class="catalog-context notice warning">O catálogo recebido pertence à fonte ativa {{ data.activeSource?.name || 'da instalação' }}. Para a fonte selecionada, informe a tag exata na configuração abaixo.</p>
        <ul v-if="filteredVersions.length" class="version-list" aria-label="Catálogo de versões">
          <li v-for="version in filteredVersions" :key="version.tag">
            <button type="button" class="version-row" :class="{ selected: targetTag === version.tag }" :aria-pressed="targetTag === version.tag" @click="chooseVersion(version.tag)">
              <span class="version-row-main"><span class="version-title"><strong>{{ version.tag }}</strong><span class="badge" :class="versionKind(version) === 'stable' ? 'success' : versionKind(version) === 'prerelease' ? 'pending' : ''">{{ kindLabel(versionKind(version)) }}</span></span><span class="version-description">{{ metadataFor(version).name && metadataFor(version).name !== version.tag ? metadataFor(version).name : versionKind(version) === 'unknown' ? 'Classificação da release não informada' : verifiedRelease(metadataFor(version)) ? 'Metadados de release disponíveis' : 'Pré-lançamento identificado pelo sufixo' }}</span></span>
              <span class="version-date">{{ metadataFor(version).published_at ? dateTime(metadataFor(version).published_at) : 'Sem data de release' }}</span><Icon name="chevron-right" :size="17" />
            </button>
          </li>
        </ul>
        <div v-else class="catalog-empty" role="status"><Icon name="search" :size="26" /><h3>{{ !catalogRows.length ? 'Nenhuma tag listada' : 'Nenhuma versão neste filtro' }}</h3><p>{{ !catalogMatchesSource ? 'Este catálogo não corresponde à fonte selecionada. Informe a tag exata na configuração.' : !catalogRows.length ? 'Nenhuma tag foi recebida nesta consulta. Você pode informar uma tag exata.' : 'Altere a busca ou consulte todas as tags. Versões sem metadados não são classificadas como estáveis.' }}</p><button v-if="search || filter !== 'all'" type="button" class="button subtle" @click="search = ''; filter = 'all'">Limpar filtros</button></div>
        <div class="catalog-footer"><p>O catálogo mantém o limite recebido do repositório. Datas e classificação só aparecem quando disponíveis.</p><button v-if="!legacyMode && can('updates.run')" type="button" class="manual-tag-button" @click="focusManualTag">Informar uma tag exata <Icon name="arrow-right" :size="14" /></button><label v-if="legacyMode || !can('updates.run')" class="manual-notes-label">Tag para consultar notas<input v-model="targetTag" maxlength="120" autocomplete="off" placeholder="Ex.: v1.2.3-rc.1" /></label></div>
      </section>
      <section class="panel version-notes" aria-labelledby="release-notes-heading" :aria-busy="loading">
        <div class="catalog-heading"><h2 id="release-notes-heading">Notas desta versão</h2><span v-if="targetTag && selectedKind !== 'unknown'" class="badge" :class="selectedKind === 'stable' ? 'success' : 'pending'">{{ kindLabel(selectedKind) }}</span></div>
        <div v-if="!targetTag" class="notes-placeholder"><span class="notes-placeholder-icon"><Icon name="file" :size="30" /></span><h3>Selecione uma versão para revisar</h3><p>As notas correspondem à tag e à fonte escolhidas. Escolha uma versão à esquerda ou informe uma tag exata na configuração.</p></div>
        <template v-else>
          <div class="release-title"><h3>{{ targetTag }}</h3><span v-if="!catalogRows.some((row) => row.tag === targetTag)" class="badge">Tag informada</span></div>
          <p v-if="notes?.name && notes.name !== notes.tag" class="release-name">{{ notes.name }}</p>
          <div class="release-metadata"><span><Icon name="clock" :size="15" />{{ notes?.published_at ? 'Publicado em ' + dateTime(notes.published_at) : 'Publicação não informada' }}</span><a v-if="notes?.url && /^https?:\/\//.test(notes.url)" :href="notes.url" target="_blank" rel="noopener noreferrer"><Icon name="branch" :size="15" />Ver tag no repositório <Icon name="external" :size="13" /></a></div>
          <p v-if="sourceSelection?.name" class="notes-source">Fonte: <strong>{{ sourceSelection.name }}</strong></p>
          <div v-if="loading" class="notes-loading" role="status"><span class="loading-dot"></span>Consultando as notas de {{ targetTag }}…</div>
          <div v-else-if="noteError" class="notice warning" role="alert"><strong>Não foi possível carregar as notas.</strong><p>{{ noteError }}</p><button type="button" class="button subtle" @click="loadNotes(true)">Tentar novamente</button></div>
          <div v-else-if="notes?.availability === 'available' && notes.notes" class="release-content">
            <template v-for="(block, index) in noteBlocks" :key="index"><h4 v-if="block.kind === 'heading'">{{ block.text }}</h4><component :is="block.ordered ? 'ol' : 'ul'" v-else-if="block.kind === 'list'"><li v-for="(item, itemIndex) in block.items || []" :key="itemIndex">{{ item }}</li></component><pre v-else-if="block.kind === 'code'"><code>{{ block.text }}</code></pre><blockquote v-else-if="block.kind === 'quote'">{{ block.text }}</blockquote><p v-else>{{ block.text }}</p></template>
          </div>
          <div v-else-if="notes" class="notes-unavailable"><Icon name="file" :size="25" /><h4>Notas não disponíveis para esta tag.</h4><p>{{ notes.availability === 'temporarily_unavailable' ? 'O provedor não respondeu. Tente novamente mais tarde.' : 'O repositório não forneceu notas de release para a tag selecionada.' }}</p><p v-if="selectedKind === 'unknown'" class="muted">Classificação estável ou pré-lançamento não informada pelo provedor.</p><button v-if="notes.availability === 'temporarily_unavailable'" type="button" class="button subtle" @click="loadNotes(true)">Tentar novamente</button></div>
          <p v-if="notes && selectedKind === 'prerelease' && !verifiedRelease(notes)" class="notes-classification-hint">Pré-lançamento identificado pelo sufixo da tag. Metadados de publicação não disponíveis.</p>
        </template>
        <div class="release-review-hint"><Icon name="shield" :size="16" /><p>{{ legacyMode ? 'Este catálogo é para consulta. A execução mantém a fonte e o perfil configurados no servidor.' : 'Selecionar uma versão prepara a tag alvo. Nenhuma atualização é iniciada sem enviar e confirmar a operação abaixo.' }}</p></div>
      </section>
    </div>
      <section class="panel update-configuration">
        <div class="panel-heading">
          <h2>
            {{
              legacyMode ? "Configuração da instalação" : "Configurar e executar atualização"
            }}
          </h2>
          <span class="badge">Execução segura</span>
        </div>
        <ActionForm
          v-if="can('updates.run') && legacyMode"
          :action="route('updater.trigger.update')"
          :confirm="
            selection === 'apply'
              ? 'Aplicar uma atualização real com a fonte, o perfil e a configuração já definidos no servidor? O backup seguirá a política configurada no servidor. Confira a configuração antes de aplicar.'
              : undefined
          "
        >
          <p class="muted">
            Este fluxo utiliza a fonte, o perfil e os parâmetros já configurados
            no servidor.
          </p>
          <dl class="info-list">
            <div>
              <dt>Fonte da instalação</dt>
              <dd>
                {{ data.activeSource?.name || "Configuração do servidor" }}
              </dd>
            </div>
            <div>
              <dt>Perfil da instalação</dt>
              <dd>
                {{ data.activeProfile?.name || "Configuração do servidor" }}
              </dd>
            </div>
          </dl>
          <label class="toggle"
            ><input v-model="selection" type="radio" value="simulate" /><span
              ><strong>Apenas simular.</strong><br /><small
                >Verifica a operação sem aplicar a atualização real.</small
              ></span
            ></label
          >
          <label class="toggle"
            ><input
              v-model="selection"
              type="radio"
              value="apply"
              :disabled="legacyProfileSimulates"
            /><span
              ><strong>Aplicar a atualização real.</strong><br /><small
                >Executa as etapas e a política configuradas na
                instalação.</small
              ></span
            ></label
          >
          <p v-if="legacyProfileSimulates" class="notice warning">
            O perfil da instalação está configurado para simular. A execução
            seguirá essa política até que a configuração seja alterada no
            servidor.
          </p>
          <input type="hidden" name="action" :value="selection" />
          <input
            type="hidden"
            name="dry_run_before"
            :value="selection === 'simulate' ? '1' : '0'"
          />
          <input
            type="hidden"
            name="dry_run"
            :value="selection === 'simulate' ? '1' : '0'"
          />
          <div v-if="selection === 'apply'" class="notice warning">
            <strong>Esta operação altera a aplicação.</strong>
            <p>
              O backup seguirá a política configurada no servidor. Confira a
              configuração antes de aplicar.
            </p>
            <label
              >Digite ATUALIZAR para confirmar<input
                name="confirm_apply"
                required
                pattern="ATUALIZAR"
                autocomplete="off"
            /></label>
          </div>
          <button
            class="button"
            :class="selection === 'apply' ? 'primary' : 'simulate-button'"
            type="submit"
          >
            {{
              selection === "apply"
                ? "Aplicar atualização real"
                : "Executar somente simulação"
            }}
            <Icon :name="selection === 'apply' ? 'arrow-right' : 'play'" :size="16" />
          </button>
        </ActionForm>
        <ActionForm
          v-else-if="can('updates.run')"
          class="execution-form"
          :action="route('updater.updates.execute')"
          :confirm="
            selection === 'apply'
              ? `${selectionSummary} Aplicar uma atualização real à aplicação? O backup seguirá a política configurada no servidor. Confira a configuração antes de aplicar.`
              : undefined
          "
        >
          <div
            v-if="!hasExecutionPrerequisites"
            class="notice warning"
            role="alert"
          >
            <strong>Configure a origem e o perfil antes de atualizar.</strong>
            <p>
              {{ !data.sources?.length ? "Nenhuma fonte está cadastrada. " : ""
              }}{{
                !data.profiles?.length
                  ? "Nenhum perfil de execução está cadastrado. "
                  : ""
              }}Cadastre e ative os itens necessários ou solicite a configuração
              a um administrador.
            </p>
            <div class="actions">
              <a
                v-if="
                  !data.sources?.length &&
                  can('sources.manage') &&
                  route('updater.section')
                "
                class="button subtle"
                :href="route('updater.section', { section: 'sources' })"
                >Configurar fonte</a
              >
              <a
                v-if="
                  !data.profiles?.length &&
                  can('profiles.manage') &&
                  route('updater.profiles.create')
                "
                class="button subtle"
                :href="route('updater.profiles.create')"
                >Cadastrar perfil</a
              >
            </div>
          </div>
          <div class="execution-layout">
          <div class="execution-fields">
          <div class="fields-grid">
            <label
              >Perfil de execução<select
                v-model="selectedProfile"
                name="profile_id"
                required
              >
                <option
                  v-for="profile in data.profiles || []"
                  :key="profile.id"
                  :value="profile.id"
                >
                  {{ profile.name }}
                </option>
              </select></label
            ><label
              >Fonte do código<select
                v-model="selectedSource"
                name="source_id"
                required
              >
                <option
                  v-for="source in data.sources || []"
                  :key="source.id"
                  :value="source.id"
                >
                  {{ source.name }} · {{ source.branch }}
                </option>
              </select></label
            ><label
              >Branch alvo<input
                v-model="targetBranch"
                name="target_branch"
                :placeholder="
                  sourceSelection?.branch || 'Usar branch da fonte'
                "
                maxlength="255"
                autocomplete="off"
              /><small
                >Opcional. Altera a branch somente nesta execução.</small
              ></label
            ><label
              >Modo de atualização<select
                v-model="mode"
                name="update_mode"
                required
              >
                <option value="merge">Git merge</option>
                <option value="ff-only">Avanço direto (ff-only)</option>
                <option value="tag">Tag exata</option>
                <option v-if="data.fullUpdateEnabled" value="full-update">
                  Atualização completa
                </option>
              </select></label
            ><label v-if="mode === 'tag'" class="wide"
              >Tag exata da versão<input
                ref="tagField"
                v-model="targetTag"
                name="target_tag"
                list="available-tags"
                maxlength="120"
                placeholder="Ex.: v1.2.3-rc.1"
                required
                autocomplete="off"
              /><datalist id="available-tags">
                <option
                  v-for="tag in catalogRows"
                  :key="tag.tag"
                  :value="tag.tag"
                /></datalist
              ><small
                >Use o nome completo, incluindo beta, rc ou outros sufixos. É
                possível digitar uma tag fora das sugestões.</small
              ></label
            >
          </div>
          </div>
          <div class="execution-decision">
          <h3>O que deve acontecer agora?</h3>
          <label class="toggle replay-option"
            ><input
              name="replay_migrations_from_start"
              value="1"
              type="checkbox"
            /><span
              >Recomeçar pela primeira migration de forma idempotente.</span
            ></label
          >
          <div class="execution-choice">
            <label class="toggle"
              ><input v-model="selection" type="radio" value="simulate" /><span
                ><strong>Apenas simular.</strong><br /><small
                  >Executa a verificação sem aplicar a atualização real.</small
                ></span
              ></label
            ><label class="toggle"
              ><input v-model="selection" type="radio" value="apply" /><span
                ><strong>Aplicar a atualização real.</strong><br /><small
                  >Altera a aplicação e executa as etapas do perfil.</small
                ></span
              ></label
            ><input
              type="hidden"
              name="dry_run_before"
              :value="selection === 'simulate' ? '1' : '0'"
            /><input type="hidden" name="action" :value="selection" />
            <div v-if="selection === 'apply'" class="notice warning">
              <strong>Esta operação altera a aplicação.</strong>
              <p>{{ selectionSummary }}</p>
              <p>
                O backup seguirá a política configurada no servidor. Confira a
                configuração antes de aplicar. Revise a fonte, a branch/tag e o
                perfil. Use a simulação primeiro quando precisar de aprovação.
              </p>
              <label
                >Digite ATUALIZAR para confirmar<input
                  name="confirm_apply"
                  pattern="ATUALIZAR"
                  required
                  autocomplete="off"
              /></label>
            </div>
          </div>
          <div class="actions">
            <button
              class="button"
              :class="selection === 'apply' ? 'primary' : 'simulate-button'"
              :disabled="!hasExecutionPrerequisites"
              type="submit"
            >
              {{
                selection === "apply"
                  ? "Aplicar atualização real"
                  : "Executar somente simulação"
              }}
              <Icon :name="selection === 'apply' ? 'arrow-right' : 'play'" :size="16" />
            </button>
          </div>
          </div>
          </div></ActionForm
        >
        <div v-else class="notice warning">
          Você pode consultar as versões, mas não tem permissão para executar
          atualizações.
        </div>
        <div v-if="!legacyMode" class="configuration-progress">
          <ProgressPanel :endpoint="route('updater.updates.progress.status')" title="Progresso da atualização ou simulação" />
        </div>
      </section>
    <details class="panel installation-state">
      <summary><span><strong>Estado da instalação</strong><code>{{ status.current_revision || "Revisão não informada" }}</code></span><span class="badge" :class="status.status_unavailable ? 'pending' : status.has_updates || status.has_update_by_tag ? 'success' : ''">{{ status.status_unavailable ? 'Verificação indisponível' : status.has_updates || status.has_update_by_tag ? 'Atualização disponível' : 'Sem atualização detectada' }}</span><Icon name="chevron-down" :size="17" /></summary>
      <div class="installation-details"><div class="metrics"><div class="metric"><span>Revisão atual</span><strong><code>{{ status.current_revision || "—" }}</code></strong></div><div class="metric"><span>Referência remota</span><strong><code>{{ status.remote || "—" }}</code></strong></div><div class="metric"><span>Commits pendentes</span><strong>{{ status.status_unavailable ? "—" : (status.behind_by_commits ?? 0) }}</strong></div><div class="metric"><span>Última tag remota</span><strong>{{ status.latest_tag || "—" }}</strong></div></div></div>
    </details>
    <p v-if="status.warning" class="notice warning">{{ status.warning }}</p>
    <ProgressPanel v-if="legacyMode" :endpoint="route('updater.updates.progress.status')" title="Progresso da atualização ou simulação" />
  </div>
</template>
<style scoped>
.updates-workspace { gap: 16px; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
.updates-guidance { display: flex; align-items: center; gap: 11px; padding: 12px 16px; border: 1px solid #d7e5ff; border-radius: 8px; background: var(--primary-soft, #edf4ff); color: var(--ink, #213e74); font-size: 13px; }
.updates-guidance > svg { flex: none; color: var(--primary, #155bff); }
.updates-guidance p { margin: 0; flex: 1; }
.updates-guidance a { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; color: var(--primary, #155bff); font-weight: 600; }
.version-workspace { display: grid; grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr); gap: 16px; align-items: stretch; }
.version-workspace > .panel { min-width: 0; padding: 20px; border-radius: 8px; }
.catalog-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 4px; }
.catalog-heading h2 { margin: 0; font-size: 17px; letter-spacing: -.025em; }
.catalog-count { color: var(--muted, #6b7b99); font-size: 12px; white-space: nowrap; }
.catalog-description { margin: 4px 0 15px; font-size: 12px; }
.version-search { display: flex; align-items: center; gap: 9px; border: 1px solid var(--line, #dce4ef); border-radius: 6px; padding: 0 11px; color: var(--muted, #6b7b99); }
.version-search input { width: 100%; min-width: 0; border: 0; outline: 0; padding: 10px 0; background: transparent; font: inherit; box-shadow: none; }
.version-search:focus-within { border-color: var(--primary, #155bff); box-shadow: 0 0 0 3px #155bff15; }
.version-filters { display: flex; flex-wrap: wrap; gap: 6px; margin: 14px 0 10px; }
.version-filters button { border: 1px solid transparent; border-radius: 6px; background: var(--soft, #f1f4f9); color: var(--muted, #4e6184); padding: 7px 10px; font: inherit; font-size: 12px; font-weight: 500; cursor: pointer; }
.version-filters button.active { background: var(--primary, #155bff); color: #fff; }
.version-filters button:not(.active):hover { border-color: var(--line, #d4e1f5); background: var(--primary-soft, #e9f0fc); color: var(--ink, #20385f); }
.version-list { list-style: none; padding: 0; margin: 0 -9px; max-height: 460px; overflow-y: auto; scrollbar-width: thin; }
.version-list li { margin: 0; }
.version-row { width: 100%; display: grid; grid-template-columns: minmax(0, 1fr) auto 17px; align-items: center; gap: 9px; padding: 13px 9px; border: 1px solid transparent; border-bottom-color: var(--line, #e5ebf4); border-radius: 0; text-align: left; background: transparent; color: inherit; cursor: pointer; }
.version-row:hover { background: var(--primary-soft, #f5f8ff); }
.version-row.selected { border-color: var(--primary, #155bff); border-radius: 6px; background: var(--primary-soft, #edf4ff); }
.version-row > svg { color: var(--primary, #155bff); }
.version-row-main { min-width: 0; display: grid; gap: 5px; }
.version-title { display: flex; gap: 9px; align-items: center; flex-wrap: wrap; }
.version-title strong { font-size: 14px; overflow-wrap: anywhere; }
.version-title .badge { font-size: 10px; padding: 3px 7px; border-radius: 5px; }
.version-description { font-size: 11px; color: var(--muted, #6b7b99); line-height: 1.4; }
.version-date { font-size: 10px; color: var(--muted, #6b7b99); text-align: right; max-width: 95px; }
.catalog-footer { margin-top: 15px; padding-top: 12px; border-top: 1px solid var(--line, #e5ebf4); }
.catalog-footer > p { margin: 0 0 10px; color: var(--muted, #6b7b99); font-size: 11px; line-height: 1.5; }
.manual-tag-button { display: inline-flex; align-items: center; gap: 7px; background: none; border: 0; padding: 0; color: var(--primary, #155bff); font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; }
.catalog-context { margin-top: 10px; font-size: 12px; }
.catalog-empty { min-height: 210px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px 12px; text-align: center; color: var(--muted, #6b7b99); }
.catalog-empty h3 { margin: 10px 0 5px; font-size: 14px; color: var(--ink, #0b1945); }
.catalog-empty p { margin: 0 0 12px; font-size: 12px; line-height: 1.6; max-width: 310px; }
.version-notes { display: flex; flex-direction: column; }
.notes-placeholder { flex: 1; min-height: 350px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
.notes-placeholder-icon { display: flex; align-items: center; justify-content: center; width: 64px; height: 64px; border-radius: 16px; background: var(--primary-soft, #edf4ff); color: var(--primary, #155bff); }
.notes-placeholder h3 { margin: 18px 0 7px; font-size: 17px; }
.notes-placeholder p { max-width: 340px; margin: 0; font-size: 13px; line-height: 1.7; color: var(--muted, #6b7b99); }
.release-title { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 17px; }
.release-title h3 { margin: 0; font-size: clamp(22px, 2.5vw, 28px); letter-spacing: -.035em; overflow-wrap: anywhere; }
.release-name { margin: 8px 0 0; font-size: 14px; color: var(--muted, #6b7b99); }
.release-metadata { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 20px; margin-top: 18px; font-size: 12px; color: var(--muted, #526789); }
.release-metadata > span, .release-metadata a { display: inline-flex; align-items: center; gap: 7px; }
.release-metadata a { color: var(--primary, #155bff); font-weight: 500; }
.notes-source { margin: 12px 0 0; color: var(--muted, #6b7b99); font-size: 12px; }
.release-content { margin-top: 20px; padding-top: 1px; border-top: 1px solid var(--line, #e5ebf4); color: var(--ink, #263b61); font-size: 13px; line-height: 1.7; overflow-wrap: anywhere; }
.release-content h4 { margin: 21px 0 9px; font-size: 15px; color: var(--ink, #0b1945); letter-spacing: -.015em; }
.release-content p { margin: 13px 0; white-space: pre-wrap; }
.release-content ul, .release-content ol { padding-left: 20px; margin: 9px 0 16px; }
.release-content li { padding-left: 3px; margin: 5px 0; }
.release-content li::marker { color: var(--primary, #155bff); }
.release-content pre { border: 1px solid var(--line, #e0e7f2); border-radius: 6px; padding: 12px; background: var(--soft, #f7f9fd); color: var(--ink, #1b3159); overflow-x: auto; white-space: pre-wrap; font-size: 12px; }
.release-content blockquote { margin: 14px 0; padding-left: 13px; border-left: 3px solid #cedfff; color: var(--muted, #6b7b99); }
.notes-loading { display: flex; align-items: center; gap: 9px; padding: 35px 0; color: var(--muted, #6b7b99); font-size: 13px; }
.loading-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--primary, #155bff); }
.notes-unavailable { padding: 35px 5px 22px; color: var(--muted, #6b7b99); }
.notes-unavailable > svg { color: #8194b5; }
.notes-unavailable h4 { color: var(--ink, #0b1945); font-size: 15px; margin: 12px 0 7px; }
.notes-unavailable p { margin: 0 0 12px; font-size: 12px; line-height: 1.65; }
.notes-classification-hint { margin-top: 14px; font-size: 11px; color: var(--muted, #6b7b99); }
.release-review-hint { display: flex; gap: 8px; padding-top: 15px; margin-top: auto; border-top: 1px solid var(--line, #e5ebf4); color: var(--muted, #6b7b99); font-size: 11px; line-height: 1.5; }
.release-review-hint > svg { flex: none; color: var(--primary, #155bff); margin-top: 2px; }
.release-review-hint p { margin: 0; }
.update-configuration > .panel-heading { margin-bottom: 16px; }
.execution-layout { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); gap: 22px; align-items: start; }
.execution-fields, .execution-decision { min-width: 0; }
.execution-fields .fields-grid { gap: 12px; }
.execution-decision { padding-left: 22px; border-left: 1px solid var(--line, #e5ebf4); display: flex; flex-direction: column; gap: 12px; }
.execution-decision h3 { margin: 0; font-size: 12px; }
.execution-decision .toggle { margin: 0; font-size: 11px; }
.execution-decision .replay-option { padding-bottom: 10px; border-bottom: 1px solid var(--line, #e5ebf4); }
.execution-choice { display: flex; flex-direction: column; gap: 10px; }
.execution-choice .notice { margin: 0; padding: 12px; }
.execution-choice .notice p { margin: 7px 0; font-size: 11px; line-height: 1.5; }
.execution-choice .notice label { margin-top: 10px; }
.execution-decision .actions { margin-top: 0; }
.configuration-progress { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); gap: 22px; margin-top: 14px; }
.configuration-progress :deep(.progress-panel) { grid-column: 2; min-width: 0; margin-left: 22px; padding: 12px 0 0; border: 0; border-top: 1px solid var(--line, #e5ebf4); border-radius: 0; background: transparent; box-shadow: none; }
.configuration-progress :deep(.panel-heading) { margin-bottom: 6px; }
.configuration-progress :deep(h2) { font-size: 11px; }
.configuration-progress :deep(p) { margin: 5px 0; }
.configuration-progress :deep(.actions) { margin-top: 7px; }
.configuration-progress :deep(.actions:empty) { display: none; }
.simulate-button { background: var(--surface, #fff); border-color: var(--primary, #155bff); color: var(--primary, #155bff); }
.simulate-button:hover { background: var(--primary-soft, #edf4ff); }
.installation-state { padding: 0; }
.installation-state summary { list-style: none; display: flex; align-items: center; gap: 12px; padding: 14px 18px; cursor: pointer; }
.installation-state summary::-webkit-details-marker { display: none; }
.installation-state summary > span:first-child { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; flex: 1; font-size: 12px; }
.installation-state summary code { color: var(--muted, #6b7b99); font-size: 11px; overflow-wrap: anywhere; }
.installation-state summary > svg { flex: none; color: var(--muted, #6b7b99); }
.installation-details { padding: 0 18px 18px; }
.manual-notes-label { font-size: 12px; }
@media (max-width: 1080px) { .version-workspace { grid-template-columns: minmax(0, 1fr) minmax(0, 1.08fr); } .version-workspace > .panel { padding: 17px; } .version-row { grid-template-columns: minmax(0, 1fr) 17px; } .version-date { grid-column: 1; grid-row: 2; max-width: none; text-align: left; } .version-row > svg { grid-column: 2; grid-row: 1 / 3; } }
@media (max-width: 780px) { .execution-layout, .configuration-progress { grid-template-columns: minmax(0, 1fr); gap: 16px; } .execution-decision { padding: 16px 0 0; border-left: 0; border-top: 1px solid var(--line, #e5ebf4); } .configuration-progress :deep(.progress-panel) { grid-column: 1; margin-left: 0; } .version-workspace { grid-template-columns: minmax(0, 1fr); } .version-list { max-height: 360px; } .notes-placeholder { min-height: 230px; } .updates-guidance { display: grid; grid-template-columns: 18px minmax(0, 1fr); align-items: start; gap: 8px 11px; } .updates-guidance > svg { grid-column: 1; grid-row: 1; } .updates-guidance p { grid-column: 2; min-width: 0; } .updates-guidance a { grid-column: 2; justify-self: start; margin-left: 0; } .installation-state summary { flex-wrap: wrap; } .installation-state summary > span:first-child { flex-basis: calc(100% - 30px); } }
@media (max-width: 400px) { .version-workspace > .panel { padding: 14px; } .version-filters { gap: 4px; } .version-filters button { padding: 7px 8px; font-size: 11px; } .release-metadata { flex-direction: column; align-items: flex-start; gap: 10px; } }
</style>
