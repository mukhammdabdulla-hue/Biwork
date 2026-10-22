import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { Link } from "react-router-dom";
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { api } from "@/api/client";
import { useCompanyKey } from "@/api/hooks";
import type { Quality } from "@/api/types";
import { Badge } from "@/components/ui/badge";
import { Card, CardHeader } from "@/components/ui/card";
import { axisProps, ChartCard, ChartTooltip, MiniTable, SERIES } from "@/components/ui/charts";
import { EmptyState, ErrorState, Notice } from "@/components/ui/feedback";
import { Select } from "@/components/ui/inputs";
import { KpiCard, PageHeader } from "@/components/ui/page";
import { useI18n } from "@/i18n";

export default function QualityPage() {
  const { t, fmtNumber, fmtDate } = useI18n();
  const company = useCompanyKey();
  const [days, setDays] = useState(30);
  const { data, isLoading, error } = useQuery({ queryKey: [company, "quality", days], queryFn: () => api.get<Quality>("/analytics/quality", { days }) });
  const produced = (data?.by_product ?? []).reduce((a, p) => a + p.produced, 0);
  const defective = (data?.by_product ?? []).reduce((a, p) => a + p.defective, 0);
  const rate = produced ? (defective / produced) * 100 : null;
  const pct = (v: number) => `${fmtNumber(v, 1)}%`;
  return (
    <div className="space-y-4">
      <PageHeader title={t("quality.title")} subtitle={t("quality.subtitle")} badge={<Badge>{t("quality.interim")}</Badge>} actions={
        <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="w-32" aria-label={t("common.period")}>
          {[30, 90, 180].map((d) => <option key={d} value={d}>{t("common.days", { n: d })}</option>)}
        </Select>
      } />
      <Notice tone="info">{t("quality.plannedNote")}</Notice>
      {error && <ErrorState error={error} />}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard loading={isLoading} label={t("quality.defectRate")} value={rate == null ? "—" : pct(rate)} tone={rate != null && rate > 5 ? "warning" : "default"} />
        <KpiCard loading={isLoading} label={t("overview.produced")} value={fmtNumber(produced)} hint={t("quality.mixedUnits")} />
        <KpiCard loading={isLoading} label={t("overview.defective")} value={fmtNumber(defective)} />
        <KpiCard loading={isLoading} label={t("quality.reports")} value={fmtNumber((data?.by_product ?? []).reduce((a, p) => a + p.reports, 0))} />
      </div>
      <div className="grid gap-3 xl:grid-cols-2">
        <ChartCard title={t("quality.daily")} loading={isLoading} empty={!data?.daily.length}
          table={<MiniTable head={[t("common.date"), t("overview.produced"), t("overview.defective"), t("quality.defectRate")]}
            rows={(data?.daily ?? []).map((d) => [fmtDate(d.date), fmtNumber(d.produced), fmtNumber(d.defective), d.defect_rate == null ? "—" : pct(d.defect_rate)])} />}>
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data?.daily ?? []} margin={{ top: 6, right: 8, bottom: 0, left: 0 }}>
              <CartesianGrid vertical={false} stroke="var(--grid)" />
              <XAxis dataKey="date" {...axisProps} tickFormatter={(d: string) => fmtDate(d, { day: "2-digit", month: "short" })} minTickGap={24} />
              <YAxis {...axisProps} width="auto" tickFormatter={(v: number) => `${fmtNumber(v)}%`} />
              <Tooltip content={<ChartTooltip series={[{ key: "defect_rate", label: t("quality.defectRate"), color: SERIES[1] }]} format={pct} labelFormat={(l) => fmtDate(l)} />} />
              <Line dataKey="defect_rate" stroke={SERIES[1]} strokeWidth={2} dot={false} activeDot={{ r: 4, strokeWidth: 2, stroke: "var(--surface)" }} isAnimationActive={false} connectNulls />
            </LineChart>
          </ResponsiveContainer>
        </ChartCard>
        <ChartCard title={t("quality.byProduct")} loading={isLoading} empty={!data?.by_product.length}
          table={<MiniTable head={[t("common.product"), t("overview.produced"), t("overview.defective"), t("quality.defectRate")]}
            rows={(data?.by_product ?? []).map((p) => [`${p.code} · ${p.name}`, fmtNumber(p.produced), fmtNumber(p.defective), p.defect_rate == null ? "—" : pct(p.defect_rate)])} />}>
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={data?.by_product ?? []} layout="vertical" margin={{ top: 0, right: 40, bottom: 0, left: 0 }} barCategoryGap={6}>
              <XAxis type="number" hide />
              <YAxis type="category" dataKey="code" {...axisProps} width={70} />
              <Tooltip cursor={{ fill: "var(--surface-3)", opacity: 0.6 }} content={<ChartTooltip series={[{ key: "defect_rate", label: t("quality.defectRate"), color: SERIES[1] }]} format={pct} />} />
              <Bar maxBarSize={48} dataKey="defect_rate" fill={SERIES[1]} radius={[0, 3, 3, 0]} isAnimationActive={false}
                label={{ position: "right", fontSize: 11, fill: "var(--text-muted)", formatter: (v: unknown) => (v == null ? "" : pct(Number(v))) }} />
            </BarChart>
          </ResponsiveContainer>
        </ChartCard>
      </div>
      <Card>
        <CardHeader title={t("quality.worst")} />
        {!data?.worst_reports.length ? <EmptyState title={t("common.noData")} /> : (
          <div className="overflow-x-auto p-4 pt-2 scrollbar-thin">
            <MiniTable head={[t("common.number"), t("common.date"), t("common.product"), t("overview.produced"), t("overview.defective"), t("quality.defectRate")]}
              rows={data.worst_reports.map((r) => [<Link key={r.id} to={`/production?report=${r.id}`} className="text-primary hover:underline">{r.number}</Link>,
                fmtDate(r.date), r.product, fmtNumber(r.produced), fmtNumber(r.defective), r.defect_rate == null ? "—" : pct(r.defect_rate)])} />
          </div>
        )}
      </Card>
    </div>
  );
}
