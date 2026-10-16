<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { can, loadPage, localUrl, route, state } from "./services/app";
import ActionForm from "./components/ActionForm.vue";
import Icon from "./components/Icon.vue";
import AuthPage from "./pages/AuthPage.vue";
import DashboardPage from "./pages/DashboardPage.vue";
import UpdatesPage from "./pages/UpdatesPage.vue";
import OperationsPage from "./pages/OperationsPage.vue";
import AdminPage from "./pages/AdminPage.vue";
import SettingsPage from "./pages/SettingsPage.vue";
import ProfilePage from "./pages/ProfilePage.vue";
import IntegrationsPage from "./pages/IntegrationsPage.vue";
const location = window.location;
const current = useRoute();
const router = useRouter();
const menuOpen = ref(false);
const menuButton = ref<HTMLButtonElement>();
const closeButton = ref<HTMLButtonElement>();
const sidebar = ref<HTMLElement>();
function closeMenu() {
  menuOpen.value = false;
}
function menuKeys(event: KeyboardEvent) {
  if (!menuOpen.value) return;
  if (event.key === "Escape") {
    event.preventDefault();
    closeMenu();
  }
  if (event.key === "Tab") {
    const focusable = Array.from(
      sidebar.value?.querySelectorAll<HTMLElement>(
        "a[href],button:not([disabled])",
      ) || [],
    ).filter((item) => item.getClientRects().length);
    const first = focusable[0],
      last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  }
}
watch(menuOpen, async (open) => {
  document.body.style.overflow = open ? "hidden" : "";
  await nextTick();
  if (open) closeButton.value?.focus();
  else menuButton.value?.focus();
});
const logoFailed = ref(false);
watch(
  () => state.context.branding?.logo_url,
  () => {
    logoFailed.value = false;
  },
);
const pageTitle = computed(
  () =>
    ({
      dashboard: "Visão geral",
      login: "Acessar central",
      twofactor: "Verificar identidade",
      profile: "Meu perfil",
      "sections.updates": "Atualizações",
      "sections.runs": "Execuções",
      "runs.show": `Diagnóstico da execução #${state.data.run?.id ?? ""}`,
      "sections.backups": "Backups",
      "backups.restore": "Restaurar backup",
      "sections.sources": "Fontes Git",
      "profiles.index": "Perfis de atualização",
      "profiles.create": "Novo perfil",
      "profiles.edit": "Editar perfil",
      "users.index": "Usuários administrativos",
      "users.create": "Novo usuário",
      "users.edit": "Editar usuário",
      "sections.logs": "Logs",
      "sections.seeds": "Seeds",
      "sections.migrations": "Auditoria de migrations",
      "sections.migrations-show": "Detalhes da migration",
      "sections.security": "Segurança e integridade",
      "settings.index": "Configurações",
      "settings.env": "Variáveis de ambiente",
      "webhooks.index": "Integrações",
      "api-docs": "Documentação da API",
      "api.docs": "Documentação da API",
      maintenance: "Manutenção em andamento",
    })[state.page] ?? "Central de atualização",
);
const authLayout = computed(() =>
  ["login", "twofactor", "maintenance", "error"].includes(state.page),
);
const navigation = computed(() =>
  [
    {
      id: "dashboard",
      label: "Visão geral",
      url: route("updater.index"),
      permission: "dashboard.view",
      icon: "home",
      group: "",
    },
    {
      id: "updates",
      label: "Atualizações",
      url: route("updater.section", { section: "updates" }),
      permission: "updates.view",
      icon: "refresh",
      group: "Operação",
    },
    {
      id: "runs",
      label: "Execuções",
      url: route("updater.section", { section: "runs" }),
      permission: "runs.view",
      icon: "history",
      group: "Operação",
    },
    {
      id: "sources",
      label: "Fontes",
      url: route("updater.section", { section: "sources" }),
      permission: "sources.manage",
      icon: "database",
      group: "Operação",
    },
    {
      id: "profiles",
      label: "Perfis",
      url: route("updater.profiles.index"),
      permission: "profiles.manage",
      icon: "file",
      group: "Operação",
    },
    {
      id: "backups",
      label: "Backups",
      url: route("updater.section", { section: "backups" }),
      permission: "backups.manage",
      icon: "database",
      group: "Integridade",
    },
    {
      id: "migrations",
      label: "Migrations",
      url: route("updater.migrations.index"),
      permission: "migrations.manage",
      icon: "code",
      group: "Integridade",
    },
    {
      id: "seeds",
      label: "Seeds",
      url: route("updater.seeds.index"),
      permission: "seeds.manage",
      icon: "layers",
      group: "Integridade",
    },
    {
      id: "logs",
      label: "Logs",
      url: route("updater.section", { section: "logs" }),
      permission: "logs.view",
      icon: "file",
      group: "Integridade",
    },
    {
      id: "users",
      label: "Usuários",
      url: route("updater.users.index"),
      permission: "users.manage",
      icon: "users",
      group: "Administração",
    },
    {
      id: "security",
      label: "Segurança",
      url: route("updater.section", { section: "security" }),
      permission: "settings.manage",
      icon: "shield",
      group: "Administração",
    },
    {
      id: "settings",
      label: "Configurações",
      url: route("updater.settings.index"),
      permission: "settings.manage",
      icon: "settings",
      group: "Administração",
    },
    {
      id: "integrations",
      label: "Integrações",
      url:
        route("updater.settings.webhooks.index") ||
        route("updater.settings.api-docs") ||
        route("updater.api.docs"),
      permission: "settings.manage",
      icon: "link",
      group: "Administração",
    },
  ].filter((item) => item.url && can(item.permission)),
);
const navGroups = computed(() =>
  ["", "Operação", "Integridade", "Administração"]
    .map((name) => ({
      name,
      items: navigation.value.filter((item) => item.group === name),
    }))
    .filter((group) => group.items.length),
);
const isActive = (item: { id: string; url: string }) => {
  if (item.id === "integrations")
    return /webhooks|api\.docs|api-docs/.test(state.page);
  if (item.id === "runs" && state.page === "runs.show") return true;
  if (item.id === "profiles" && state.page.startsWith("profiles.")) return true;
  if (item.id === "users" && state.page.startsWith("users.")) return true;
  if (item.id === "migrations" && state.page.startsWith("sections.migrations"))
    return true;
  if (item.id === "backups" && state.page === "backups.restore") return true;
  if (item.id === "settings" && state.page.startsWith("settings.")) return true;
  const parsed = new URL(item.url, location.origin);
  return (
    parsed.pathname.replace(/\/$/, "") === location.pathname.replace(/\/$/, "")
  );
};
const brandName = computed(
  () =>
    state.context.branding?.app_name_full ||
    state.context.branding?.app_name ||
    "Updater Manager",
);
const accountName = computed(
  () =>
    state.context.user?.name ||
    state.context.user?.email ||
    "Conta da instalação",
);
const accountInitials = computed(() =>
  accountName.value
    .split(/\s+/)
    .slice(0, 2)
    .map((part: string) => part[0])
    .join("")
    .toUpperCase(),
);
const pageDescription = computed(
  () =>
    ({
      dashboard: "Acompanhe o estado do sistema e o histórico de atualizações.",
      "sections.updates":
        "Gerencie as versões disponíveis e planeje a atualização do sistema.",
      "sections.runs":
        "Consulte o histórico e os resultados das operações do Updater Manager.",
      "runs.show":
        "Acompanhe o progresso e os detalhes de uma execução de atualização.",
      "sections.backups":
        "Gerencie as cópias de segurança da aplicação e do banco de dados.",
      "backups.restore":
        "Revise o backup e confirme a restauração com segurança.",
      "sections.sources":
        "Configure as origens de código e a autenticação dos repositórios.",
      "profiles.index":
        "Organize os parâmetros e as etapas de cada fluxo de atualização.",
      "profiles.create": "Configure um novo perfil de execução.",
      "profiles.edit": "Revise os parâmetros do perfil de execução.",
      "sections.migrations":
        "Acompanhe a integridade, o histórico e a execução das migrations.",
      "sections.migrations-show":
        "Consulte a consistência e o histórico desta migration.",
      "sections.seeds":
        "Consulte os seeds aplicados e controle as reexecuções.",
      "sections.logs": "Consulte os eventos e os registros da ferramenta.",
      "sections.security":
        "Gerencie a proteção de acesso e os controles da instalação.",
      "users.index": "Gerencie as pessoas com acesso e suas permissões.",
      "users.create": "Cadastre um novo acesso à central.",
      "users.edit": "Atualize o acesso e a segurança do usuário.",
      "settings.index":
        "Personalize a ferramenta e os parâmetros da instalação.",
      "settings.env": "Revise as variáveis de ambiente do Laravel Updater.",
      profile:
        "Gerencie seu acesso, sua senha e a autenticação em dois fatores.",
      "webhooks.index":
        "Conecte o Updater Manager com seus sistemas via API e webhooks.",
      "api.docs":
        "Consulte os contratos, a autenticação e os exemplos da API própria.",
    })[state.page] || location.hostname,
);
watch(
  () => current.fullPath,
  async () => {
    menuOpen.value = false;
    if (state.acceptedNavigation === current.fullPath) {
      state.acceptedNavigation = "";
      window.scrollTo({ top: 0 });
      return;
    }
    await loadPage(
      `${state.context.basePath.replace(/\/$/, "")}${current.fullPath === "/" ? "/" : current.fullPath}`,
    );
    window.scrollTo({ top: 0 });
  },
);
watch(
  pageTitle,
  (value) => {
    document.title = `${value} · ${state.context.branding?.app_name ?? "Updater Manager"}`;
  },
  { immediate: true },
);
function navigate(event: MouseEvent) {
  const target = (event.target as HTMLElement).closest("a");
  if (
    !target ||
    event.defaultPrevented ||
    event.ctrlKey ||
    event.metaKey ||
    event.shiftKey ||
    target.target ||
    target.download ||
    target.dataset.external
  )
    return;
  const url = new URL(target.href, location.origin);
  const prefix = state.context.basePath.replace(/\/$/, "");
  if (
    url.origin !== location.origin ||
    (url.pathname !== prefix && !url.pathname.startsWith(prefix + "/"))
  )
    return;
  const remainder = url.pathname.slice(prefix.length);
  if (
    /\/(?:api|assets)\//.test(remainder) ||
    /(?:\/download|\/diagnostics\/|\/progress\/|\/status$|\/openapi)/.test(
      remainder,
    ) ||
    url.searchParams.has("download")
  )
    return;
  event.preventDefault();
  router.push(localUrl(url.href));
}
onMounted(() => {
  window.addEventListener("keydown", menuKeys);
  try {
    const savedTheme = localStorage.getItem("updater-theme");
    if (savedTheme === "dark") {
      document.documentElement.dataset.theme = "dark";
      document.documentElement.style.removeProperty("--primary");
    }
  } catch {}
});
onUnmounted(() => window.removeEventListener("keydown", menuKeys));
watch(
  () => state.context.branding?.primary_color,
  (color) => {
    if (
      typeof color === "string" &&
      /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(color) &&
      document.documentElement.dataset.theme !== "dark"
    )
      document.documentElement.style.setProperty("--primary", color);
    else document.documentElement.style.removeProperty("--primary");
  },
  { immediate: true },
);
function theme() {
  const next =
    document.documentElement.dataset.theme === "dark" ? "light" : "dark";
  document.documentElement.dataset.theme = next;
  const primary = state.context.branding?.primary_color;
  if (
    next === "light" &&
    typeof primary === "string" &&
    /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(primary)
  )
    document.documentElement.style.setProperty("--primary", primary);
  else document.documentElement.style.removeProperty("--primary");
  try {
    localStorage.setItem("updater-theme", next);
  } catch {}
}
const pageComponent = computed(() => {
  if (authLayout.value) return AuthPage;
  if (state.page === "dashboard" || state.page === "index")
    return DashboardPage;
  if (state.page === "sections.updates") return UpdatesPage;
  if (state.page.startsWith("settings.")) return SettingsPage;
  if (state.page === "profile") return ProfilePage;
  if (
    /^(users|profiles)\./.test(state.page) ||
    state.page === "sections.sources"
  )
    return AdminPage;
  if (
    state.page.includes("webhooks") ||
    state.page.includes("api") ||
    state.page.includes("docs")
  )
    return IntegrationsPage;
  return OperationsPage;
});
</script>
<template>
  <div @click="navigate">
    <a class="skip-link" href="#main-content">Pular para o conteúdo</a>
    <div v-if="!authLayout" class="app-shell">
      <button
        v-if="menuOpen"
        class="sidebar-shade"
        aria-label="Fechar menu"
        @click="closeMenu"
      />
      <aside
        ref="sidebar"
        class="sidebar"
        :class="{ open: menuOpen }"
        :role="menuOpen ? 'dialog' : undefined"
        :aria-modal="menuOpen ? true : undefined"
        aria-label="Navegação do updater"
      >
        <button
          ref="closeButton"
          class="button subtle sidebar-close"
          type="button"
          @click="closeMenu"
        >
          Fechar navegação <Icon name="close" :size="18" />
        </button>
        <a class="brand" :href="route('updater.index')"
          ><img
            v-if="state.context.branding?.logo_url && !logoFailed"
            class="brand-image"
            :src="state.context.branding.logo_url"
            :alt="`Logo de ${brandName}`"
            referrerpolicy="no-referrer"
            @error="logoFailed = true"
          /><Icon v-else class="brand-cube" name="cube" :size="30" /><strong>{{
            brandName
          }}</strong></a
        >
        <nav aria-label="Navegação principal">
          <div v-for="group in navGroups" :key="group.name" class="nav-group">
            <p v-if="group.name" class="nav-group-label">{{ group.name }}</p>
            <a
              v-for="item in group.items"
              :key="item.id"
              :href="item.url"
              :class="{ active: isActive(item) }"
              :aria-current="isActive(item) ? 'page' : undefined"
              ><Icon :name="item.icon" :size="20" /><span>{{
                item.label
              }}</span></a
            >
          </div>
        </nav>
        <div class="sidebar-footer">
          <span class="status-dot" /><span>{{
            state.context.authEnabled
              ? "Acesso autenticado"
              : "Autenticação da aplicação"
          }}</span
          ><small>Laravel Updater</small>
        </div>
      </aside>
      <div class="workspace">
        <header class="topbar">
          <div class="mobile-header-line">
            <button
              ref="menuButton"
              class="icon-button mobile-menu"
              type="button"
              aria-label="Abrir navegação"
              :aria-expanded="menuOpen"
              @click="menuOpen = !menuOpen"
            >
              <Icon name="menu" :size="21" /></button
            ><a class="mobile-brand" :href="route('updater.index')"
              ><img
                v-if="state.context.branding?.logo_url && !logoFailed"
                class="brand-image"
                :src="state.context.branding.logo_url"
                :alt="`Logo de ${brandName}`"
                @error="logoFailed = true"
              /><Icon v-else name="cube" :size="27" /><strong>{{
                brandName
              }}</strong></a
            >
          </div>
          <div class="header-page-title">
            <h1>{{ pageTitle }}</h1>
            <p>{{ pageDescription }}</p>
          </div>
          <div class="topbar-actions">
            <button
              class="icon-button header-refresh"
              type="button"
              aria-label="Atualizar dados"
              :disabled="state.loading"
              @click="loadPage(location.href)"
            >
              <Icon name="refresh" :size="19" />
            </button>
            <details class="account-menu">
              <summary>
                <span class="avatar">{{ accountInitials }}</span
                ><span class="account-name">{{ accountName }}</span
                ><Icon name="chevron-down" :size="15" />
              </summary>
              <div class="account-dropdown">
                <a
                  v-if="route('updater.profile') && can('profile.manage')"
                  :href="route('updater.profile')"
                  ><Icon name="user" :size="17" />Meu perfil</a
                ><button type="button" @click="theme">
                  <Icon name="moon" :size="17" />Alternar tema</button
                ><ActionForm
                  v-if="route('updater.logout')"
                  :action="route('updater.logout')"
                  ><button type="submit">
                    <Icon name="logout" :size="17" />Sair
                  </button></ActionForm
                >
              </div>
            </details>
          </div>
        </header>
        <main id="main-content" tabindex="-1" class="main-content">
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
            <p v-for="(error, key) in state.context.flash.errors" :key="key">
              {{ Array.isArray(error) ? error.join(" ") : error }}
            </p>
          </div>
          <div v-if="state.loading" class="loading-state" role="status">
            <span class="loading-line" />Carregando informações do servidor…
          </div>
          <section v-if="state.error" class="panel empty-state" role="alert">
            <h2>
              {{
                state.statusCode === 403
                  ? "Acesso restrito"
                  : "Não foi possível carregar"
              }}
            </h2>
            <p>{{ state.error }}</p>
            <button class="button primary" @click="loadPage(location.href)">
              Tentar novamente
            </button>
          </section>
          <component
            :is="pageComponent"
            v-else-if="!state.loading"
            :key="state.page + current.fullPath"
            :data="state.data"
            :page="state.page"
          />
        </main>
      </div>
    </div>
    <main v-else id="main-content" class="auth-shell">
      <component :is="pageComponent" :data="state.data" :page="state.page" />
    </main>
  </div>
</template>
