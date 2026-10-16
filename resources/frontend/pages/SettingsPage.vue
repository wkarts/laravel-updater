<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRoute } from "vue-router";
import { dateTime } from "../services/format";
import ActionForm from "../components/ActionForm.vue";
import DataTable from "../components/DataTable.vue";
import { can, route, state } from "../services/app";

const props = defineProps<{ data: any; page: string }>();
const currentRoute = useRoute();
const tabIds = ["branding", "runtime", "maintenance", "cloud", "tokens"];
function requestedTab(): string {
  const requested = String(currentRoute.query.tab || "");
  if (tabIds.includes(requested)) return requested;
  const hashTab = currentRoute.hash.slice(1);
  return tabIds.includes(hashTab) ? hashTab : "branding";
}
const tab = ref(requestedTab());
watch(
  () => [currentRoute.query.tab, currentRoute.hash],
  () => { tab.value = requestedTab(); },
);
const search = ref("");
const provider = ref("none");
const rotatingToken = ref<any>(null);
const tokenName = ref("");
const tokenScopes = ref<string[]>([
  "status:read",
  "check:read",
  "runs:read",
  "releases:read",
]);
const copied = ref(false);
const env = computed(() => props.page === "settings.env");
const branding = computed(
  () => props.data.branding || state.context.branding || {},
);
const cloud = computed(() => props.data.backupUpload || {});
const configuredFields = computed<any[]>(() =>
  env.value ? props.data.envFields || [] : props.data.updaterConfigFields || [],
);
const groups = computed(() => {
  const result: Record<string, any[]> = {};
  configuredFields.value.forEach((field) =>
    (result[field.group || "Geral"] ||= []).push(field),
  );
  return Object.entries(result).sort(([a], [b]) => a.localeCompare(b));
});
const tokens = computed(() =>
  (props.data.tokens || []).map((token: any) => ({
    ...token,
    statusLabel: token.revoked_at ? "Revogado" : "Ativo",
    scopeLabel: scopesFor(token).join(", ") || "Escopos legados",
    lastUse: token.last_used_at
      ? dateTime(token.last_used_at)
      : "Ainda não utilizado",
  })),
);
const newToken = computed(() => state.context.flash?.token_plain || "");
const scopes = [
  {
    value: "status:read",
    label: "Consultar status",
    description: "Lê o estado da instalação.",
  },
  {
    value: "check:read",
    label: "Verificar atualizações",
    description: "Consulta a versão disponível.",
  },
  {
    value: "runs:read",
    label: "Consultar execuções",
    description: "Acessa o histórico de execuções.",
  },
  {
    value: "releases:read",
    label: "Consultar versões",
    description: "Lê tags e notas de versão.",
  },
  {
    value: "updates:simulate",
    label: "Simular atualização",
    description: "Permite solicitar uma simulação.",
  },
  {
    value: "updates:apply",
    label: "Solicitar aplicação",
    description:
      "Pode solicitar uma atualização real, sujeita aos controles do servidor.",
  },
];
const tabs = [
  { id: "branding", label: "Identidade" },
  { id: "runtime", label: "Comportamento" },
  { id: "maintenance", label: "Manutenção" },
  { id: "cloud", label: "Armazenamento" },
  { id: "tokens", label: "Tokens de API" },
];

watch(
  () => props.data,
  () => {
    provider.value = cloud.value.provider || "none";
  },
  { immediate: true },
);
watch(
  () => props.page,
  () => {
    search.value = "";
  },
);
watch(
  newToken,
  (value) => {
    if (value) {
      cancelRotation();
      tab.value = "tokens";
      copied.value = false;
    }
  },
  { immediate: true },
);
function boolean(value: any): boolean {
  return value === true || value === 1 || value === "1" || value === "true";
}
function valueFor(field: any): string {
  if (field.sensitive) return "";
  const value = env.value ? (field.env_value ?? "") : (field.value ?? "");
  return Array.isArray(value)
    ? value.join("\n")
    : field.type === "list" && env.value
      ? String(value).replaceAll(",", "\n")
      : String(value);
}
function matches(field: any): boolean {
  const term = search.value.trim().toLocaleLowerCase();
  return (
    !term ||
    `${field.label} ${field.config} ${field.primary_env_key} ${field.group}`
      .toLocaleLowerCase()
      .includes(term)
  );
}
function scopesFor(token: any): string[] {
  if (Array.isArray(token.scopes)) return token.scopes;
  try {
    return JSON.parse(token.scopes_json || "[]");
  } catch {
    return [];
  }
}
function rotate(token: any): void {
  rotatingToken.value = token;
  tokenName.value = `${token.name} — rotação`;
  tokenScopes.value = scopesFor(token).length
    ? scopesFor(token)
    : ["status:read", "check:read", "runs:read", "releases:read"];
  document.getElementById("token-name")?.focus();
}
function cancelRotation(): void {
  rotatingToken.value = null;
  tokenName.value = "";
  tokenScopes.value = [
    "status:read",
    "check:read",
    "runs:read",
    "releases:read",
  ];
}
async function copyToken(): Promise<void> {
  try {
    await navigator.clipboard.writeText(newToken.value);
    copied.value = true;
  } catch {
    copied.value = false;
  }
}
function configured(value: any): string {
  return value ? "Credencial configurada" : "Credencial ausente";
}
</script>

<template>
  <div v-if="!can('settings.manage')" class="notice warning" role="alert">
    Sua conta não tem permissão para gerenciar configurações.
  </div>
  <div v-else class="stack settings-page">
    <div class="page-intro">
      <div>
        <p class="eyebrow">Administração</p>
        <h1>{{ env ? "Variáveis de ambiente" : "Configurações" }}</h1>
        <p class="muted">
          {{
            env
              ? "Edite os parâmetros do Updater definidos no arquivo .env."
              : "Identidade, comportamento, armazenamento e acesso às integrações."
          }}
        </p>
      </div>
      <a
        v-if="env"
        :href="route('updater.settings.index')"
        class="button subtle"
        >Voltar às configurações</a
      >
      <a
        v-else
        :href="route('updater.settings.env.index')"
        class="button subtle"
        >Variáveis de ambiente</a
      >
    </div>

    <div
      v-if="!env"
      class="tabs"
      role="tablist"
      aria-label="Áreas de configurações"
    >
      <button
        v-for="item in tabs"
        :id="`tab-${item.id}`"
        :key="item.id"
        type="button"
        role="tab"
        :aria-selected="tab === item.id"
        :aria-controls="`panel-${item.id}`"
        :class="{ active: tab === item.id }"
        @click="
          tab = item.id;
          search = '';
        "
      >
        {{ item.label }}
      </button>
    </div>

    <section
      v-if="!env && tab === 'branding'"
      id="panel-branding"
      role="tabpanel"
      aria-labelledby="tab-branding"
      class="panel stack"
    >
      <header>
        <h2>Identidade da aplicação</h2>
        <p class="muted">
          Personalize o painel e a página de manutenção. Os arquivos enviados
          substituem os atuais.
        </p>
      </header>
      <ActionForm
        :action="route('updater.settings.branding.save')"
        class="stack"
        enctype="multipart/form-data"
      >
        <div class="fields-grid">
          <label
            >Nome da aplicação<input
              name="app_name"
              :defaultValue="branding.app_name"
              maxlength="120"
              placeholder="Minha aplicação"
          /></label>
          <label
            >Sufixo do nome<input
              name="app_sufix_name"
              :defaultValue="branding.app_sufix_name"
              maxlength="120"
              placeholder="Updater Manager"
          /></label>
          <label class="span-all"
            >Descrição curta<input
              name="app_desc"
              :defaultValue="branding.app_desc"
              maxlength="255"
          /></label>
          <label
            >Cor primária<input
              name="primary_color"
              :defaultValue="branding.primary_color || '#173e68'"
              pattern="#[0-9a-fA-F]{6}"
              placeholder="#173e68"
            /><small class="muted"
              >Formato hexadecimal, por exemplo #173e68.</small
            ></label
          >
        </div>
        <fieldset>
          <legend>Página de manutenção</legend>
          <div class="fields-grid">
            <label
              >Título<input
                name="maintenance_title"
                :defaultValue="branding.maintenance_title"
                maxlength="120"
            /></label>
            <label
              >Rodapé<input
                name="maintenance_footer"
                :defaultValue="branding.maintenance_footer"
                maxlength="200"
            /></label>
            <label class="span-all"
              >Mensagem<textarea
                name="maintenance_message"
                rows="3"
                :defaultValue="branding.maintenance_message"
                maxlength="500"
              ></textarea>
            </label>
          </div>
        </fieldset>
        <fieldset>
          <legend>Arquivos da identidade</legend>
          <div class="fields-grid">
            <label
              >Logo do painel<input
                type="file"
                name="logo"
                accept="image/png,image/jpeg,image/svg+xml"
              /><small class="muted">PNG, JPG ou SVG.</small></label
            >
            <label
              >Favicon<input
                type="file"
                name="favicon"
                accept="image/png,image/x-icon,image/vnd.microsoft.icon"
              /><small class="muted">PNG ou ICO.</small></label
            >
            <label
              >Logo da manutenção<input
                type="file"
                name="maintenance_logo"
                accept="image/png,image/jpeg,image/svg+xml"
              /><small class="muted"
                >Se ausente, utiliza a identidade padrão configurada.</small
              ></label
            >
          </div>
        </fieldset>
        <fieldset>
          <legend>Comportamento inicial</legend>
          <div class="stack">
            <label class="toggle"
              ><input
                type="checkbox"
                name="first_run_assume_behind"
                value="1"
                :defaultChecked="boolean(branding.first_run_assume_behind ?? 1)"
              /><span
                >Assumir atualização pendente na primeira execução sem
                repositório Git</span
              ></label
            >
            <label
              >Quantidade de commits assumidos<input
                type="number"
                name="first_run_assume_behind_commits"
                min="1"
                max="9999"
                :defaultValue="branding.first_run_assume_behind_commits || 1"
            /></label>
          </div>
        </fieldset>
        <div class="actions">
          <button class="button primary" type="submit">
            Salvar identidade
          </button>
        </div>
      </ActionForm>
      <details class="settings-advanced">
        <summary>Restaurar identidade ou remover arquivos</summary>
        <div class="actions">
          <ActionForm
            :action="route('updater.settings.branding.reset')"
            confirm="Restaurar a identidade definida no ambiente? Os ajustes atuais serão substituídos."
            ><button class="button subtle" type="submit">
              Restaurar padrão
            </button></ActionForm
          >
          <ActionForm
            v-for="asset in [
              { key: 'logo', label: 'logo do painel' },
              { key: 'favicon', label: 'favicon' },
              { key: 'maintenance-logo', label: 'logo da manutenção' },
            ]"
            :key="asset.key"
            :action="
              route('updater.settings.branding.asset.remove', {
                asset: asset.key,
              })
            "
            method="DELETE"
            :confirm="`Remover ${asset.label}?`"
            ><button class="button subtle" type="submit">
              Remover {{ asset.label }}
            </button></ActionForm
          >
        </div>
      </details>
    </section>

    <section
      v-if="!env && tab === 'maintenance'"
      id="panel-maintenance"
      role="tabpanel"
      aria-labelledby="tab-maintenance"
      class="panel stack"
    >
      <header>
        <h2>Manutenção durante atualizações</h2>
        <p class="muted">
          Defina se a aplicação deve entrar em manutenção ao iniciar uma atualização.
        </p>
      </header>
      <p>
        Configuração salva:
        <span class="badge" :class="boolean(branding.enter_maintenance_on_update_start ?? 0) ? 'success' : 'pending'">
          {{ boolean(branding.enter_maintenance_on_update_start ?? 0) ? "Ativada" : "Desativada" }}
        </span>
      </p>
      <ActionForm
        :action="route('updater.settings.maintenance.save')"
        class="stack"
      >
        <fieldset>
          <legend>Ativar manutenção ao iniciar uma atualização</legend>
          <div class="stack">
            <label class="toggle">
              <input
                type="radio"
                name="enter_maintenance_on_update_start"
                value="1"
                :defaultChecked="boolean(branding.enter_maintenance_on_update_start ?? 0)"
                required
              />
              <span>Ativado: exibe a página de manutenção aos clientes durante a atualização.</span>
            </label>
            <label class="toggle">
              <input
                type="radio"
                name="enter_maintenance_on_update_start"
                value="0"
                :defaultChecked="!boolean(branding.enter_maintenance_on_update_start ?? 0)"
                required
              />
              <span>Desativado: o Updater não coloca a aplicação em manutenção durante a atualização.</span>
            </label>
          </div>
        </fieldset>
        <p class="muted">
          O controle manual de manutenção é independente. Salvar esta configuração
          não ativa nem desativa o modo de manutenção agora.
        </p>
        <div class="actions">
          <button class="button primary" type="submit">Salvar configuração</button>
        </div>
      </ActionForm>
    </section>

    <section
      v-if="env || tab === 'runtime'"
      :id="env ? 'environment-fields' : 'panel-runtime'"
      :role="env ? undefined : 'tabpanel'"
      :aria-labelledby="env ? undefined : 'tab-runtime'"
      class="panel stack"
    >
      <header>
        <h2>
          {{ env ? "Parâmetros do Updater" : "Comportamento do Updater" }}
        </h2>
        <p class="muted">
          {{
            env
              ? "As alterações são limitadas às variáveis conhecidas do pacote. Campos secretos vazios preservam o valor atual."
              : "Parâmetros definidos no .env têm prioridade e só podem ser alterados na tela de ambiente."
          }}
        </p>
      </header>
      <div v-if="env" class="notice warning">
        Alterações de ambiente podem modificar autenticação, filas e operação do
        pacote. Revise os valores antes de salvar.
      </div>
      <label class="settings-search"
        >Buscar parâmetro<input
          v-model="search"
          type="search"
          placeholder="Nome, grupo ou variável de ambiente"
      /></label>
      <ActionForm
        :key="`${page}-fields`"
        :action="
          route(
            env ? 'updater.settings.env.save' : 'updater.settings.config.save',
          )
        "
        class="stack"
      >
        <fieldset
          v-for="[group, fields] in groups"
          v-show="fields.some(matches)"
          :key="group"
        >
          <legend>{{ group }}</legend>
          <div class="stack">
            <div
              v-for="field in fields"
              v-show="matches(field)"
              :key="field.field"
              class="settings-runtime-row"
            >
              <div>
                <label :for="field.field">{{
                  field.label || field.field
                }}</label
                ><small class="muted settings-code"
                  ><code>{{
                    env ? field.primary_env_key : field.config
                  }}</code></small
                ><small v-if="field.description" class="muted">{{
                  field.description
                }}</small
                ><span v-if="!env && field.env_locked" class="badge"
                  >Definido no .env</span
                >
              </div>
              <div>
                <label v-if="field.type === 'bool'" class="toggle"
                  ><input
                    :id="field.field"
                    :name="field.field"
                    type="checkbox"
                    value="1"
                    :defaultChecked="
                      boolean(env ? field.env_value : field.value)
                    "
                    :disabled="!env && field.env_locked"
                  /><span>Habilitado</span></label
                >
                <template v-else-if="field.sensitive"
                  ><p class="muted">{{ configured(field.configured) }}</p>
                  <input
                    :id="field.field"
                    :name="field.field"
                    type="password"
                    autocomplete="new-password"
                    value=""
                    :aria-label="`Substituir ${field.label || field.field}`"
                    placeholder="Novo valor; vazio preserva o atual"
                    :disabled="!env && field.env_locked"
                  />
                  <label
                    v-if="field.configured && (env || !field.env_locked)"
                    class="toggle"
                    style="margin-top: 12px"
                  >
                    <input
                      type="checkbox"
                      :name="`remove_${field.field}`"
                      value="1"
                    />
                    <span
                      >Remover segredo atual ao salvar.<small class="field-hint"
                        >A integração ou o recurso que utiliza esta credencial
                        pode deixar de funcionar até receber um novo
                        segredo.</small
                      ></span
                    >
                  </label>
                </template>
                <textarea
                  v-else-if="field.type === 'list'"
                  :id="field.field"
                  :name="field.field"
                  rows="3"
                  :defaultValue="valueFor(field)"
                  placeholder="Um item por linha"
                  :disabled="!env && field.env_locked"
                ></textarea>
                <input
                  v-else
                  :id="field.field"
                  :name="field.field"
                  :type="field.type === 'int' ? 'number' : 'text'"
                  :min="field.type === 'int' ? 0 : undefined"
                  :defaultValue="valueFor(field)"
                  :disabled="!env && field.env_locked"
                />
              </div>
            </div>
          </div>
        </fieldset>
        <p v-if="!configuredFields.length" class="muted">
          Nenhum parâmetro está disponível nesta instalação.
        </p>
        <p
          v-else-if="!configuredFields.some(matches)"
          role="status"
          class="notice"
        >
          Nenhum parâmetro corresponde à busca.
        </p>
        <div v-if="configuredFields.length" class="actions">
          <button type="submit" class="button primary">
            {{ env ? "Salvar variáveis de ambiente" : "Salvar comportamento" }}
          </button>
        </div>
      </ActionForm>
      <div v-if="!env" class="notice">
        <strong>Política de segurança</strong>
        <p>
          Autenticação em dois fatores:
          {{
            state.context.security?.twoFactorEnabled
              ? "habilitada"
              : "desabilitada"
          }}. Exigência global:
          {{
            state.context.security?.twoFactorRequired
              ? "obrigatória"
              : "opcional"
          }}.
        </p>
        <a
          v-if="can('profile.manage') && route('updater.profile')"
          :href="route('updater.profile')"
          >Gerenciar a segurança da minha conta</a
        >
      </div>
    </section>

    <section
      v-if="!env && tab === 'cloud'"
      id="panel-cloud"
      role="tabpanel"
      aria-labelledby="tab-cloud"
      class="panel stack"
    >
      <header>
        <h2>Armazenamento de backups</h2>
        <p class="muted">
          Mantenha uma cópia remota dos backups locais. Falhas no envio ficam
          registradas e não impedem a conclusão do backup local.
        </p>
      </header>
      <ActionForm
        :action="route('updater.settings.backup-upload.save')"
        class="stack"
      >
        <div class="fields-grid">
          <label
            >Destino de envio<select v-model="provider" name="provider">
              <option value="none">Somente armazenamento local</option>
              <option value="dropbox">Dropbox</option>
              <option value="google-drive">Google Drive</option>
              <option value="s3">Amazon S3 / compatível</option>
              <option value="minio">MinIO</option>
            </select></label
          >
          <label
            >Pasta remota<input
              name="prefix"
              :defaultValue="cloud.prefix || 'updater/backups'"
              maxlength="190"
              placeholder="updater/backups"
          /></label>
        </div>
        <label class="toggle"
          ><input
            type="checkbox"
            name="auto_upload"
            value="1"
            :defaultChecked="boolean(cloud.auto_upload)"
            :disabled="provider === 'none'"
          /><span>Enviar automaticamente após criar um backup</span></label
        >
        <p class="notice">
          Credenciais existentes nunca são exibidas. Deixe o campo vazio para
          manter a credencial ou marque a opção de remoção.
        </p>
        <fieldset v-show="provider === 'dropbox'">
          <legend>Dropbox</legend>
          <div class="stack">
            <label
              >Substituir Access Token<small class="muted">{{
                configured(cloud.dropbox?.access_token_configured)
              }}</small
              ><input
                name="dropbox_access_token"
                type="password"
                autocomplete="new-password"
                placeholder="Novo token" /></label
            ><label class="toggle"
              ><input
                name="remove_dropbox_access_token"
                type="checkbox"
                value="1"
              /><span>Remover Access Token salvo</span></label
            >
          </div>
        </fieldset>
        <fieldset v-show="provider === 'google-drive'">
          <legend>Google Drive</legend>
          <div class="fields-grid">
            <label
              >Client ID<input
                name="google_client_id"
                :defaultValue="cloud.google_drive?.client_id"
                autocomplete="off"
            /></label>
            <label
              >Folder ID<input
                name="google_folder_id"
                :defaultValue="cloud.google_drive?.folder_id"
                autocomplete="off"
              /><small class="muted"
                >Opcional: identificador da pasta de destino.</small
              ></label
            >
            <div class="stack">
              <label
                >Substituir Client Secret<small class="muted">{{
                  configured(cloud.google_drive?.client_secret_configured)
                }}</small
                ><input
                  name="google_client_secret"
                  type="password"
                  autocomplete="new-password"
                  placeholder="Novo Client Secret" /></label
              ><label class="toggle"
                ><input
                  name="remove_google_client_secret"
                  type="checkbox"
                  value="1"
                /><span>Remover segredo salvo</span></label
              >
            </div>
            <div class="stack">
              <label
                >Substituir Refresh Token<small class="muted">{{
                  configured(cloud.google_drive?.refresh_token_configured)
                }}</small
                ><input
                  name="google_refresh_token"
                  type="password"
                  autocomplete="new-password"
                  placeholder="Novo Refresh Token" /></label
              ><label class="toggle"
                ><input
                  name="remove_google_refresh_token"
                  type="checkbox"
                  value="1"
                /><span>Remover token salvo</span></label
              >
            </div>
          </div>
        </fieldset>
        <fieldset v-show="provider === 's3' || provider === 'minio'">
          <legend>
            {{ provider === "minio" ? "MinIO" : "S3 / compatível" }}
          </legend>
          <div class="fields-grid">
            <label
              >Endpoint<input
                name="s3_endpoint"
                type="url"
                :defaultValue="cloud.s3?.endpoint"
                placeholder="https://s3.amazonaws.com"
            /></label>
            <label
              >Região<input
                name="s3_region"
                :defaultValue="cloud.s3?.region || 'us-east-1'"
            /></label>
            <label
              >Bucket<input name="s3_bucket" :defaultValue="cloud.s3?.bucket"
            /></label>
            <div class="stack">
              <label
                >Substituir Access Key<small class="muted">{{
                  configured(cloud.s3?.access_key_configured)
                }}</small
                ><input
                  name="s3_access_key"
                  type="password"
                  autocomplete="new-password"
                  placeholder="Nova Access Key" /></label
              ><label class="toggle"
                ><input
                  name="remove_s3_access_key"
                  type="checkbox"
                  value="1"
                /><span>Remover chave salva</span></label
              >
            </div>
            <div class="stack">
              <label
                >Substituir Secret Key<small class="muted">{{
                  configured(cloud.s3?.secret_key_configured)
                }}</small
                ><input
                  name="s3_secret_key"
                  type="password"
                  autocomplete="new-password"
                  placeholder="Nova Secret Key" /></label
              ><label class="toggle"
                ><input
                  name="remove_s3_secret_key"
                  type="checkbox"
                  value="1"
                /><span>Remover segredo salvo</span></label
              >
            </div>
          </div>
          <label class="toggle"
            ><input type="hidden" name="s3_path_style" value="0" /><input
              type="checkbox"
              name="s3_path_style"
              value="1"
              :defaultChecked="boolean(cloud.s3?.path_style ?? true)"
            /><span
              >Utilizar endereçamento path-style (recomendado para MinIO)</span
            ></label
          >
        </fieldset>
        <div class="actions">
          <button type="submit" class="button primary">
            Salvar armazenamento</button
          ><a
            :href="route('updater.section', { section: 'backups' })"
            class="button subtle"
            >Ver backups</a
          >
        </div>
      </ActionForm>
    </section>

    <section
      v-if="!env && tab === 'tokens'"
      id="panel-tokens"
      role="tabpanel"
      aria-labelledby="tab-tokens"
      class="stack"
    >
      <div v-if="newToken" class="notice success stack" role="status">
        <strong>Token criado. Copie agora.</strong>
        <p>
          O valor completo é exibido uma única vez. Guarde-o em um gerenciador
          de segredos.
        </p>
        <textarea
          class="token-output"
          readonly
          rows="3"
          :defaultValue="newToken"
          aria-label="Novo token de API"
          autocomplete="off"
          spellcheck="false"
        ></textarea>
        <div class="actions">
          <button type="button" class="button subtle" @click="copyToken">
            {{ copied ? "Token copiado" : "Copiar token" }}
          </button>
        </div>
      </div>
      <div class="panel stack">
        <header>
          <h2>
            {{
              rotatingToken
                ? `Rotacionar ${rotatingToken.name}`
                : "Criar token de API"
            }}
          </h2>
          <p class="muted">
            Conceda somente as operações necessárias. Tokens não substituem
            aprovações e controles de segurança.
          </p>
        </header>
        <div v-if="rotatingToken" class="notice warning">
          Ao criar o novo token, o token anterior será revogado. Atualize a
          credencial na integração antes da próxima operação.
        </div>
        <ActionForm
          :action="route('updater.settings.tokens.create')"
          class="stack"
          :confirm="
            rotatingToken
              ? 'Criar o novo token e revogar imediatamente o token anterior?'
              : undefined
          "
        >
          <input
            v-if="rotatingToken"
            type="hidden"
            name="rotate_token_id"
            :defaultValue="rotatingToken.id"
          />
          <label
            >Nome do token<input
              id="token-name"
              v-model="tokenName"
              name="name"
              required
              maxlength="120"
              placeholder="Ex.: monitoramento de produção"
          /></label>
          <fieldset>
            <legend>Escopos permitidos</legend>
            <div class="fields-grid">
              <label
                v-for="scope in scopes"
                :key="scope.value"
                class="toggle scope-option"
                ><input
                  v-model="tokenScopes"
                  type="checkbox"
                  name="scopes[]"
                  :defaultValue="scope.value"
                /><span
                  ><strong>{{ scope.label }}</strong
                  ><small class="muted">{{ scope.description }}</small
                  ><code>{{ scope.value }}</code></span
                ></label
              >
            </div>
          </fieldset>
          <p v-if="!tokenScopes.length" class="notice warning">
            Selecione pelo menos um escopo.
          </p>
          <div class="actions">
            <button
              class="button primary"
              type="submit"
              :disabled="!tokenScopes.length"
            >
              {{
                rotatingToken ? "Criar e rotacionar token" : "Criar token"
              }}</button
            ><button
              v-if="rotatingToken"
              type="button"
              class="button subtle"
              @click="cancelRotation"
            >
              Cancelar rotação</button
            ><a
              v-if="route('updater.settings.api-docs')"
              :href="route('updater.settings.api-docs')"
              class="button subtle"
              >Documentação da API</a
            >
          </div>
        </ActionForm>
      </div>
      <div class="panel stack">
        <header>
          <h2>Tokens cadastrados</h2>
          <p class="muted">
            A revogação impede imediatamente novos acessos com o token.
          </p>
        </header>
        <DataTable
          :rows="tokens"
          :columns="[
            { key: 'name', label: 'Nome' },
            { key: 'scopeLabel', label: 'Escopos' },
            { key: 'created_at', label: 'Criado em' },
            { key: 'lastUse', label: 'Último uso' },
            { key: 'statusLabel', label: 'Situação' },
          ]"
        >
          <template #actions="{ row }"
            ><div v-if="!row.revoked_at" class="actions">
              <button type="button" class="button subtle" @click="rotate(row)">
                Rotacionar</button
              ><ActionForm
                :action="
                  route('updater.settings.tokens.revoke', { id: row.id })
                "
                method="DELETE"
                :confirm="`Revogar o token ${row.name}? As integrações deixarão de autenticar com esta credencial.`"
                ><button class="button danger" type="submit">
                  Revogar
                </button></ActionForm
              >
            </div>
            <span v-else class="muted">Revogado</span></template
          >
        </DataTable>
      </div>
    </section>

    <section v-if="!env" class="settings-related">
      <h2>Outras configurações</h2>
      <div class="actions">
        <a
          v-if="can('sources.manage')"
          :href="route('updater.section', { section: 'sources' })"
          class="button subtle"
          >Fontes Git</a
        ><a
          v-if="can('profiles.manage')"
          :href="
            route('updater.profiles.index') ||
            route('updater.section', { section: 'profiles' })
          "
          class="button subtle"
          >Perfis de atualização</a
        ><a
          v-if="can('users.manage')"
          :href="
            route('updater.users.index') ||
            route('updater.section', { section: 'admin-users' })
          "
          class="button subtle"
          >Usuários e permissões</a
        >
      </div>
    </section>
  </div>
</template>

<style scoped>
.settings-runtime-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.15fr);
  gap: 1.5rem;
  padding: 1rem 0;
  border-bottom: 1px solid var(--border, #e3e7eb);
}
.settings-runtime-row:last-child {
  border-bottom: 0;
}
.settings-runtime-row > div {
  min-width: 0;
}
.settings-code {
  display: block;
  overflow-wrap: anywhere;
  margin: 0.25rem 0;
}
.settings-advanced {
  border-top: 1px solid var(--border, #e3e7eb);
  padding-top: 1rem;
}
.settings-advanced summary {
  cursor: pointer;
  font-weight: 600;
  margin-bottom: 1rem;
}
.settings-search {
  max-width: 36rem;
}
.settings-related {
  padding: 0.5rem 0;
}
.settings-related h2 {
  font-size: 1rem;
  margin-bottom: 0.75rem;
}
.scope-option {
  align-items: flex-start;
}
.scope-option small {
  display: block;
  margin: 0.2rem 0;
}
.scope-option code {
  font-size: 0.75rem;
  overflow-wrap: anywhere;
}
.token-output {
  font-family: var(--font-mono, monospace);
  overflow-wrap: anywhere;
}
.span-all {
  grid-column: 1 / -1;
}
@media (max-width: 767px) {
  .settings-runtime-row {
    grid-template-columns: 1fr;
    gap: 0.65rem;
  }
  .tabs {
    overflow: auto;
  }
  .tabs button {
    white-space: nowrap;
  }
}
</style>
