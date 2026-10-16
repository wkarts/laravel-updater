import { reactive } from "vue";
import type { Router } from "vue-router";

export interface PageEnvelope {
  page: string;
  data: Record<string, any>;
  context: {
    basePath: string;
    csrfToken: string;
    routes: Record<string, string>;
    user?: Record<string, any> | null;
    permissions?: string[] | Record<string, boolean>;
    flash?: Record<string, any>;
    branding?: Record<string, any>;
    [key: string]: any;
  };
}

declare global {
  interface Window {
    __UPDATER_SPA__?: PageEnvelope;
  }
}
const script = document.getElementById("updater-bootstrap");
const initial =
  window.__UPDATER_SPA__ ??
  (script ? JSON.parse(script.textContent || "{}") : null);
export const state = reactive<
  PageEnvelope & {
    loading: boolean;
    error: string;
    statusCode: number;
    result: any;
    acceptedNavigation: string;
  }
>({
  page: initial?.page ?? "error",
  data: initial?.data ?? {},
  context: initial?.context ?? {
    basePath: "/_updater",
    csrfToken: "",
    routes: {},
  },
  loading: false,
  error: initial
    ? ""
    : "Não foi possível carregar a interface. Recarregue a página.",
  statusCode: 200,
  result: null,
  acceptedNavigation: "",
});
let router: Router | undefined;
let currentRequest: AbortController | undefined;
export function bindRouter(value: Router) {
  router = value;
}
export function route(
  name: string,
  params: Record<string, any> | string | number = {},
): string {
  let url = state.context.routes[name] ?? "";
  if (!url) return "";
  if (typeof params !== "object") {
    const key = /\{([^}]+)\}/.exec(url)?.[1];
    params = key ? { [key]: params } : {};
  }
  const remaining: Record<string, any> = {};
  Object.entries(params).forEach(([key, value]) => {
    const marker = `{${key}}`;
    if (url.includes(marker))
      url = url.replaceAll(marker, encodeURIComponent(String(value)));
    else if (value !== undefined && value !== null && value !== "")
      remaining[key] = value;
  });
  if (Object.keys(remaining).length)
    url += `${url.includes("?") ? "&" : "?"}${new URLSearchParams(remaining).toString()}`;
  return url;
}
export function can(permission: string): boolean {
  const user = state.context.user;
  if (!user)
    return (
      state.context.authEnabled === false ||
      state.context.auth_enabled === false
    );
  if (user.is_master) return true;
  const permissions = state.context.permissions ?? user.permissions ?? [];
  if (Array.isArray(permissions))
    return (
      permissions.includes("*") ||
      permissions.includes(permission) ||
      (permissions.length === 0 && !!user.is_admin)
    );
  return !!permissions[permission];
}
export function localUrl(url: string): string {
  const parsed = new URL(url || state.context.basePath, window.location.origin);
  const base = state.context.basePath.replace(/\/$/, "");
  return `${parsed.pathname.startsWith(base) ? parsed.pathname.slice(base.length) || "/" : parsed.pathname}${parsed.search}${parsed.hash}`;
}
export function acceptPage(payload: PageEnvelope): void {
  state.page = payload.page;
  state.data = payload.data || {};
  state.context = payload.context || state.context;
  state.error = "";
}
function followHostRedirect(response: Response): boolean {
  if (!response.redirected) return false;
  const target = new URL(response.url);
  const base = state.context.basePath.replace(/\/$/, "");
  if (
    target.origin === window.location.origin &&
    target.pathname !== base &&
    !target.pathname.startsWith(base + "/")
  ) {
    window.location.assign(target.href);
    return true;
  }
  return false;
}
export async function loadPage(url: string): Promise<void> {
  currentRequest?.abort();
  currentRequest = new AbortController();
  state.loading = true;
  state.error = "";
  state.result = null;
  try {
    const response = await fetch(url, {
      credentials: "same-origin",
      headers: { "X-Updater-SPA": "1", Accept: "application/json" },
      signal: currentRequest.signal,
    });
    if (followHostRedirect(response)) return;
    const payload = await response.json().catch(() => null);
    state.statusCode = response.status;
    if (payload?.page) {
      acceptPage(payload);
      if (
        response.redirected &&
        router &&
        router.currentRoute.value.fullPath !== localUrl(response.url)
      ) {
        state.acceptedNavigation = localUrl(response.url);
        await router.replace(state.acceptedNavigation);
      }
    } else if (!response.ok) {
      state.error =
        response.status === 403
          ? "Você não tem permissão para acessar esta área."
          : response.status === 401
            ? "Sua sessão expirou. Entre novamente."
            : response.status === 404
              ? "Esta página não foi encontrada."
              : "A aplicação está indisponível. Tente novamente.";
    } else {
      state.error = "Resposta de página inválida. Recarregue a página.";
    }
  } catch (error) {
    if ((error as Error).name !== "AbortError")
      state.error =
        "Não foi possível conectar. Verifique sua conexão e tente novamente.";
  } finally {
    state.loading = false;
  }
}
export async function jsonGet(url: string): Promise<any> {
  const response = await fetch(url, {
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
  });
  if (!response.ok)
    throw new Error(
      response.status === 403
        ? "Sem permissão para consultar."
        : "Não foi possível consultar o andamento.",
    );
  return response.json();
}
export async function mutation(
  url: string,
  body: FormData,
  method = "POST",
): Promise<{ errors?: Record<string, string[]>; result?: any }> {
  state.error = "";
  if (!url)
    return {
      errors: { action: ["Esta ação não está disponível nesta instalação."] },
    };
  body.set("_token", state.context.csrfToken);
  if (method.toUpperCase() !== "POST")
    body.set("_method", method.toUpperCase());
  const response = await fetch(url, {
    method: "POST",
    body,
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      "X-Updater-SPA": "1",
      "X-CSRF-TOKEN": state.context.csrfToken,
      "X-Requested-With": "XMLHttpRequest",
    },
  });
  if (followHostRedirect(response)) return {};
  const payload = await response.json().catch(() => null);
  if (!response.ok)
    return {
      errors: payload?.errors ?? {
        action: [
          response.status === 419
            ? "Sua sessão expirou. Recarregue a página antes de continuar."
            : response.status === 403
              ? "Você não tem permissão para realizar esta ação."
              : (payload?.message ??
                "A operação não foi concluída. Tente novamente."),
        ],
      },
    };
  if (payload?.page) {
    acceptPage(payload);
    const flashErrors = payload.context?.flash?.errors;
    if (flashErrors && Object.keys(flashErrors).length)
      return { errors: flashErrors };
    if (
      router &&
      response.redirected &&
      localUrl(response.url) !== router.currentRoute.value.fullPath
    ) {
      state.acceptedNavigation = localUrl(response.url);
      await router.push(state.acceptedNavigation);
    }
  } else if (payload === null) {
    return {
      errors: {
        action: [
          "O servidor retornou uma resposta inválida. Recarregue a página para verificar o resultado antes de repetir.",
        ],
      },
    };
  } else {
    state.result = payload;
    if (
      payload?.secret &&
      payload?.endpoint &&
      Array.isArray(state.data.endpoints)
    ) {
      state.data.endpoints = [
        ...state.data.endpoints.filter(
          (endpoint: any) => endpoint.id !== payload.endpoint.id,
        ),
        payload.endpoint,
      ];
    }
    if (!payload?.secret && !payload?.token)
      await loadPage(window.location.href);
  }
  return { result: payload };
}
