import { state } from "./app";

/** Keep wire values intact; human labels are presentation only. */
export function statusLabel(value: unknown): string {
  const labels: Record<string, string> = {
    success: "Concluída",
    completed: "Concluída",
    finished: "Concluída",
    delivered: "Entregue",
    failed: "Falhou",
    error: "Erro",
    running: "Em execução",
    queued: "Na fila",
    pending: "Pendente",
    dry_run: "Simulação",
    cancelled: "Cancelada",
    canceled: "Cancelada",
    active: "Ativo",
    inactive: "Inativo",
    received: "Recebido",
    duplicate: "Duplicado",
    rejected: "Recusado",
    unknown: "Não informado",
    skipped: "Ignorada",
    retrying: "Nova tentativa",
    retry: "Nova tentativa",
    processing: "Processando",
    accepted: "Aceito",
  };
  const raw = String(value ?? "");
  return labels[raw.toLowerCase()] ?? (raw || "—");
}
export function dateTime(value: unknown): string {
  if (!value) return "—";
  const raw = String(value);
  const parsed = new Date(raw);
  if (Number.isNaN(parsed.getTime())) return raw;
  try {
    return new Intl.DateTimeFormat("pt-BR", {
      dateStyle: "short",
      timeStyle: "short",
      timeZone:
        state.context.timezone ||
        Intl.DateTimeFormat().resolvedOptions().timeZone,
    }).format(parsed);
  } catch {
    return raw;
  }
}
export function modeLabel(value: unknown): string {
  const labels: Record<string, string> = {
    inplace: "No servidor",
    atomic: "Troca de versão",
    artifact: "Por artefato",
    merge: "Git merge",
    "ff-only": "Avanço direto",
    tag: "Tag exata",
  };
  return labels[String(value)] ?? String(value || "Não informado");
}
