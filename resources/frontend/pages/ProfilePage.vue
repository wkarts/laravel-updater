<script setup lang="ts">
import { computed, ref, watch } from "vue";
import QRCode from "qrcode";
import ActionForm from "../components/ActionForm.vue";
import { can, route, state } from "../services/app";

const props = defineProps<{ data: any; page: string }>();
const user = computed(() => props.data.user || state.context.user || {});
const enabled = computed(() =>
  [true, 1, "1"].includes(user.value.totp_enabled),
);
const recovery = computed(() => props.data.recoverySummary || {});
const newCodes = computed<string[]>(
  () =>
    props.data.newRecoveryCodes ||
    state.context.flash?.twofactor_recovery_codes ||
    [],
);
const copied = ref(false);
const localQr = ref("");
watch(
  () => props.data.otpauthUri,
  async (uri: string) => {
    localQr.value = "";
    if (uri && !enabled.value && uri.startsWith("otpauth://")) {
      try {
        localQr.value = await QRCode.toDataURL(uri, {
          width: 220,
          margin: 2,
          errorCorrectionLevel: "M",
        });
      } catch {}
    }
  },
  { immediate: true },
);
async function copyCodes(): Promise<void> {
  try {
    await navigator.clipboard.writeText(newCodes.value.join("\n"));
    copied.value = true;
  } catch {
    copied.value = false;
  }
}
</script>

<template>
  <div v-if="!can('profile.manage')" role="alert" class="notice warning">
    Sua conta não tem permissão para editar o perfil.
  </div>
  <div v-else class="stack profile-page">
    <section class="panel profile-summary">
      <div>
        <h2>{{ user.name || "Conta administrativa" }}</h2>
        <p class="muted">{{ user.email }}</p>
      </div>
      <div class="profile-status">
        <span class="badge" :class="enabled ? 'success' : 'warning'">{{
          enabled ? "2FA habilitado" : "2FA desabilitado"
        }}</span
        ><small class="muted"
          >{{ recovery.disponiveis ?? 0 }} de {{ recovery.total ?? 0 }} códigos
          de recuperação disponíveis</small
        >
      </div>
    </section>

    <section v-if="newCodes.length" class="notice success stack" role="status">
      <header>
        <h2>Guarde seus códigos de recuperação</h2>
        <p>
          Cada código só pode ser utilizado uma vez. Esta é a única exibição dos
          valores completos.
        </p>
      </header>
      <div class="recovery-codes">
        <code v-for="code in newCodes" :key="code">{{ code }}</code>
      </div>
      <div class="actions">
        <button class="button subtle" type="button" @click="copyCodes">
          {{ copied ? "Códigos copiados" : "Copiar códigos" }}
        </button>
      </div>
    </section>

    <div class="profile-layout">
      <section class="panel stack">
        <header>
          <h2>Trocar senha</h2>
          <p class="muted">
            Use uma senha exclusiva para esta conta, com pelo menos 6
            caracteres.
          </p>
        </header>
        <ActionForm :action="route('updater.profile.password')" class="stack">
          <label
            >Nova senha<input
              type="password"
              name="password"
              required
              minlength="6"
              autocomplete="new-password"
          /></label>
          <label
            >Confirmar nova senha<input
              type="password"
              name="password_confirmation"
              required
              minlength="6"
              autocomplete="new-password"
          /></label>
          <div class="actions">
            <button class="button primary" type="submit">
              Salvar nova senha
            </button>
          </div>
        </ActionForm>
      </section>

      <section class="panel stack">
        <header>
          <h2>Autenticação em dois fatores</h2>
          <p class="muted">
            Além da senha, utilize um código temporário de um aplicativo
            autenticador.
          </p>
        </header>
        <template v-if="!enabled">
          <div v-if="localQr" class="profile-twofactor">
            <img
              :src="localQr"
              width="200"
              height="200"
              alt="QR Code para configurar um novo autenticador"
            />
            <p class="muted">
              Escaneie no seu autenticador e digite o código de seis dígitos
              para concluir.
            </p>
          </div>
          <details v-if="data.pendingTotpSecret" class="profile-manual">
            <summary>Configurar manualmente</summary>
            <p class="muted">
              Use esta chave somente para cadastrar o novo autenticador. Não
              compartilhe.
            </p>
            <code>{{ data.pendingTotpSecret }}</code>
          </details>
          <ActionForm
            v-if="localQr || data.pendingTotpSecret"
            :action="route('updater.profile.2fa.enable')"
            class="stack"
            ><label
              >Código do autenticador<input
                type="text"
                name="code"
                required
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]{6}"
                maxlength="6"
                placeholder="000000"
            /></label>
            <div class="actions">
              <button class="button primary" type="submit">
                Ativar dois fatores
              </button>
            </div></ActionForm
          >
          <p v-else class="notice warning">
            Não foi possível preparar a configuração do autenticador. Recarregue
            a página e tente novamente.
          </p>
        </template>
        <template v-else
          ><p class="notice success">
            A autenticação em dois fatores está ativa para sua conta.
          </p>
          <details class="profile-manual">
            <summary>Desativar autenticação em dois fatores</summary>
            <p class="muted">
              A desativação reduz a proteção da conta e exige sua senha atual.
            </p>
            <ActionForm
              :action="route('updater.profile.2fa.disable')"
              class="stack"
              confirm="Desativar a autenticação em dois fatores desta conta?"
              ><label
                >Senha atual<input
                  type="password"
                  name="password"
                  required
                  autocomplete="current-password"
              /></label>
              <div class="actions">
                <button class="button danger" type="submit">
                  Desativar dois fatores
                </button>
              </div></ActionForm
            >
          </details></template
        >
      </section>
    </div>

    <section class="panel stack">
      <header>
        <h2>Códigos de recuperação</h2>
        <p class="muted">
          Utilize um código quando estiver sem o autenticador. Gerar um novo
          conjunto invalida todos os códigos anteriores.
        </p>
      </header>
      <ActionForm
        :action="route('updater.profile.2fa.recovery.regenerate')"
        class="stack"
        confirm="Gerar novos códigos de recuperação? Todos os códigos anteriores serão invalidados."
        ><label class="profile-password"
          >Senha atual<input
            type="password"
            name="password"
            required
            autocomplete="current-password"
        /></label>
        <div class="actions">
          <button class="button subtle" type="submit">
            Gerar novos códigos
          </button>
        </div></ActionForm
      >
    </section>
  </div>
</template>

<style scoped>
.profile-summary {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
}
.profile-status {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.5rem;
}
.profile-layout {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1.5rem;
  align-items: start;
}
.profile-twofactor {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}
.profile-twofactor img {
  max-width: 100%;
  height: auto;
  border: 1px solid var(--border, #e3e7eb);
  border-radius: 0.75rem;
  background: white;
  padding: 0.5rem;
}
.profile-twofactor p {
  flex: 1 1 10rem;
}
.profile-manual summary {
  cursor: pointer;
  font-weight: 600;
  margin-bottom: 0.75rem;
}
.profile-manual code {
  display: block;
  overflow-wrap: anywhere;
  padding: 0.75rem;
  background: var(--surface-soft, #f5f7f8);
  border-radius: 0.5rem;
}
.recovery-codes {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}
.recovery-codes code {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #d9e5de);
  border-radius: 0.5rem;
  padding: 0.65rem;
  text-align: center;
  overflow-wrap: anywhere;
}
.profile-password {
  max-width: 30rem;
}
@media (max-width: 767px) {
  .profile-layout {
    grid-template-columns: 1fr;
  }
  .profile-summary {
    flex-direction: column;
    align-items: flex-start;
  }
  .profile-status {
    align-items: flex-start;
  }
  .profile-twofactor {
    flex-direction: column;
    align-items: flex-start;
  }
  .profile-twofactor p {
    flex-basis: auto;
  }
}
</style>
