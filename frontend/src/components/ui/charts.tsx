import { Table2, BarChart3 } from "lucide-react";
import { useState, type ReactNode } from "react";
import { useI18n } from "@/i18n";
import { Button } from "./button";
import { Card, CardHeader } from "./card";
import { EmptyState, Skeleton } from "./feedback";

/** Categorical series colours in fixed order (never cycled; see index.css). */
export const SERIES = ["var(--series-1)", "var(--series-2)", "var(--series-3)"] as const;

export const axisProps = {
  tick: { fontSize: 11, fill: "var(--text-subtle)" },
  tickLine: false,
  axisLine: false,
} as const;

export interface SeriesSpec {
  key: string;
  label: string;
  color: string;
}

export function Legend({ series }: { series: SeriesSpec[] }) {
  if (series.length < 2) return null;
  return (
    <ul className="flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-muted">
      {series.map((s) => (
        <li key={s.key} className="inline-flex items-center gap-1.5">
          <span className="size-2 rounded-sm" style={{ background: s.color }} aria-hidden />
          {s.label}
        </li>
      ))}
    </ul>
  );
}

interface TooltipPayload {
  name?: string | number;
  value?: number | string;
  color?: string;
  dataKey?: string | number;
}

/** Recharts tooltip content: label plus one row per series, values in text ink. */
export function ChartTooltip({ active, payload, label, format, labelFormat, series }: {
  active?: boolean;
  payload?: TooltipPayload[];
  label?: string | number;
  format?: (v: number) => string;
  labelFormat?: (l: string) => string;
  series?: SeriesSpec[];
}) {
  if (!active || !payload?.length) return null;
  const names = new Map(series?.map((s) => [s.key, s.label]));
  return (
    <div className="rounded-md border border-border bg-surface px-2.5 py-1.5 text-xs shadow-lg">
      {label !== undefined && <p className="mb-1 font-medium">{labelFormat ? labelFormat(String(label)) : label}</p>}
      {payload.map((p) => (
        <p key={String(p.dataKey)} className="flex items-center gap-2">
          <span className="size-2 rounded-sm" style={{ background: p.color }} aria-hidden />
          <span className="text-muted">{names.get(String(p.dataKey)) ?? p.name}</span>
          <span className="ml-auto pl-3 font-medium tabular">{format ? format(Number(p.value)) : p.value}</span>
        </p>
      ))}
    </div>
  );
}

interface ChartCardProps {
  title: ReactNode;
  subtitle?: ReactNode;
  series?: SeriesSpec[];
  loading?: boolean;
  empty?: boolean;
  emptyText?: ReactNode;
  height?: number;
  table: ReactNode;
  children: ReactNode;
  actions?: ReactNode;
  className?: string;
}

/** A chart with a legend and an accessible table view of the same data. */
export function ChartCard({ title, subtitle, series = [], loading, empty, emptyText, height = 220, table, children, actions, className }: ChartCardProps) {
  const { t } = useI18n();
  const [asTable, setAsTable] = useState(false);
  return (
    <Card className={className}>
      <CardHeader title={title} subtitle={subtitle} actions={<>
        {actions}
        <Button variant="ghost" size="icon" onClick={() => setAsTable((v) => !v)} aria-pressed={asTable}
          aria-label={asTable ? t("common.showChart") : t("common.showTable")} title={asTable ? t("common.showChart") : t("common.showTable")}>
          {asTable ? <BarChart3 className="size-3.5" /> : <Table2 className="size-3.5" />}
        </Button>
      </>} />
      <div className="px-4 pt-2 pb-3">
        {!asTable && <div className="mb-1.5"><Legend series={series} /></div>}
        {loading ? <div style={{ height }}><Skeleton className="h-full w-full" /></div> : empty ? <EmptyState title={emptyText ?? t("common.noData")} /> : asTable ? (
          <div className="max-h-[320px] overflow-auto scrollbar-thin">{table}</div>
        ) : (
          <div style={{ height }}>{children}</div>
        )}
      </div>
    </Card>
  );
}

/** Small table used as the table view of charts. */
export function MiniTable({ head, rows }: { head: ReactNode[]; rows: ReactNode[][] }) {
  return (
    <table className="w-full text-xs">
      <thead className="sticky top-0 bg-surface">
        <tr className="border-b border-border text-left text-muted">
          {head.map((h, i) => <th key={i} scope="col" className={`py-1.5 pr-3 font-medium ${i > 0 ? "text-right" : ""}`}>{h}</th>)}
        </tr>
      </thead>
      <tbody>
        {rows.map((r, i) => (
          <tr key={i} className="border-b border-border last:border-0">
            {r.map((c, j) => <td key={j} className={`py-1.5 pr-3 ${j > 0 ? "text-right tabular" : ""}`}>{c}</td>)}
          </tr>
        ))}
      </tbody>
    </table>
  );
}
