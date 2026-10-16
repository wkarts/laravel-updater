<script setup lang="ts">
import { computed } from "vue";
import { route } from "../services/app";
import ActionForm from "../components/ActionForm.vue";
import DataTable from "../components/DataTable.vue";
const props = defineProps<{ data: any; page: string }>();
const source = computed(() => props.data.editingSource || {});
const user = computed(() => props.data.user || {});
const profile = computed(() => props.data.profile || {});
const sourceAllowed = computed(
  () =>
    props.data.allowMultipleSources ||
    !!props.data.editingSource ||
    !props.data.sources?.length,
);
const bool = (value: any) => value === true || value === 1 || value === "1";
const selectedPermissions = computed(() => {
  try {
    return (
      props.data.user?.permissions ||
      JSON.parse(props.data.user?.permissions_json || "[]")
    );
  } catch {
    return [];
  }
});
const profileSwitches: Record<string, string> = {
  backup_enabled: "Ativar backups",
  dry_run: "Simular por padrão",
  force: "Forçar execução",
  composer_install: "Executar Composer",
  migrate: "Executar migrations",
  seed: "Executar seeds",
  rollback_on_fail: "Rollback em caso de falha",
  snapshot_include_vendor: "Incluir vendor no snapshot",
  active: "Usar como perfil ativo",
};
</script>
<template>
  <div class="stack">
    <template v-if="page === 'sections.sources'"
      ><p class="page-intro">
        Defina a origem do código e suas credenciais. Apenas uma fonte fica
        ativa por vez.
      </p>
      <div class="two-column">
        <section class="panel">
          <div class="panel-heading">
            <h2>{{ source.id ? "Editar fonte" : "Cadastrar fonte" }}</h2>
            <span v-if="source.id" class="badge">#{{ source.id }}</span>
          </div>
          <ActionForm
            v-if="sourceAllowed"
            :action="route('updater.sources.save')"
            ><input type="hidden" name="id" :defaultValue="source.id || ''" />
            <div class="fields-grid">
              <label
                >Nome da fonte<input
                  name="name"
                  :defaultValue="source.name || ''"
                  maxlength="120"
                  required /></label
              ><label
                >Tipo de origem<select
                  name="type"
                  :value="source.type || 'git_merge'"
                >
                  <option value="git_merge">Git merge</option>
                  <option value="git_ff_only">Git ff-only</option>
                  <option value="git_tag">Git por tag</option>
                  <option value="zip_release">ZIP release</option>
                  <option value="github">GitHub</option>
                  <option value="gitlab">GitLab</option>
                  <option value="bitbucket">Bitbucket</option>
                </select></label
              ><label class="wide"
                >URL do repositório<input
                  name="repo_url"
                  :defaultValue="source.repo_url || ''"
                  required
                  placeholder="https://github.com/organizacao/repositorio.git"
                /><small
                  >Use a URL sem senha ou token. As credenciais devem ser
                  cadastradas nos campos próprios.</small
                ></label
              ><label
                >Branch padrão<input
                  name="branch"
                  :defaultValue="source.branch || 'main'" /></label
              ><label
                >Autenticação<select
                  name="auth_mode"
                  :value="source.auth_mode || 'none'"
                >
                  <option value="none">Sem autenticação</option>
                  <option value="token">Token ou senha</option>
                  <option value="ssh">SSH</option>
                </select></label
              ><label class="wide"
                >Usuário de autenticação<input
                  name="auth_username"
                  :defaultValue="source.auth_username || ''"
                  autocomplete="off"
                /><small>Opcional. Ex.: x-access-token.</small></label
              ><label class="wide"
                >Substituir senha ou token<span class="secret-status">{{
                  source.auth_password_configured
                    ? "Credencial configurada"
                    : "Não configurada"
                }}</span
                ><input
                  name="auth_password"
                  type="password"
                  autocomplete="new-password"
                  placeholder="Deixe vazio para manter a credencial existente" /></label
              ><label class="wide"
                >Substituir token alternativo<span class="secret-status">{{
                  source.token_encrypted_configured
                    ? "Token configurado"
                    : "Não configurado"
                }}</span
                ><input
                  name="token_encrypted"
                  type="password"
                  autocomplete="new-password"
                  placeholder="Deixe vazio para manter" /></label
              ><label class="wide"
                >Substituir caminho da chave SSH<span class="secret-status">{{
                  source.ssh_private_key_path_configured
                    ? "Chave configurada"
                    : "Não configurada"
                }}</span
                ><input
                  name="ssh_private_key_path"
                  type="password"
                  autocomplete="new-password"
                  placeholder="Caminho no servidor, vazio mantém o atual"
              /></label>
            </div>
            <label v-if="source.id" class="toggle"
              ><input
                type="checkbox"
                name="remove_credentials"
                value="1"
              /><span
                >Remover todas as credenciais existentes desta fonte ao
                salvar.</span
              ></label
            ><label class="toggle"
              ><input
                type="checkbox"
                name="active"
                value="1"
                :defaultChecked="bool(source.active)"
              /><span>Usar como fonte ativa.</span></label
            >
            <div class="actions">
              <button class="button primary">
                {{
                  source.id ? "Salvar alterações" : "Cadastrar fonte"
                }}</button
              ><a
                v-if="source.id"
                class="button subtle"
                :href="route('updater.section', { section: 'sources' })"
                >Cancelar</a
              >
            </div></ActionForm
          >
          <div v-else class="notice warning">
            <strong>Uma fonte já está cadastrada.</strong>
            <p>
              Edite a origem existente. O cadastro de múltiplas fontes depende
              da configuração administrativa.
            </p>
          </div>
        </section>
        <section class="panel">
          <h2>Fontes cadastradas</h2>
          <DataTable
            :rows="data.sources || []"
            :columns="[
              { key: 'name', label: 'Nome' },
              { key: 'branch', label: 'Branch' },
              {
                key: 'active',
                label: 'Ativa',
                format: (v) => (bool(v) ? 'Sim' : 'Não'),
              },
            ]"
            ><template #actions="{ row }"
              ><a
                class="button subtle"
                :href="
                  route('updater.section', { section: 'sources', edit: row.id })
                "
                >Editar</a
              ><ActionForm :action="route('updater.sources.test')"
                ><input
                  type="hidden"
                  name="source_id"
                  :defaultValue="row.id"
                /><button class="button subtle">
                  Testar conexão
                </button></ActionForm
              ><ActionForm
                v-if="!bool(row.active)"
                :action="route('updater.sources.activate', { id: row.id })"
                ><button class="button subtle">Ativar</button></ActionForm
              ><ActionForm
                :action="route('updater.sources.delete', { id: row.id })"
                method="DELETE"
                confirm="Excluir esta fonte? As próximas operações precisarão de outra origem configurada."
                ><button class="button danger">Excluir</button></ActionForm
              ></template
            ></DataTable
          >
        </section>
      </div></template
    >
    <template v-else-if="page === 'users.index'"
      ><p class="page-intro">
        Gerencie o acesso à central com permissões por operação e autenticação
        em dois fatores.
      </p>
      <section class="panel">
        <div class="panel-heading">
          <h2>Pessoas com acesso</h2>
          <a class="button primary" :href="route('updater.users.create')"
            >+ Novo usuário</a
          >
        </div>
        <DataTable
          :rows="data.users || []"
          :columns="[
            { key: 'name', label: 'Nome' },
            { key: 'email', label: 'E-mail' },
            {
              key: 'is_admin',
              label: 'Administrador',
              format: (v) => (bool(v) ? 'Sim' : 'Não'),
            },
            {
              key: 'is_active',
              label: 'Ativo',
              format: (v) => (bool(v) ? 'Sim' : 'Não'),
            },
            {
              key: 'totp_enabled',
              label: '2FA',
              format: (v) => (bool(v) ? 'Ativado' : 'Desativado'),
            },
            { key: 'last_login_at', label: 'Último acesso' },
          ]"
          ><template #actions="{ row }"
            ><a
              class="button subtle"
              :href="route('updater.users.edit', { id: row.id })"
              >Editar</a
            ><ActionForm
              :action="route('updater.users.delete', { id: row.id })"
              method="DELETE"
              confirm="Excluir o acesso deste usuário à central?"
              ><button class="button danger">Excluir</button></ActionForm
            ></template
          ></DataTable
        >
      </section></template
    >
    <template v-else-if="page === 'users.create' || page === 'users.edit'"
      ><div class="two-column">
        <section class="panel">
          <h2>
            {{ user.id ? "Dados do usuário" : "Novo acesso administrativo" }}
          </h2>
          <ActionForm
            :action="
              route(
                user.id ? 'updater.users.update' : 'updater.users.store',
                user.id ? { id: user.id } : {},
              )
            "
            :method="user.id ? 'PUT' : 'POST'"
            ><div class="fields-grid">
              <label
                >Nome<input
                  name="name"
                  :defaultValue="user.name || ''"
                  required
                  maxlength="120"
                  autocomplete="name" /></label
              ><label
                >E-mail<input
                  type="email"
                  name="email"
                  :defaultValue="user.email || ''"
                  required
                  maxlength="190"
                  autocomplete="email" /></label
              ><label
                >{{ user.id ? "Nova senha" : "Senha"
                }}<input
                  type="password"
                  name="password"
                  :required="!user.id"
                  minlength="6"
                  autocomplete="new-password"
                /><small v-if="user.id"
                  >Deixe vazia para manter a senha atual.</small
                ></label
              ><label
                >Confirmar senha<input
                  name="password_confirmation"
                  type="password"
                  :required="!user.id"
                  minlength="6"
                  autocomplete="new-password"
              /></label>
            </div>
            <label class="toggle"
              ><input
                type="checkbox"
                name="is_admin"
                value="1"
                :defaultChecked="bool(user.is_admin)"
              /><span>Administrador.</span></label
            ><label class="toggle"
              ><input type="hidden" name="is_active" value="0" /><input
                type="checkbox"
                name="is_active"
                value="1"
                :defaultChecked="!user.id || bool(user.is_active)"
              /><span>Acesso ativo.</span></label
            >
            <div class="section-divider">
              <h3>Permissões de acesso</h3>
              <p
                v-if="data.masterEmail && user.email === data.masterEmail"
                class="notice"
              >
                A conta master definida no ambiente possui acesso completo.
              </p>
              <div class="permissions-grid">
                <label
                  v-for="(label, key) in data.permissionDefinitions || {}"
                  :key="key"
                  class="toggle"
                  ><input
                    type="checkbox"
                    name="permissions[]"
                    :defaultValue="key"
                    :defaultChecked="selectedPermissions.includes(key)"
                  /><span>{{ label }}</span></label
                >
              </div>
            </div>
            <div class="actions">
              <button class="button primary">Salvar usuário</button
              ><a class="button subtle" :href="route('updater.users.index')"
                >Cancelar</a
              >
            </div></ActionForm
          >
        </section>
        <section v-if="user.id" class="panel danger-zone">
          <h2>Redefinir autenticação 2FA</h2>
          <p class="muted">
            Estado atual:
            {{ bool(user.totp_enabled) ? "ativada" : "desativada" }}. A
            redefinição remove a configuração do autenticador e os códigos de
            recuperação.
          </p>
          <ActionForm
            :action="route('updater.users.2fa.reset', { id: user.id })"
            confirm="Redefinir a autenticação 2FA deste usuário? Ele precisará configurar um novo autenticador."
            ><label
              >Sua senha atual de administrador<input
                type="password"
                name="admin_password"
                required
                autocomplete="current-password" /></label
            ><button class="button danger">Redefinir 2FA</button></ActionForm
          >
        </section>
      </div></template
    >
    <template v-else-if="page === 'profiles.index'"
      ><p class="page-intro">
        Organize os parâmetros e as etapas confiáveis de cada fluxo de
        atualização.
      </p>
      <section class="panel">
        <div class="panel-heading">
          <h2>Perfis de execução</h2>
          <a class="button primary" :href="route('updater.profiles.create')"
            >+ Novo perfil</a
          >
        </div>
        <DataTable
          :rows="data.profiles || []"
          :columns="[
            { key: 'name', label: 'Perfil' },
            {
              key: 'backup_enabled',
              label: 'Backup',
              format: (v) => (bool(v) ? 'Ativado' : 'Desativado'),
            },
            {
              key: 'dry_run',
              label: 'Simulação padrão',
              format: (v) => (bool(v) ? 'Sim' : 'Não'),
            },
            { key: 'retention_backups', label: 'Retenção' },
            {
              key: 'active',
              label: 'Ativo',
              format: (v) => (bool(v) ? 'Sim' : 'Não'),
            },
          ]"
          ><template #actions="{ row }"
            ><a
              class="button subtle"
              :href="route('updater.profiles.edit', { id: row.id })"
              >Editar</a
            ><ActionForm
              v-if="!bool(row.active)"
              :action="route('updater.profiles.activate', { id: row.id })"
              ><button class="button subtle">Ativar</button></ActionForm
            ><ActionForm
              :action="route('updater.profiles.delete', { id: row.id })"
              method="DELETE"
              confirm="Excluir este perfil de atualização?"
              ><button class="button danger">Excluir</button></ActionForm
            ></template
          ></DataTable
        >
      </section></template
    >
    <template v-else
      ><section class="panel">
        <h2>Parâmetros do perfil</h2>
        <ActionForm
          :action="
            route(
              profile.id ? 'updater.profiles.update' : 'updater.profiles.store',
              profile.id ? { id: profile.id } : {},
            )
          "
          :method="profile.id ? 'PUT' : 'POST'"
          ><div class="fields-grid">
            <label
              >Nome do perfil<input
                name="name"
                :defaultValue="profile.name || ''"
                required /></label
            ><label
              >Retenção de backups<input
                type="number"
                name="retention_backups"
                :defaultValue="profile.retention_backups ?? 10"
                min="1"
                max="200" /></label
            ><label
              >Compressão do snapshot<select
                name="snapshot_compression"
                value="zip"
              >
                <option value="zip">ZIP</option>
              </select></label
            >
          </div>
          <div class="permissions-grid">
            <label
              v-for="(label, key) in profileSwitches"
              :key="key"
              class="toggle"
              ><input
                type="checkbox"
                :name="key"
                value="1"
                :defaultChecked="bool(profile[key])"
              /><span>{{ label }}</span></label
            >
          </div>
          <div class="notice warning">
            <strong>Comandos administrativos confiáveis.</strong>
            <p>
              Os comandos abaixo executam no servidor conforme as permissões do
              processo. Use somente instruções revisadas. APIs e webhooks não
              podem alterá-los.
            </p>
          </div>
          <label
            >Substituir comandos anteriores à atualização<span
              v-if="profile.pre_update_commands_configured"
              class="secret-status"
              >Comandos já configurados</span
            ><textarea
              name="pre_update_commands"
              rows="5"
              placeholder="Um comando por linha. Vazio mantém os comandos existentes."
            ></textarea
            ><small
              >Linhas iniciadas por # ficam desativadas. Comandos existentes são
              ocultados para proteger possíveis credenciais.</small
            ></label
          ><label v-if="profile.id" class="toggle"
            ><input
              type="checkbox"
              name="remove_pre_update_commands"
              value="1"
            /><span
              >Remover os comandos anteriores já configurados.</span
            ></label
          ><label
            >Substituir comandos após a atualização<span
              v-if="profile.post_update_commands_configured"
              class="secret-status"
              >Comandos já configurados</span
            ><textarea
              name="post_update_commands"
              rows="6"
              placeholder="Um comando por linha. Vazio mantém os comandos existentes."
            ></textarea></label
          ><label v-if="profile.id" class="toggle"
            ><input
              type="checkbox"
              name="remove_post_update_commands"
              value="1"
            /><span
              >Remover os comandos posteriores já configurados.</span
            ></label
          >
          <div class="actions">
            <button class="button primary">Salvar perfil</button
            ><a class="button subtle" :href="route('updater.profiles.index')"
              >Cancelar</a
            >
          </div></ActionForm
        >
      </section></template
    >
  </div>
</template>
