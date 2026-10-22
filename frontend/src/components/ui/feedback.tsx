import { AlertTriangle, Inbox, Loader2 } from "lucide-react";
import type { ReactNode } from "react";
import { ApiError } from "@/api/client";
import { useI18n } from "@/i18n";
import { cn } from "@/lib/utils";

export function Spinner({ className }: { className?: string }) {
  return <Loader2 className={cn("size-4 animate-spin text-muted", className)} aria-label="Loading" />;
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn("animate-pulse rounded bg-surface-3", className)} />;
}

export function EmptyState({ icon, title, children, className }: { icon?: ReactNode; title: ReactNode; children?: ReactNode; className?: string }) {
  return (
    <div className={cn("flex flex-col items-center justify-center gap-1.5 px-6 py-10 text-center", className)}>
      <div className="mb-1 text-subtle">{icon ?? <Inbox className="size-6" />}</div>
      <p className="text-sm font-medium text-fg">{title}</p>
      {children && <div className="max-w-md text-xs text-muted">{children}</div>}
    </div>
  );
}

export function ErrorState({ error, className }: { error: unknown; className?: string }) {
  const { t } = useI18n();
  const apiError = error instanceof ApiError ? error : null;
  return (
    <div role="alert" className={cn("flex items-start gap-2 rounded-md border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger", className)}>
      <AlertTriangle className="mt-0.5 size-4 shrink-0" />
      <div>
        <p className="font-medium">{apiError?.status === 403 ? t("common.forbidden") : apiError?.message ?? t("common.error")}</p>
        {apiError?.problem.request_id && <p className="text-2xs opacity-75">Request {apiError.problem.request_id}</p>}
      </div>
    </div>
  );
}

export function Notice({ tone = "info", children, icon, className }: { tone?: "info" | "warning" | "danger" | "success" | "violet"; children: ReactNode; icon?: ReactNode; className?: string }) {
  const tones = {
    info: "border-info/25 bg-info-soft text-info",
    warning: "border-warning/30 bg-warning-soft text-warning",
    danger: "border-danger/30 bg-danger-soft text-danger",
    success: "border-success/30 bg-success-soft text-success",
    violet: "border-violet/30 bg-violet-soft text-violet",
  };
  return (
    <div className={cn("flex items-start gap-2 rounded-md border px-3 py-2 text-xs leading-relaxed", tones[tone], className)}>
      {icon && <span className="mt-0.5 shrink-0">{icon}</span>}
      <div className="min-w-0">{children}</div>
    </div>
  );
}

export function Kbd({ children }: { children: ReactNode }) {
  return <kbd className="rounded border border-border bg-surface-2 px-1 font-mono text-2xs text-muted">{children}</kbd>;
}
