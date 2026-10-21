import { useQuery } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { useState } from "react";
import { api } from "@/api/client";
import { useCompanyKey } from "@/api/hooks";
import type { PlanVsActual, ProductionOrder } from "@/api/types";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { ErrorState, Notice, Skeleton } from "@/components/ui/feedback";
import { Input } from "@/components/ui/inputs";
import { PlanTable } from "@/features/overview/OverviewPage";
import { useI18n } from "@/i18n";
import { todayIso } from "@/lib/action";
import { cn } from "@/lib/utils";

const shift = (iso: string, days: number) => {
  const d = new Date(`${iso}T00:00:00`);
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

export function PlanTab() {
  const { t } = useI18n();
  const company = useCompanyKey();
  const [range, setRange] = useState({ date_from: shift(todayIso(), -30), date_to: shift(todayIso(), 30) });
  const { data, isLoading, error } = useQuery({
    queryKey: [company, "production", "plan", range],
    queryFn: () => api.get<PlanVsActual[]>("/production/plan-vs-actual", range),
  });
  return (
    <Card>
      <CardHeader title={t("production.tab.plan")} subtitle={t("production.planHint")} actions={<>
        <Input type="date" value={range.date_from} onChange={(e) => setRange((r) => ({ ...r, date_from: e.target.value }))} className="h-7 w-36 text-xs" aria-label={t("common.from")} />
        <Input type="date" value={range.date_to} onChange={(e) => setRange((r) => ({ ...r, date_to: e.target.value }))} className="h-7 w-36 text-xs" aria-label={t("common.to")} />
      </>} />
      {error ? <ErrorState error={error} className="m-4" /> : <PlanTable rows={data ?? []} loading={isLoading} />}
    </Card>
  );
}

interface CalendarData {
  month: string;
  orders: ProductionOrder[];
  days: Record<string, { produced: number; defective: number }>;
  note: string;
}

export function CalendarTab() {
  const { t, fmtNumber, fmtDate, locale } = useI18n();
  const company = useCompanyKey();
  const [month, setMonth] = useState(todayIso().slice(0, 7));
  const { data, isLoading, error } = useQuery({
    queryKey: [company, "production", "calendar", month],
    queryFn: () => api.get<CalendarData>("/production/calendar", { month }),
  });
  const first = new Date(`${month}-01T00:00:00`);
  const daysInMonth = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
  const lead = (first.getDay() + 6) % 7; // Monday first
  const today = todayIso();
  const move = (delta: number) => {
    const d = new Date(first.getFullYear(), first.getMonth() + delta, 1);
    setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`);
  };
  const weekdays = Array.from({ length: 7 }, (_, i) => new Intl.DateTimeFormat(locale, { weekday: "short" }).format(new Date(2024, 0, 1 + i)));
  const cells = [...Array(lead).fill(null), ...Array.from({ length: daysInMonth }, (_, i) => `${month}-${String(i + 1).padStart(2, "0")}`)];
  return (
    <Card>
      <CardHeader title={fmtDate(first, { month: "long", year: "numeric" })} actions={<>
        <Button size="icon" variant="ghost" onClick={() => move(-1)} aria-label={t("production.calendar.prev")}><ChevronLeft className="size-4" /></Button>
        <Button size="xs" onClick={() => setMonth(today.slice(0, 7))}>{t("production.calendar.today")}</Button>
        <Button size="icon" variant="ghost" onClick={() => move(1)} aria-label={t("production.calendar.next")}><ChevronRight className="size-4" /></Button>
      </>} />
      {error && <ErrorState error={error} className="m-4" />}
      {isLoading ? <Skeleton className="m-4 h-96" /> : (
        <div className="overflow-x-auto p-3 scrollbar-thin">
          <div className="grid min-w-[720px] grid-cols-7 gap-px overflow-hidden rounded-md border border-border bg-border">
            {weekdays.map((w) => <div key={w} className="bg-surface-2 px-2 py-1 text-2xs font-medium text-muted">{w}</div>)}
            {cells.map((day, i) => {
              if (!day) return <div key={`e${i}`} className="min-h-24 bg-surface-2/50" />;
              const output = data?.days[day];
              const orders = (data?.orders ?? []).filter((o) => (o.planned_start ?? o.planned_end ?? "") <= day && day <= (o.planned_end ?? o.planned_start ?? ""));
              return (
                <div key={day} className={cn("min-h-24 bg-surface p-1.5", day === today && "ring-2 ring-inset ring-primary")}>
                  <div className="flex items-center justify-between">
                    <span className={cn("text-xs", day === today ? "font-semibold text-primary" : "text-muted")}>{Number(day.slice(8))}</span>
                    {output && <span className="text-2xs font-medium tabular">{fmtNumber(output.produced)}</span>}
                  </div>
                  <ul className="mt-1 space-y-0.5">
                    {orders.slice(0, 3).map((o) => (
                      <li key={o.id} title={`${o.number} · ${o.product.name}`}
                        className={cn("truncate rounded px-1 text-2xs", o.status === "completed" ? "bg-success-soft text-success" : o.status === "in_progress" ? "bg-info-soft text-info" : "bg-surface-3 text-muted")}>
                        {o.number.split("-").pop()} {o.product.code}
                      </li>
                    ))}
                    {orders.length > 3 && <li className="text-2xs text-subtle">+{orders.length - 3}</li>}
                  </ul>
                </div>
              );
            })}
          </div>
          <Notice tone="info" className="mt-3">{t("production.calendarNote")}</Notice>
        </div>
      )}
    </Card>
  );
}
