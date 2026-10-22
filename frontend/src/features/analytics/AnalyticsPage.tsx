import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { api } from "@/api/client";
import { useCompanyKey } from "@/api/hooks";
import type { AgentStats, Overview } from "@/api/types";
import { Card, CardHeader } from "@/components/ui/card";
import { axisProps, ChartCard, ChartTooltip, MiniTable, SERIES, type SeriesSpec } from "@/components/ui/charts";
import { EmptyState, ErrorState } from "@/components/ui/feedback";
import { Select } from "@/components/ui/inputs";
import { KpiCard, PageHeader } from "@/components/ui/page";
import { StatusBadge } from "@/components/ui/status";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { PlanTable } from "@/features/overview/OverviewPage";
import { useI18n } from "@/i18n";
import { codeLabel, fieldLabel, recordTypeLabel, stageLabel } from "@/i18n/labels";

function CountList({ data, kind }: { data: Record<string, number>; kind: "status" | "decision" }) {
  const total = Object.values(data).reduce((a, b) => a + b, 0);
  const { t, fmtNumber } = useI18n();
  if (!total) return <EmptyState title="—" />;
  return (
    <ul className="space-y-1.5">
      {Object.entries(data).sort((a, b) => b[1] - a[1]).map(([k, v]) => (
        <li key={k} className="flex items-center gap-2 text-xs">
          <span className="w-36 shrink-0">{kind === "status" ? <StatusBadge status={k} /> : codeLabel(t, "agent.decision", k)}</span>
          <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-3"><span className="block h-full rounded-full bg-[var(--series-1)]" style={{ width: `${(v / total) * 100}%` }} /></span>
          <span className="w-10 text-right tabular">{fmtNumber(v)}</span>
        </li>
      ))}
    </ul>
  );
}

function AgentTab({ days }: { days: number }) {
  const { t, fmtNumber, fmtDate } = useI18n();
  const company = useCompanyKey();
  const { data, isLoading, error } = useQuery({ queryKey: [company, "analytics", "agent", days], queryFn: () => api.get<AgentStats>("/analytics/agent/stats", { days }) });
  const series: SeriesSpec[] = [
    { key: "completed", label: t("status.completed"), color: SERIES[0] },
    { key: "in_review", label: t("status.awaiting_review"), color: SERIES[2] },
    { key: "failed", label: t("status.failed"), color: SERIES[1] },
  ];
  const decisions = data?.decisions ?? {};
  const corrections = decisions.correct ?? 0;
  if (error) return <ErrorState error={error} />;
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard loading={isLoading} label={t("agent.stats.jobs")} value={fmtNumber(data?.jobs_total)} />
        <KpiCard loading={isLoading} label={t("agent.stats.success")} value={data?.success_rate == null ? "—" : `${fmtNumber(data.success_rate, 1)}%`} />
        <KpiCard loading={isLoading} label={t("analytics.corrections")} value={fmtNumber(corrections)} />
        <KpiCard loading={isLoading} label={t("analytics.cost")} value={fmtNumber((data?.ai_usage ?? []).reduce((a, u) => a + u.cost_usd, 0), 2)} />
      </div>
      <ChartCard title={t("analytics.jobsByDay")} series={series} loading={isLoading} empty={!data?.jobs_by_day.length}
        table={<MiniTable head={[t("common.date"), ...series.map((s) => s.label)]} rows={(data?.jobs_by_day ?? []).map((d) => [fmtDate(d.date), d.completed ?? 0, d.in_review ?? 0, d.failed ?? 0])} />}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data?.jobs_by_day ?? []} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barCategoryGap="20%">
            <CartesianGrid vertical={false} stroke="var(--grid)" />
            <XAxis dataKey="date" {...axisProps} tickFormatter={(d: string) => fmtDate(d, { day: "2-digit", month: "short" })} minTickGap={24} />
            <YAxis {...axisProps} allowDecimals={false} width="auto" tickFormatter={(v: number) => fmtNumber(v)} />
            <Tooltip cursor={{ fill: "var(--surface-3)", opacity: 0.6 }} content={<ChartTooltip series={series} labelFormat={(l) => fmtDate(l)} />} />
            {series.map((s, i) => <Bar key={s.key} maxBarSize={48} dataKey={s.key} stackId="j" fill={s.color} stroke="var(--surface)" strokeWidth={1} radius={i === series.length - 1 ? [3, 3, 0, 0] : undefined} isAnimationActive={false} />)}
          </BarChart>
        </ResponsiveContainer>
      </ChartCard>
      <div className="grid gap-3 lg:grid-cols-3">
        <Card><CardHeader title={t("analytics.recordsByStatus")} /><div className="p-4"><CountList data={data?.records_by_status ?? {}} kind="status" /></div></Card>
        <Card><CardHeader title={t("analytics.decisions")} /><div className="p-4"><CountList data={decisions} kind="decision" /></div></Card>
        <Card>
          <CardHeader title={t("analytics.stageTimes")} />
          <div className="overflow-x-auto p-4 pt-2 scrollbar-thin">
            <MiniTable head={[t("analytics.stage"), t("analytics.avg"), t("status.failed")]}
              rows={(data?.stages ?? []).map((s) => [stageLabel(t, s.stage), s.avg_ms >= 1000 ? `${fmtNumber(s.avg_ms / 1000, 1)} s` : `${fmtNumber(Math.round(s.avg_ms))} ms`, fmtNumber(s.failures)])} />
          </div>
        </Card>
      </div>
      <div className="grid gap-3 lg:grid-cols-2">
        <Card>
          <CardHeader title={t("analytics.aiUsage")} />
          {!data?.ai_usage.length ? <EmptyState title={t("analytics.noAi")} /> : (
            <div className="overflow-x-auto p-4 pt-2 scrollbar-thin">
              <MiniTable head={[t("analytics.task"), t("analytics.model"), t("analytics.calls"), t("analytics.tokens"), t("analytics.cost")]}
                rows={data.ai_usage.map((u) => [u.task, u.model, `${fmtNumber(u.calls)}${u.errors ? ` (${fmtNumber(u.errors)} ✕)` : ""}`, `${fmtNumber(u.input_tokens)} / ${fmtNumber(u.output_tokens)}`, fmtNumber(u.cost_usd, 4)])} />
            </div>
          )}
        </Card>
        <Card>
          <CardHeader title={t("analytics.corrected")} subtitle={t("analytics.correctedHint")} />
          {!data?.most_corrected_fields.length ? <EmptyState title={t("common.noData")} /> : (
            <div className="overflow-x-auto p-4 pt-2 scrollbar-thin">
              <MiniTable head={[t("agent.submit.recordType"), t("agent.mapping.field"), t("analytics.corrections")]}
                rows={data.most_corrected_fields.map((f) => [recordTypeLabel(t, f.record_type), fieldLabel(t, f.record_type, f.field), fmtNumber(f.corrections)])} />
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}

function ProductionTab({ days }: { days: number }) {
  const { t, fmtNumber } = useI18n();
  const company = useCompanyKey();
  const { data, isLoading, error } = useQuery({ queryKey: [company, "overview", days], queryFn: () => api.get<Overview>("/analytics/overview", { days }) });
  if (error) return <ErrorState error={error} />;
  const byDep = data?.charts.by_department ?? [];
  const series: SeriesSpec[] = [{ key: "accepted", label: t("overview.accepted"), color: SERIES[0] }, { key: "defective", label: t("overview.defective"), color: SERIES[1] }];
  return (
    <div className="space-y-3">
      <ChartCard title={t("overview.byDepartment")} subtitle={data?.unit ? t("overview.unitNote", { unit: data.unit }) : undefined} series={series} loading={isLoading} empty={!byDep.length}
        table={<MiniTable head={[t("common.department"), t("overview.produced"), t("overview.defective")]} rows={byDep.map((d) => [d.name, fmtNumber(d.produced), fmtNumber(d.defective)])} />}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={byDep.map((d) => ({ ...d, accepted: d.produced - d.defective }))} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barCategoryGap="30%">
            <CartesianGrid vertical={false} stroke="var(--grid)" />
            <XAxis dataKey="name" {...axisProps} />
            <YAxis {...axisProps} width="auto" tickFormatter={(v: number) => fmtNumber(v)} />
            <Tooltip cursor={{ fill: "var(--surface-3)", opacity: 0.6 }} content={<ChartTooltip series={series} format={(v) => fmtNumber(v)} />} />
            <Bar maxBarSize={48} dataKey="accepted" stackId="d" fill={SERIES[0]} stroke="var(--surface)" strokeWidth={1} isAnimationActive={false} />
            <Bar maxBarSize={48} dataKey="defective" stackId="d" fill={SERIES[1]} stroke="var(--surface)" strokeWidth={1} radius={[3, 3, 0, 0]} isAnimationActive={false} />
          </BarChart>
        </ResponsiveContainer>
      </ChartCard>
      <Card>
        <CardHeader title={t("overview.byProduct")} />
        <div className="overflow-x-auto p-4 pt-2 scrollbar-thin">
          <MiniTable head={[t("common.product"), t("overview.produced"), t("overview.defective"), t("quality.defectRate")]}
            rows={(data?.charts.by_product ?? []).map((p) => [`${p.code} · ${p.name}`, `${fmtNumber(p.produced)} ${p.unit}`, fmtNumber(p.defective), p.defect_rate == null ? "—" : `${fmtNumber(p.defect_rate, 1)}%`])} />
        </div>
      </Card>
      <Card><CardHeader title={t("overview.planVsActual")} /><PlanTable rows={data?.charts.plan_vs_actual ?? []} loading={isLoading} /></Card>
    </div>
  );
}

export default function AnalyticsPage() {
  const { t } = useI18n();
  const [params, setParams] = useSearchParams();
  const tab = params.get("tab") === "agent" ? "agent" : "production";
  const [days, setDays] = useState(30);
  return (
    <div className="space-y-4">
      <PageHeader title={t("analytics.title")} subtitle={t("analytics.subtitle")} actions={
        <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="w-32" aria-label={t("common.period")}>
          {[7, 30, 90, 365].map((d) => <option key={d} value={d}>{t("common.days", { n: d })}</option>)}
        </Select>
      } />
      <Tabs value={tab} onValueChange={(v) => setParams({ tab: v }, { replace: true })}>
        <TabsList className="mb-3">
          <TabsTrigger value="production">{t("analytics.production")}</TabsTrigger>
          <TabsTrigger value="agent">{t("analytics.agent")}</TabsTrigger>
        </TabsList>
        <TabsContent value="production"><ProductionTab days={days} /></TabsContent>
        <TabsContent value="agent"><AgentTab days={days} /></TabsContent>
      </Tabs>
    </div>
  );
}
