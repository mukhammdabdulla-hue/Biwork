import { useQuery } from "@tanstack/react-query";
import {
  AlertTriangle, Bot, CheckCircle2, ClipboardList, Factory, Gauge, Info, PackageX, RefreshCw, ShieldAlert, Timer, XCircle,
} from "lucide-react";
import { Fragment, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { api } from "@/api/client";
import { useCompanyKey } from "@/api/hooks";
import type { Overview } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { axisProps, ChartCard, ChartTooltip, MiniTable, SERIES, type SeriesSpec } from "@/components/ui/charts";
import { EmptyState, ErrorState } from "@/components/ui/feedback";
import { KpiCard, PageHeader } from "@/components/ui/page";
import { StatusBadge } from "@/components/ui/status";
import { hasMessage, useI18n, type TFunction } from "@/i18n";
import { auditActionLabel } from "@/i18n/labels";
import { cn, toNumber } from "@/lib/utils";

const PERIODS = [7, 30, 90] as const;

type Alert = Overview["alerts"][number];

/** Alert text in the user's language from its kind and parameters; the API's English text when they are missing. */
function alertText(alert: Alert, t: TFunction, fmtNumber: (v: unknown) => string, fmtDate: (v: string) => string) {
  const p = alert.params;
  const titleKey = `alert.${alert.kind}.title`;
  const detailKey = `alert.${alert.kind}.detail`;
  if (!p || !hasMessage(titleKey) || !hasMessage(detailKey)) return { title: alert.title, detail: alert.detail };
  const num = (v: unknown) => fmtNumber(v);
  const date = (v: unknown) => (typeof v === "string" && v ? fmtDate(v) : "—");
  const vars: Record<string, string> = Object.fromEntries(Object.entries(p).map(([k, v]) => [k, v == null ? "" : String(v)]));
  for (const k of ["on_hand", "reorder_level", "completion_pct", "rate"]) if (k in p) vars[k] = num(p[k] ?? 0);
  for (const k of ["planned_end", "due_date"]) if (k in p) vars[k] = date(p[k]);
  if (alert.kind === "job_failed" && !vars.title) vars.title = `#${String(p.job_id ?? "").slice(0, 8)}`;
  return { title: t(titleKey, vars), detail: t(detailKey, vars) };
}

export default function OverviewPage() {
  const { t, fmtNumber, fmtDate, fmtRelative } = useI18n();
  const { can } = useAuth();
  const navigate = useNavigate();
  const company = useCompanyKey();
  const [days, setDays] = useState<number>(30);
  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: [company, "overview", days],
    queryFn: () => api.get<Overview>("/analytics/overview", { days }),
    refetchInterval: 120_000,
  });
  const k = data?.kpis;
  const unit = data?.unit ?? undefined;
  const shortDate = (d: string) => fmtDate(d, { day: "2-digit", month: "short" });

  const trendSeries: SeriesSpec[] = [
    { key: "accepted", label: t("overview.accepted"), color: SERIES[0] },
    { key: "defective", label: t("overview.defective"), color: SERIES[1] },
  ];
  const trend = (data?.charts.production_trend ?? []).map((d) => ({ ...d, accepted: d.produced - d.defective }));

  return (
    <div className="space-y-4">
      <PageHeader
        title={t("overview.title")}
        subtitle={t("overview.subtitle", { days })}
        badge={data?.is_sample_data && <Badge tone="violet">{t("common.sampleData")}</Badge>}
        actions={<>
          <div role="group" aria-label={t("common.period")} className="inline-flex rounded-md border border-border bg-surface p-0.5 shadow-card">
            {PERIODS.map((p) => (
              <button key={p} type="button" onClick={() => setDays(p)} aria-pressed={days === p}
                className={cn("h-6 rounded px-2 text-xs", days === p ? "bg-primary-soft font-medium text-primary" : "text-muted hover:text-fg")}>
                {t("common.days", { n: p })}
              </button>
            ))}
          </div>
          <Button size="sm" onClick={() => refetch()} loading={isFetching && !isLoading} aria-label={t("common.refresh")}>
            <RefreshCw className="size-3.5" />{t("common.refresh")}
          </Button>
        </>}
      />
      {error && <ErrorState error={error} />}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard loading={isLoading} label={t("overview.kpi.totalProduction")} icon={<Factory className="size-4" />}
          value={fmtNumber(k?.total_production.value)} unit={unit} delta={k?.total_production.delta_pct}
          hint={k?.total_production.delta_pct != null ? t("overview.vsPrevious") : unit ? undefined : t("overview.noOutput")}
          onClick={() => navigate("/production")} />
        <KpiCard loading={isLoading} label={t("overview.kpi.planCompletion")} icon={<Gauge className="size-4" />}
          value={k?.plan_completion.value == null ? "—" : `${fmtNumber(k.plan_completion.value, 1)}%`}
          hint={t("overview.ordersInPlan", { n: k?.plan_completion.orders ?? 0 })} onClick={() => navigate("/production?tab=plan")} />
        <KpiCard loading={isLoading} label={t("overview.kpi.activeOrders")} icon={<ClipboardList className="size-4" />}
          value={fmtNumber(k?.active_orders.value)} hint={t("overview.plannedOrders", { n: k?.active_orders.planned ?? 0 })}
          onClick={() => navigate("/production?tab=orders")} />
        <KpiCard loading={isLoading} label={t("overview.kpi.shortages")} icon={<PackageX className="size-4" />}
          value={fmtNumber(k?.inventory_shortages.value)} tone={k?.inventory_shortages.value ? "warning" : "default"}
          hint={t("overview.belowReorder")} onClick={() => navigate("/inventory?tab=shortages")} />
        <KpiCard loading={isLoading} label={t("overview.kpi.overdueTasks")} icon={<Timer className="size-4" />}
          value={fmtNumber(k?.overdue_tasks.value)} tone={k?.overdue_tasks.value ? "danger" : "default"}
          onClick={() => navigate("/tasks?overdue=1")} />
        {/* Each part of the count opens the list that holds those items. */}
        <KpiCard loading={isLoading} label={t("overview.kpi.pendingApprovals")} icon={<CheckCircle2 className="size-4" />}
          value={fmtNumber(k?.pending_approvals.value)}
          hint={k && ([
            ["/agent?status=awaiting_review", "overview.pending.agent", k.pending_approvals.agent_records],
            ["/supply?status=submitted", "overview.pending.purchase", k.pending_approvals.purchase_requests],
            ["/production?status=draft", "overview.pending.drafts", k.pending_approvals.draft_reports],
          ] as const).map(([to, key, n], i) => (
            <Fragment key={to}>{i > 0 && "·"}<Link to={to} className="hover:text-primary hover:underline">{t(key, { n: fmtNumber(n) })}</Link></Fragment>
          ))} />
        <KpiCard loading={isLoading} label={t("overview.kpi.ingestionSuccess")} icon={<Bot className="size-4" />}
          value={k?.ingestion_success_rate.value == null ? "—" : `${fmtNumber(k.ingestion_success_rate.value, 1)}%`}
          hint={t("overview.jobsCount", { n: k?.ingestion_success_rate.jobs ?? 0 })} onClick={() => navigate("/analytics?tab=agent")} />
        <KpiCard loading={isLoading} label={t("overview.kpi.failedJobs")} icon={<XCircle className="size-4" />}
          value={fmtNumber(k?.failed_jobs.value)} tone={k?.failed_jobs.currently_failed ? "danger" : "default"}
          hint={t("overview.currentlyFailed", { n: k?.failed_jobs.currently_failed ?? 0 })} onClick={() => navigate("/agent?status=failed")} />
      </div>

      <div className="grid gap-3 xl:grid-cols-3">
        <ChartCard className="xl:col-span-2" title={t("overview.trend")} subtitle={unit && t("overview.unitNote", { unit })} series={trendSeries}
          loading={isLoading} empty={trend.length === 0} height={240}
          table={<MiniTable head={[t("common.date"), t("overview.produced"), t("overview.accepted"), t("overview.defective")]}
            rows={trend.map((d) => [fmtDate(d.date), fmtNumber(d.produced), fmtNumber(d.accepted), fmtNumber(d.defective)])} />}>
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={trend} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barCategoryGap="20%">
              <CartesianGrid vertical={false} stroke="var(--grid)" />
              <XAxis dataKey="date" {...axisProps} tickFormatter={shortDate} minTickGap={24} />
              <YAxis {...axisProps} width="auto" tickFormatter={(v: number) => fmtNumber(v)} />
              <Tooltip cursor={{ fill: "var(--surface-3)", opacity: 0.6 }}
                content={<ChartTooltip series={trendSeries} format={(v) => fmtNumber(v)} labelFormat={(l) => fmtDate(l)} />} />
              <Bar maxBarSize={48} dataKey="accepted" stackId="q" fill={SERIES[0]} stroke="var(--surface)" strokeWidth={1} isAnimationActive={false} />
              <Bar maxBarSize={48} dataKey="defective" stackId="q" fill={SERIES[1]} stroke="var(--surface)" strokeWidth={1} radius={[3, 3, 0, 0]} isAnimationActive={false} />
            </BarChart>
          </ResponsiveContainer>
        </ChartCard>

        <Card>
          <CardHeader title={t("overview.alerts")} actions={data && <Badge>{data.alerts.length}</Badge>} />
          <ul className="max-h-[300px] divide-y divide-border overflow-y-auto scrollbar-thin">
            {data && data.alerts.length === 0 && <li><EmptyState icon={<CheckCircle2 className="size-5 text-success" />} title={t("overview.noAlerts")} /></li>}
            {data?.alerts.map((a, i) => {
              const text = alertText(a, t, fmtNumber, fmtDate);
              return (
              <li key={i}>
                <Link to={a.link} className="flex gap-2.5 px-4 py-2.5 hover:bg-surface-2">
                  {a.severity === "danger" ? <ShieldAlert className="mt-0.5 size-4 shrink-0 text-danger" aria-label={t("agent.severity.error")} />
                    : a.severity === "warning" ? <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-label={t("agent.severity.warning")} />
                      : <Info className="mt-0.5 size-4 shrink-0 text-info" aria-label={t("agent.severity.info")} />}
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-medium">{text.title}</span>
                    <span className="block truncate text-xs text-muted">{text.detail}</span>
                  </span>
                </Link>
              </li>
              );
            })}
          </ul>
        </Card>
      </div>

      <div className="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
        <HorizontalBars title={t("overview.byProduct")} loading={isLoading}
          rows={(data?.charts.by_product ?? []).map((p) => ({ label: `${p.code} · ${p.name}`, value: p.produced, unit: p.unit }))} />
        <HorizontalBars title={t("overview.byDepartment")} loading={isLoading} unit={unit}
          rows={(data?.charts.by_department ?? []).map((d) => ({ label: d.name, value: d.produced, unit: unit ?? "" }))} />
        <Card className="lg:col-span-2 xl:col-span-1">
          <CardHeader title={t("overview.activity")} />
          {!can("audit.read") ? <EmptyState title={t("overview.activityHidden")} /> : (
            <ul className="max-h-[300px] divide-y divide-border overflow-y-auto scrollbar-thin">
              {data && data.recent_activity.length === 0 && <li><EmptyState title={t("overview.noActivity")} /></li>}
              {data?.recent_activity.map((a, i) => (
                <li key={i} className="px-4 py-2">
                  <p className="truncate text-sm" title={a.summary ?? undefined}>{auditActionLabel(t, a.action, a.summary)}</p>
                  <p className="text-2xs text-muted">{a.actor} · {fmtRelative(a.at)}</p>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Card>
        <CardHeader title={t("overview.planVsActual")} actions={<Button size="xs" variant="ghost" onClick={() => navigate("/production?tab=plan")}>{t("common.open")}</Button>} />
        <PlanTable rows={data?.charts.plan_vs_actual ?? []} loading={isLoading} />
      </Card>
    </div>
  );
}

function HorizontalBars({ title, rows, loading, unit }: { title: string; rows: { label: string; value: number; unit: string }[]; loading?: boolean; unit?: string }) {
  const { t, fmtNumber } = useI18n();
  const top = rows.slice(0, 8);
  return (
    <ChartCard title={title} subtitle={unit && t("overview.unitNote", { unit })} loading={loading} empty={top.length === 0} height={Math.max(120, top.length * 30 + 16)}
      table={<MiniTable head={[t("common.name"), t("overview.produced")]} rows={rows.map((r) => [r.label, `${fmtNumber(r.value)} ${r.unit}`])} />}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={top} layout="vertical" margin={{ top: 0, right: 48, bottom: 0, left: 0 }} barCategoryGap={6}>
          <XAxis type="number" hide />
          <YAxis type="category" dataKey="label" {...axisProps} width={130} tick={{ fontSize: 11, fill: "var(--text-muted)" }} />
          <Tooltip cursor={{ fill: "var(--surface-3)", opacity: 0.6 }}
            content={<ChartTooltip series={[{ key: "value", label: t("overview.produced"), color: SERIES[0] }]} format={(v) => fmtNumber(v)} />} />
          <Bar maxBarSize={48} dataKey="value" fill={SERIES[0]} radius={[0, 3, 3, 0]} isAnimationActive={false}
            label={{ position: "right", fontSize: 11, fill: "var(--text-muted)", formatter: (v: unknown) => fmtNumber(v) }} />
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}

export function PlanTable({ rows, loading }: { rows: Overview["charts"]["plan_vs_actual"]; loading?: boolean }) {
  const { t, fmtNumber, fmtDate } = useI18n();
  if (!loading && rows.length === 0) return <EmptyState title={t("common.noData")} />;
  return (
    <div className="overflow-x-auto scrollbar-thin">
      <table className="w-full text-xs">
        <thead className="bg-surface-2 text-left text-muted">
          <tr className="border-b border-border">
            <th scope="col" className="px-4 py-1.5 font-medium">{t("production.order")}</th>
            <th scope="col" className="px-3 py-1.5 font-medium">{t("common.product")}</th>
            <th scope="col" className="px-3 py-1.5 font-medium">{t("common.department")}</th>
            <th scope="col" className="px-3 py-1.5 font-medium">{t("common.status")}</th>
            <th scope="col" className="px-3 py-1.5 font-medium">{t("production.plannedEnd")}</th>
            <th scope="col" className="px-3 py-1.5 text-right font-medium">{t("production.plannedQty")}</th>
            <th scope="col" className="px-3 py-1.5 text-right font-medium">{t("production.actualQty")}</th>
            <th scope="col" className="w-44 px-4 py-1.5 font-medium">{t("production.completion")}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => {
            const pct = r.completion_pct ?? 0;
            const late = r.planned_end && r.planned_end < new Date().toISOString().slice(0, 10) && pct < 100 && r.status !== "completed";
            return (
              <tr key={r.order_id} className="border-b border-border last:border-0">
                <td className="px-4 py-1.5 font-medium">{r.number}</td>
                <td className="px-3 py-1.5">{r.product}</td>
                <td className="px-3 py-1.5 text-muted">{r.department ?? "—"}</td>
                <td className="px-3 py-1.5"><StatusBadge status={r.status} /></td>
                <td className={cn("px-3 py-1.5", late && "font-medium text-danger")}>{fmtDate(r.planned_end)}</td>
                <td className="px-3 py-1.5 text-right tabular">{fmtNumber(r.planned_qty)} {r.unit}</td>
                <td className="px-3 py-1.5 text-right tabular">{fmtNumber(toNumber(r.actual_qty))}</td>
                <td className="px-4 py-1.5">
                  <div className="flex items-center gap-2">
                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-3">
                      <div className={cn("h-full rounded-full", pct >= 100 ? "bg-success" : late ? "bg-danger" : "bg-primary")} style={{ width: `${Math.min(pct, 100)}%` }} />
                    </div>
                    <span className="w-10 text-right tabular text-muted">{r.completion_pct == null ? "—" : `${fmtNumber(pct, 0)}%`}</span>
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
