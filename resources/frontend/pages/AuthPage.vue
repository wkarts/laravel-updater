<script setup lang="ts">
import { route, state } from "../services/app";
import { computed, ref, watch } from "vue";
import ActionForm from "../components/ActionForm.vue";
import Icon from "../components/Icon.vue";
const props = defineProps<{ data: any; page: string }>();
const location = window.location;
const logoFailed = ref(false);
const brandImage = computed(() =>
  props.page === "maintenance"
    ? state.context.branding?.maintenance_logo_url ||
      state.context.branding?.logo_url
    : state.context.branding?.logo_url,
);
watch(brandImage, () => {
  logoFailed.value = false;
});
</script>
<template>
  <section class="auth-card">
    <a class="brand auth-brand" :href="route('updater.index') || '#'"
      ><img
        v-if="brandImage && !logoFailed"
        class="brand-image"
        :src="brandImage"
        :alt="`Logo de ${state.context.branding?.app_name_full || state.context.branding?.app_name || 'Updater Manager'}`"
        referrerpolicy="no-referrer"
        @error="logoFailed = true"
      /><Icon v-else name="cube" :size="32" /><span
        ><strong>{{
          state.context.branding?.app_name_full ||
          state.context.branding?.app_name ||
          "Updater Manager"
        }}</strong
        ><small>Central de atualização</small></span
      ></a
    ><template v-if="page === 'login' || page === 'twofactor'"
      ><p class="eyebrow">ACESSO SEGURO</p>
      <h1>
        {{
          page === "login"
            ? "Tudo pronto para continuar."
            : "Confirme sua identidade."
        }}
      </h1>
      <p class="muted">
        {{
          page === "login"
            ? "Acesse a central para acompanhar e gerenciar as atualizações da sua aplicação."
            : "Informe o código do autenticador ou um código de recuperação disponível."
        }}
      </p>
      <div
        v-if="state.context.flash?.status"
        class="notice success"
        role="status"
      >
        {{ state.context.flash.status }}
      </div>
      <div
        v-if="
          state.context.flash?.errors &&
          Object.keys(state.context.flash.errors).length
        "
        class="notice danger"
        role="alert"
      >
        <p v-for="(messages, key) in state.context.flash.errors" :key="key">
          {{ Array.isArray(messages) ? messages.join(" ") : messages }}
        </p>
      </div>
      <ActionForm
        :action="
          route(
            page === 'login' ? 'updater.login.submit' : 'updater.2fa.verify',
          )
        "
        class="stack"
        ><template v-if="page === 'login'"
          ><label
            >E-mail<input
              type="email"
              name="email"
              autocomplete="username"
              required /></label
          ><label
            >Senha<input
              type="password"
              name="password"
              autocomplete="current-password"
              required /></label></template
        ><label v-else
          >Código de autenticação ou recuperação<input
            name="code"
            autocomplete="one-time-code"
            required
            autofocus /></label
        ><button class="button primary full-width" type="submit">
          {{ page === "login" ? "Entrar na central" : "Verificar e continuar" }}
          <Icon name="arrow-right" :size="17" /></button
      ></ActionForm>
      <p class="auth-foot">
        Sua sessão utiliza a proteção e as permissões do Laravel Updater.
      </p></template
    ><template v-else-if="page === 'maintenance'"
      ><span class="badge running">Manutenção programada</span>
      <h1>
        {{
          data.title ||
          state.context.branding?.maintenance_title ||
          "Estamos preparando uma versão melhor."
        }}
      </h1>
      <p>
        {{
          data.message ||
          state.context.branding?.maintenance_message ||
          "A aplicação está temporariamente em manutenção. Tente novamente em alguns instantes."
        }}
      </p>
      <p class="muted">
        {{
          data.footer ||
          state.context.branding?.maintenance_footer ||
          "Obrigado pela compreensão."
        }}
      </p>
      <button class="button subtle" @click="location.reload()">
        Verificar disponibilidade
      </button></template
    ><template v-else
      ><p class="eyebrow">{{ data.status || state.statusCode || "ERRO" }}</p>
      <h1>{{ data.title || "Acesso indisponível" }}</h1>
      <p>
        {{
          data.message ||
          state.error ||
          "Você não pode acessar esta página neste momento."
        }}
      </p>
      <a
        class="button primary"
        :href="route('updater.index') || state.context.basePath"
        >Voltar à central</a
      ></template
    >
  </section>
</template>
