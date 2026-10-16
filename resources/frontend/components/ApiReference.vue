<script setup lang="ts">
import { computed, onBeforeUnmount, onErrorCaptured, ref } from "vue";
import {
  ApiReference as ScalarApiReference,
  type ReferenceProps,
} from "@scalar/api-reference";
import "@scalar/api-reference/style.css";

const props = defineProps<{
  specification?: Record<string, any> | null;
  baseUrl: string;
}>();
const failed = ref(false);
// Scalar applies a body color-mode class; restore the host's state when leaving.
const originalBodyModes = ["light-mode", "dark-mode"].filter((mode) =>
  document.body.classList.contains(mode),
);
onBeforeUnmount(() => {
  document.body.classList.remove("light-mode", "dark-mode");
  document.body.classList.add(...originalBodyModes);
});
const available = computed(
  () =>
    typeof props.specification?.openapi === "string" &&
    !!props.specification.paths &&
    typeof props.specification.paths === "object",
);

/** Requests remain explicit, local API calls and never inherit the web session. */
async function fetchApi(
  input: string | URL | Request,
  init?: RequestInit,
): Promise<Response> {
  const target = new URL(
    input instanceof Request ? input.url : String(input),
    window.location.origin,
  );
  const server = new URL(props.baseUrl, window.location.origin);
  const apiPath = `${server.pathname.replace(/\/$/, "")}/api`;
  if (
    target.origin !== server.origin ||
    target.username ||
    target.password ||
    (target.pathname !== apiPath && !target.pathname.startsWith(`${apiPath}/`))
  ) {
    throw new Error(
      "Os testes desta documentação permitem apenas a API desta instalação.",
    );
  }
  return window.fetch(input, {
    ...init,
    credentials: "omit",
    redirect: "error",
    referrerPolicy: "no-referrer",
  });
}

const configuration = computed<NonNullable<ReferenceProps["configuration"]>>(
  () => ({
    // This object is the server-authorized OpenAPI document, never a remote URL.
    content: props.specification || {},
    baseServerURL: window.location.origin,
    servers: [{ url: props.baseUrl, description: "Esta instalação" }],
    layout: "modern",
    theme: "none",
    localization: { locale: "pt" },
    withDefaultFonts: false,
    persistAuth: false,
    telemetry: false,
    agent: { disabled: true },
    mcp: { disabled: true },
    proxyUrl: "",
    plugins: [],
    showDeveloperTools: "never",
    hideClientButton: true,
    hideTestRequestButton: false,
    hideDarkModeToggle: true,
    forceDarkModeState: "light",
    showSidebar: true,
    documentDownloadType: "json",
    defaultHttpClient: { targetKey: "shell", clientKey: "curl" },
    customFetch: fetchApi,
  }),
);
onErrorCaptured(() => {
  failed.value = true;
  return false;
});
</script>

<template>
  <section
    class="api-reference"
    aria-label="Referência interativa da API"
    @click.stop
  >
    <div v-if="failed || !available" class="notice warning" role="alert">
      Não foi possível carregar a referência da API. Atualize a página ou
      consulte o administrador.
    </div>
    <ScalarApiReference v-else :configuration="configuration" />
  </section>
</template>

<style scoped>
/* Scalar's base layer also targets body; preserve the Manager canvas globally. */
:global(body) {
  background-color: var(--canvas);
}

.api-reference {
  --scalar-font:
    ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI",
    sans-serif;
  --scalar-font-code: var(--font-mono);
  --scalar-color-1: var(--ink);
  --scalar-color-2: var(--muted);
  --scalar-color-3: var(--muted);
  --scalar-color-accent: var(--primary);
  --scalar-background-1: var(--surface);
  --scalar-background-2: var(--soft);
  --scalar-background-3: var(--canvas);
  --scalar-background-accent: var(--primary-soft);
  --scalar-border-color: var(--line);
  --scalar-sidebar-width: 220px;
  --scalar-sidebar-background-1: var(--surface);
  --scalar-sidebar-color-1: var(--ink);
  --scalar-sidebar-color-2: var(--muted);
  --scalar-sidebar-color-active: var(--primary);
  --scalar-sidebar-item-active-background: var(--primary-soft);
  --scalar-sidebar-border-color: var(--line);
  --scalar-radius: 6px;
  --scalar-radius-lg: 8px;
  --scalar-custom-header-height: 0px;
  min-width: 0;
  width: 100%;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--surface);
  isolation: isolate;
  overflow: clip;
}
.api-reference :deep(.references-layout) {
  min-height: 620px;
}
.api-reference :deep(.sidebar) {
  position: sticky;
  top: 0;
  max-height: 100dvh;
}
.api-reference :deep(a:focus-visible),
.api-reference :deep(button:focus-visible),
.api-reference :deep(input:focus-visible) {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}
@media (max-width: 640px) {
  .api-reference {
    --scalar-sidebar-width: 200px;
  }
  .api-reference :deep(.section-container) {
    min-width: 0;
  }
}
</style>
