import { ArrowDownRight, ArrowUpRight } from "lucide-react";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";
import { Skeleton } from "./feedback";

export function PageHeader({ title, subtitle, actions, badge }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode; badge?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div className="min-w-0 max-w-full">
        <div className="flex min-w-0 items-center gap-2">
          <h1 className="min-w-0 text-2xl font-semibold tracking-tight">{title}</h1>
          {badge}
        </div>
        {subtitle && <p className="mt-0.5 text-sm text-muted">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

interface KpiProps {
  label: ReactNode;
  value: ReactNode;
  unit?: ReactNode;
  hint?: ReactNode;
  delta?: number | null;
  deltaGoodWhenUp?: boolean;
  icon?: ReactNode;
  tone?: "default" | "warning" | "danger" | "success";
  loading?: boolean;
  onClick?: () => void;
}

export function KpiCard({ label, value, unit, hint, delta, deltaGoodWhenUp = true, icon, tone = "default", loading, onClick }: KpiProps) {
  const good = delta === null || delta === undefined ? null : delta >= 0 === deltaGoodWhenUp;
  const Comp = onClick ? "button" : "div";
  return (
    <Comp onClick={onClick} className={cn("group flex flex-col gap-1 rounded-lg border border-border bg-surface px-3.5 py-3 text-left shadow-card",
      onClick && "hover:border-border-strong transition-colors")}>
      <div className="flex items-center justify-between gap-2 text-xs text-muted">
        <span className="truncate">{label}</span>
        {icon && <span className={cn("text-subtle", tone === "warning" && "text-warning", tone === "danger" && "text-danger", tone === "success" && "text-success")}>{icon}</span>}
      </div>
      {loading ? <Skeleton className="h-7 w-24" /> : (
        <div className="flex items-baseline gap-1">
          <span className={cn("text-3xl font-semibold tracking-tight tabular", tone === "danger" && "text-danger", tone === "warning" && "text-warning")}>{value}</span>
          {unit && <span className="text-sm text-muted">{unit}</span>}
        </div>
      )}
      <div className="flex min-h-4 items-center gap-1.5 text-2xs text-muted">
        {delta !== null && delta !== undefined && (
          <span className={cn("inline-flex items-center font-medium", good ? "text-success" : "text-danger")}>
            {delta >= 0 ? <ArrowUpRight className="size-3" /> : <ArrowDownRight className="size-3" />}
            {Math.abs(delta)}%
          </span>
        )}
        {hint}
      </div>
    </Comp>
  );
}

export function SectionTitle({ children, actions }: { children: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-2 flex items-center justify-between gap-2">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-subtle">{children}</h3>
      {actions}
    </div>
  );
}

export function DefinitionList({ items, className }: { items: [ReactNode, ReactNode][]; className?: string }) {
  return (
    <dl className={cn("grid grid-cols-[minmax(110px,auto)_1fr] gap-x-4 gap-y-1.5 text-sm", className)}>
      {items.map(([k, v], i) => (
        <div key={i} className="contents">
          <dt className="text-xs text-muted pt-0.5">{k}</dt>
          <dd className="min-w-0 break-words">{v ?? "—"}</dd>
        </div>
      ))}
    </dl>
  );
}
