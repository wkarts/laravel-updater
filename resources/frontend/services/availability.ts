export interface AvailabilityContext {
  applicationRootPath?: string;
  basePath: string;
}

export interface AvailabilityResult {
  available: boolean;
  destination?: string;
  message: string;
}

/** The public application root is a same-origin path, never a Manager route. */
export function applicationRootUrl(context: AvailabilityContext): string {
  const root = context.applicationRootPath || "/";
  const origin = window.location.origin;
  try {
    if (!root.startsWith("/") || root.startsWith("//") || /[\\\s?#]/.test(root))
      return origin + "/";
    const target = new URL(root, origin);
    if (target.origin !== origin || target.username || target.password)
      return origin + "/";
    return target.href;
  } catch {
    return origin + "/";
  }
}

/** Probe a real public request, including the same maintenance gates as customers. */
export async function probeApplication(
  context: AvailabilityContext,
  timeoutMs = 8000,
): Promise<AvailabilityResult> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(applicationRootUrl(context), {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      redirect: "follow",
      headers: { Accept: "text/html" },
      signal: controller.signal,
    });
    if (response.status === 503)
      return { available: false, message: "A aplicação continua em manutenção. Tente novamente em alguns instantes." };
    if (!response.ok)
      return { available: false, message: "A aplicação ainda não está disponível. Tente novamente em alguns instantes." };

    const destination = new URL(response.url || applicationRootUrl(context));
    const manager = context.basePath.replace(/\/$/, "");
    if (destination.origin !== window.location.origin || destination.username || destination.password
      || destination.pathname === manager || destination.pathname.startsWith(manager + "/"))
      return { available: false, message: "Não foi possível confirmar a disponibilidade da aplicação. Tente novamente." };

    return { available: true, destination: destination.href, message: "Aplicação disponível. Redirecionando…" };
  } catch {
    return { available: false, message: controller.signal.aborted
      ? "A verificação demorou mais que o esperado. Tente novamente."
      : "Não foi possível verificar a disponibilidade. Confira sua conexão e tente novamente." };
  } finally {
    window.clearTimeout(timeout);
  }
}
