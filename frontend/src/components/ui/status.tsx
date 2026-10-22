import type { FieldState } from "@/api/types";
import { useI18n, type MessageKey } from "@/i18n";
import { Badge, type Tone } from "./badge";

const STATUS_TONES: Record<string, Tone> = {
  queued: "neutral", processing: "info", awaiting_review: "warning", committing: "info", completed: "success", failed: "danger",
  cancelled: "neutral", draft: "neutral", needs_review: "warning", approved: "info", rejected: "neutral", committed: "success",
  planned: "neutral", released: "info", in_progress: "info", submitted: "warning", ordered: "violet", closed: "success",
  open: "neutral", done: "success", running: "info", succeeded: "success", pending: "neutral", parsed: "info", interpreted: "success",
  ready: "success", needs_correction: "danger", needs_approval: "warning",
};

export function StatusBadge({ status, className }: { status: string | null | undefined; className?: string }) {
  const { t } = useI18n();
  if (!status) return null;
  const key = (["ready", "needs_correction", "needs_approval"].includes(status) ? `group.${status}` : `status.${status}`) as MessageKey;
  return <Badge tone={STATUS_TONES[status] ?? "neutral"} dot className={className}>{t(key)}</Badge>;
}

const STATE_TONES: Record<FieldState, Tone> = {
  high_confidence: "success", low_confidence: "warning", missing: "neutral", conflicting: "danger", invalid: "danger", needs_approval: "info",
};

export function FieldStateBadge({ state }: { state: FieldState }) {
  const { t } = useI18n();
  return <Badge tone={STATE_TONES[state]}>{t(`state.${state}` as MessageKey)}</Badge>;
}

export function ConfidenceBar({ value }: { value: number | null }) {
  if (value === null || value === undefined) return <span className="text-subtle">—</span>;
  const pct = Math.round(value * 100);
  const color = pct >= 90 ? "bg-success" : pct >= 75 ? "bg-warning" : "bg-danger";
  return (
    <span className="inline-flex items-center gap-1.5" title={`${pct}%`}>
      <span className="h-1.5 w-10 overflow-hidden rounded-full bg-surface-3"><span className={`block h-full ${color}`} style={{ width: `${pct}%` }} /></span>
      <span className="tabular text-2xs text-muted">{pct}%</span>
    </span>
  );
}

export function PriorityBadge({ priority }: { priority: string }) {
  const { t } = useI18n();
  const tone: Tone = priority === "urgent" ? "danger" : priority === "high" ? "warning" : priority === "low" ? "neutral" : "info";
  return <Badge tone={tone}>{t(`priority.${priority}` as MessageKey)}</Badge>;
}
