import { useInfiniteQuery, useQuery } from "@tanstack/react-query";
import type { ColumnDef } from "@tanstack/react-table";
import { Bot, Plus } from "lucide-react";
import { useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { api, newIdempotencyKey } from "@/api/client";
import { useCompanyKey, useDepartments, useProducts } from "@/api/hooks";
import type { Page, ProductionOrder, ProductionReport } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ConfirmDialog, Dialog, Sheet } from "@/components/ui/dialog";
import { EmptyState, ErrorState, Notice } from "@/components/ui/feedback";
import { Field, Input, Select, Textarea } from "@/components/ui/inputs";
import { DefinitionList, PageHeader, SectionTitle } from "@/components/ui/page";
import { PriorityBadge, StatusBadge } from "@/components/ui/status";
import { DataTable } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useI18n, type MessageKey } from "@/i18n";
import { ifMatch, todayIso, useAction } from "@/lib/action";
import { ReportFields, reportBody, useReportForm } from "./ReportForm";
import { CalendarTab, PlanTab } from "./PlanTabs";
import { ProductsTab } from "./ProductsTab";

const ORDER_TRANSITIONS: Record<string, string[]> = {
  planned: ["released", "in_progress", "cancelled"],
  released: ["in_progress", "planned", "cancelled"],
  in_progress: ["completed", "cancelled"],
  completed: [],
  cancelled: [],
};

export function useOrders(status?: string) {
  const company = useCompanyKey();
  return useQuery({
    queryKey: [company, "production", "orders", status ?? "all"],
    queryFn: async () => (await api.get<Page<ProductionOrder>>("/production/orders", { status, limit: 500 })).items,
  });
}

function SourceBadge({ id }: { id: string | null }) {
  const { t } = useI18n();
  return id ? <Badge tone="brand"><Bot className="size-3" />{t("production.agentShort")}</Badge> : null;
}

function DraftEditor({ report, orders }: { report: ProductionReport; orders: ProductionOrder[] }) {
  const { t } = useI18n();
  const { run, busy } = useAction("production");
  const form = useReportForm(report);
  return (
    <form className="space-y-3 rounded-md border border-border p-3" onSubmit={form.handleSubmit((v) =>
      run("save", () => api.put(`/production/reports/${report.id}`, reportBody(v), ifMatch(report.version)), t("common.saved")))}>
      <SectionTitle>{t("common.edit")}</SectionTitle>
      <ReportFields form={form} orders={orders} showApprove={false} />
      <div className="flex justify-end"><Button type="submit" loading={busy === "save"}>{t("common.saveChanges")}</Button></div>
    </form>
  );
}

function CorrectDialog({ report, orders, onClose, onDone }: { report: ProductionReport; orders: ProductionOrder[]; onClose: () => void; onDone: () => void }) {
  const { t } = useI18n();
  const { run, busy } = useAction("production");
  const form = useReportForm(report);
  const [reason, setReason] = useState("");
  const submit = form.handleSubmit(async (v) => {
    const ok = await run("correct", () => api.post(`/production/reports/${report.id}/correct`, { report: reportBody(v), reason }), t("common.saved"));
    if (ok !== undefined) { onClose(); onDone(); }
  });
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} wide title={`${t("production.correct")} ${report.number}`} description={t("production.correctHint")}
      footer={<>
        <Button onClick={onClose}>{t("common.cancel")}</Button>
        <Button variant="primary" loading={busy === "correct"} disabled={!reason.trim()} onClick={submit}>{t("production.correct")}</Button>
      </>}>
      <div className="space-y-3">
        <ReportFields form={form} orders={orders} showApprove={false} />
        <Field label={t("common.reason")} required>{(fid) => <Textarea id={fid} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />}</Field>
      </div>
    </Dialog>
  );
}

function ReportSheet({ id, onClose }: { id: string; onClose: () => void }) {
  const { t, fmtNumber, fmtDate, fmtDateTime } = useI18n();
  const { can } = useAuth();
  const company = useCompanyKey();
  const { run, busy } = useAction("production");
  const { data: r, error } = useQuery({ queryKey: [company, "production", "report", id], queryFn: () => api.get<ProductionReport>(`/production/reports/${id}`) });
  const { data: orders = [] } = useOrders("planned,released,in_progress");
  const [mode, setMode] = useState<"correct" | "cancel" | null>(null);
  const [reason, setReason] = useState("");

  if (error) return <Sheet open onOpenChange={onClose} title="—"><ErrorState error={error} /></Sheet>;
  return (
    <Sheet open onOpenChange={(o) => !o && onClose()} title={r ? `${t("production.report")} ${r.number}` : "…"}
      description={r && `${fmtDate(r.production_date)} · ${r.department.name}`}
      footer={r && <>
        {can("audit.read") && <Link className="mr-auto self-center text-xs text-primary hover:underline" to={`/trace/production_report/${r.id}`}>{t("common.trace")}</Link>}
        {r.status !== "cancelled" && can("production.write") && <Button variant="ghost" className="text-danger" onClick={() => setMode("cancel")}>{t("production.cancelReport")}</Button>}
        {r.status === "approved" && can("production.write") && <Button onClick={() => setMode("correct")}>{t("production.correct")}</Button>}
        {r.status === "draft" && can("production.approve") && (
          <Button variant="success" loading={busy === "approve"} onClick={() => run("approve", () => api.post(`/production/reports/${r.id}/approve`, {}, ifMatch(r.version)), t("status.approved"))}>
            {t("common.approve")}
          </Button>
        )}
      </>}>
      {!r ? null : (
        <div className="space-y-4">
          <div className="flex flex-wrap gap-2"><StatusBadge status={r.status} /><SourceBadge id={r.source_record_id} />
            {r.supersedes_id && <Badge tone="info">{t("production.supersedes")}</Badge>}
            {r.superseded_by && <Badge tone="warning">{t("production.supersededBy", { number: r.superseded_by.number })}</Badge>}
          </div>
          <DefinitionList items={[
            [t("common.product"), `${r.product.code} · ${r.product.name}`],
            [t("common.department"), r.department.name],
            [t("production.shift"), r.shift?.name],
            [t("production.order"), r.order?.number],
            [t("production.produced"), `${fmtNumber(r.qty_produced)} ${r.unit}`],
            [t("production.accepted"), r.qty_accepted != null ? `${fmtNumber(r.qty_accepted)} ${r.unit}` : "—"],
            [t("production.defective"), r.qty_defective != null ? `${fmtNumber(r.qty_defective)} ${r.unit}` : "—"],
            [t("common.notes"), r.notes],
            [t("common.created"), fmtDateTime(r.created_at)],
            [t("status.approved"), fmtDateTime(r.approved_at)],
          ]} />
          {r.status === "draft" && can("production.write") && <DraftEditor key={r.version} report={r} orders={orders} />}
          {!!r.stock_movements?.length && (
            <section>
              <SectionTitle>{t("production.stockMovements")}</SectionTitle>
              <ul className="space-y-1 text-xs">
                {r.stock_movements.map((m) => (
                  <li key={m.id} className="flex gap-2"><span className="font-medium">{m.number}</span><span>{t(`movement.${m.type}` as MessageKey)}</span>
                    <span className="tabular">{fmtNumber(m.qty)} {m.unit}</span><span className="text-muted">{m.warehouse}</span>
                    {m.reverses_id && <Badge>{t("inventory.reversal")}</Badge>}</li>
                ))}
              </ul>
            </section>
          )}
        </div>
      )}
      {r && mode === "correct" && <CorrectDialog report={r} orders={orders} onClose={() => setMode(null)} onDone={onClose} />}
      <ConfirmDialog open={mode === "cancel"} onOpenChange={(o) => !o && setMode(null)} title={t("production.cancelReport")} danger confirmLabel={t("production.cancelReport")}
        loading={busy === "cancel"} body={r?.status === "approved" ? t("production.cancelHint") : undefined}
        onConfirm={async () => {
          if (!r || !reason.trim()) return;
          const ok = await run("cancel", () => api.post(`/production/reports/${r.id}/cancel`, { reason }), t("status.cancelled"));
          if (ok !== undefined) { setMode(null); setReason(""); }
        }}>
        <Field label={t("common.reason")} required className="mt-3">{(fid) => <Textarea id={fid} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />}</Field>
      </ConfirmDialog>
    </Sheet>
  );
}

function NewReportDialog({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const { can } = useAuth();
  const { run, busy } = useAction("production");
  const { data: orders = [] } = useOrders("planned,released,in_progress");
  const form = useReportForm(null);
  const submit = form.handleSubmit(async (v) => {
    const ok = await run("create", () => api.post("/production/reports", { ...reportBody(v), approve: v.approve }, { "Idempotency-Key": newIdempotencyKey() }), t("common.saved"));
    if (ok !== undefined) onClose();
  });
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} wide title={t("production.newReport")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button><Button variant="primary" loading={busy === "create"} onClick={submit}>{t("common.create")}</Button></>}>
      <ReportFields form={form} orders={orders} showApprove={can("production.approve")} />
    </Dialog>
  );
}

function ReportsTab() {
  const { t, fmtNumber, fmtDate } = useI18n();
  const company = useCompanyKey();
  const [params, setParams] = useSearchParams();
  const { data: products } = useProducts();
  const { data: departments } = useDepartments();
  const [filters, setFilters] = useState({ date_from: "", date_to: "", product_id: "", department_id: "", status: "" });
  const selected = params.get("report");
  const open = (id: string | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set("report", id);
    else next.delete("report");
    setParams(next, { replace: true });
  };
  const query = useInfiniteQuery({
    queryKey: [company, "production", "reports", filters],
    queryFn: ({ pageParam }) => api.get<Page<ProductionReport>>("/production/reports", { ...filters, cursor: pageParam, limit: 100 }),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.next_cursor ?? undefined,
  });
  const rows = useMemo(() => query.data?.pages.flatMap((p) => p.items) ?? [], [query.data]);
  const columns = useMemo<ColumnDef<ProductionReport>[]>(() => [
    { header: t("common.number"), accessorKey: "number", cell: ({ row }) => <span className="font-medium">{row.original.number}</span> },
    { header: t("common.date"), accessorKey: "production_date", cell: ({ getValue }) => fmtDate(getValue() as string) },
    { header: t("common.product"), id: "product", accessorFn: (r) => r.product.code, cell: ({ row }) => <span>{row.original.product.code} <span className="text-muted">{row.original.product.name}</span></span> },
    { header: t("common.department"), id: "dep", accessorFn: (r) => r.department.name },
    { header: t("production.shift"), id: "shift", accessorFn: (r) => r.shift?.name ?? "—" },
    { header: t("production.produced"), id: "produced", accessorFn: (r) => Number(r.qty_produced), meta: { align: "right" }, cell: ({ row }) => `${fmtNumber(row.original.qty_produced)} ${row.original.unit}` },
    { header: t("production.accepted"), id: "accepted", accessorFn: (r) => Number(r.qty_accepted ?? 0), meta: { align: "right" }, cell: ({ row }) => fmtNumber(row.original.qty_accepted) },
    { header: t("production.defective"), id: "defective", accessorFn: (r) => Number(r.qty_defective ?? 0), meta: { align: "right" }, cell: ({ row }) => fmtNumber(row.original.qty_defective) },
    { header: t("common.status"), accessorKey: "status", cell: ({ row }) => <span className="inline-flex gap-1"><StatusBadge status={row.original.status} /><SourceBadge id={row.original.source_record_id} /></span> },
  ], [t, fmtNumber, fmtDate]);
  const set = (k: keyof typeof filters, v: string) => setFilters((f) => ({ ...f, [k]: v }));
  return (
    <Card>
      <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2">
        <Input type="date" value={filters.date_from} onChange={(e) => set("date_from", e.target.value)} className="w-36" aria-label={t("common.from")} />
        <Input type="date" value={filters.date_to} onChange={(e) => set("date_to", e.target.value)} className="w-36" aria-label={t("common.to")} />
        <Select value={filters.product_id} onChange={(e) => set("product_id", e.target.value)} className="w-48" aria-label={t("common.product")}>
          <option value="">{t("production.filterProduct")}</option>
          {(products ?? []).filter((p) => p.product_type !== "raw_material").map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
        </Select>
        <Select value={filters.department_id} onChange={(e) => set("department_id", e.target.value)} className="w-40" aria-label={t("common.department")}>
          <option value="">{t("production.filterDepartment")}</option>
          {(departments ?? []).filter((d) => d.kind === "workshop").map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </Select>
        <Select value={filters.status} onChange={(e) => set("status", e.target.value)} className="w-36" aria-label={t("common.status")}>
          <option value="">{t("agent.history.allStatuses")}</option>
          {["draft", "approved", "cancelled"].map((s) => <option key={s} value={s}>{t(`status.${s}` as MessageKey)}</option>)}
        </Select>
      </div>
      {query.error ? <ErrorState error={query.error} className="m-4" /> : (
        <DataTable columns={columns} data={rows} loading={query.isLoading} onRowClick={(r) => open(r.id)} getRowId={(r) => r.id} selectedId={selected}
          rowClassName={(r) => (r.status === "cancelled" ? "text-subtle line-through decoration-subtle/40" : undefined)}
          empty={<EmptyState title={t("common.noData")} />} />
      )}
      {query.hasNextPage && <div className="border-t border-border p-2 text-center"><Button size="sm" variant="ghost" loading={query.isFetchingNextPage} onClick={() => query.fetchNextPage()}>{t("common.loadMore")}</Button></div>}
      {selected && <ReportSheet id={selected} onClose={() => open(null)} />}
    </Card>
  );
}

function OrderDialog({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const { data: products } = useProducts();
  const { data: departments } = useDepartments();
  const { run, busy } = useAction("production");
  const [v, setV] = useState({ product_id: "", planned_qty: "", department_id: "", planned_start: todayIso(), planned_end: "", priority: "normal", external_number: "", notes: "" });
  const set = (k: keyof typeof v, value: string) => setV((s) => ({ ...s, [k]: value }));
  const valid = v.product_id && Number(v.planned_qty.replace(",", ".")) > 0 && (!v.planned_end || !v.planned_start || v.planned_end >= v.planned_start);
  const submit = async () => {
    const ok = await run("create", () => api.post("/production/orders", {
      ...v, planned_qty: v.planned_qty.replace(",", "."), department_id: v.department_id || null, planned_start: v.planned_start || null,
      planned_end: v.planned_end || null, external_number: v.external_number || null, notes: v.notes || null,
    }, { "Idempotency-Key": newIdempotencyKey() }), t("common.saved"));
    if (ok !== undefined) onClose();
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={t("production.newOrder")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button><Button variant="primary" disabled={!valid} loading={busy === "create"} onClick={submit}>{t("common.create")}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("common.product")} required className="sm:col-span-2">
          {(id) => <Select id={id} value={v.product_id} onChange={(e) => set("product_id", e.target.value)}><option value="">—</option>
            {(products ?? []).filter((p) => ["finished_good", "semi_finished"].includes(p.product_type)).map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}</Select>}
        </Field>
        <Field label={t("production.plannedQty")} required>{(id) => <Input id={id} inputMode="decimal" value={v.planned_qty} onChange={(e) => set("planned_qty", e.target.value)} />}</Field>
        <Field label={t("common.department")}>
          {(id) => <Select id={id} value={v.department_id} onChange={(e) => set("department_id", e.target.value)}><option value="">—</option>
            {(departments ?? []).filter((d) => d.kind === "workshop").map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</Select>}
        </Field>
        <Field label={t("production.plannedStart")}>{(id) => <Input id={id} type="date" value={v.planned_start} onChange={(e) => set("planned_start", e.target.value)} />}</Field>
        <Field label={t("production.plannedEnd")} error={v.planned_end && v.planned_start && v.planned_end < v.planned_start ? t("common.invalidValue") : undefined}>
          {(id) => <Input id={id} type="date" value={v.planned_end} onChange={(e) => set("planned_end", e.target.value)} />}
        </Field>
        <Field label={t("common.priority")}>
          {(id) => <Select id={id} value={v.priority} onChange={(e) => set("priority", e.target.value)}>{["low", "normal", "high", "urgent"].map((p) => <option key={p} value={p}>{t(`priority.${p}` as MessageKey)}</option>)}</Select>}
        </Field>
        <Field label={t("production.externalNumber")}>{(id) => <Input id={id} value={v.external_number} onChange={(e) => set("external_number", e.target.value)} />}</Field>
        <Field label={t("common.notes")} className="sm:col-span-2">{(id) => <Textarea id={id} rows={2} value={v.notes} onChange={(e) => set("notes", e.target.value)} />}</Field>
      </div>
    </Dialog>
  );
}

function OrdersTab() {
  const { t, fmtNumber, fmtDate } = useI18n();
  const { can } = useAuth();
  const [status, setStatus] = useState("");
  const { data = [], isLoading, error } = useOrders(status || undefined);
  const { run, busy } = useAction("production");
  const [selected, setSelected] = useState<ProductionOrder | null>(null);
  const columns = useMemo<ColumnDef<ProductionOrder>[]>(() => [
    { header: t("common.number"), accessorKey: "number", cell: ({ row }) => <span className="font-medium">{row.original.number}</span> },
    { header: t("common.product"), id: "product", accessorFn: (o) => o.product.code, cell: ({ row }) => <span>{row.original.product.code} <span className="text-muted">{row.original.product.name}</span></span> },
    { header: t("common.department"), id: "dep", accessorFn: (o) => o.department?.name ?? "—" },
    { header: t("production.plannedStart"), accessorKey: "planned_start", cell: ({ getValue }) => fmtDate(getValue() as string) },
    { header: t("production.plannedEnd"), accessorKey: "planned_end", cell: ({ getValue }) => fmtDate(getValue() as string) },
    { header: t("production.plannedQty"), id: "qty", accessorFn: (o) => Number(o.planned_qty), meta: { align: "right" }, cell: ({ row }) => `${fmtNumber(row.original.planned_qty)} ${row.original.unit}` },
    { header: t("production.actualQty"), id: "actual", accessorFn: (o) => Number(o.actual_qty ?? 0), meta: { align: "right" }, cell: ({ row }) => fmtNumber(row.original.actual_qty) },
    { header: t("common.priority"), accessorKey: "priority", cell: ({ getValue }) => <PriorityBadge priority={getValue() as string} /> },
    { header: t("common.status"), accessorKey: "status", cell: ({ row }) => <span className="inline-flex gap-1"><StatusBadge status={row.original.status} /><SourceBadge id={row.original.source_record_id} /></span> },
  ], [t, fmtNumber, fmtDate]);
  return (
    <Card>
      <div className="flex items-center gap-2 border-b border-border px-4 py-2">
        <Select value={status} onChange={(e) => setStatus(e.target.value)} className="w-44" aria-label={t("common.status")}>
          <option value="">{t("agent.history.allStatuses")}</option>
          {Object.keys(ORDER_TRANSITIONS).map((s) => <option key={s} value={s}>{t(`status.${s}` as MessageKey)}</option>)}
        </Select>
      </div>
      {error ? <ErrorState error={error} className="m-4" /> : (
        <DataTable columns={columns} data={data} loading={isLoading} onRowClick={setSelected} getRowId={(o) => o.id} selectedId={selected?.id}
          empty={<EmptyState title={t("common.noData")} />} />
      )}
      {selected && (
        <Sheet open onOpenChange={(o) => !o && setSelected(null)} title={`${t("production.order")} ${selected.number}`} description={`${selected.product.code} · ${selected.product.name}`}
          footer={can("production.write") && ORDER_TRANSITIONS[selected.status].map((s) => (
            <Button key={s} variant={s === "cancelled" ? "ghost" : "secondary"} className={s === "cancelled" ? "text-danger" : undefined} loading={busy === s}
              onClick={async () => {
                const ok = await run(s, () => api.post(`/production/orders/${selected.id}/status`, { status: s }, ifMatch(selected.version)), t("common.saved"));
                if (ok !== undefined) setSelected(null);
              }}>
              {t("production.transition", { status: t(`status.${s}` as MessageKey) })}
            </Button>
          ))}>
          <div className="space-y-3">
            <div className="flex gap-2"><StatusBadge status={selected.status} /><PriorityBadge priority={selected.priority} /><SourceBadge id={selected.source_record_id} /></div>
            <DefinitionList items={[
              [t("common.department"), selected.department?.name],
              [t("production.plannedQty"), `${fmtNumber(selected.planned_qty)} ${selected.unit}`],
              [t("production.actualQty"), `${fmtNumber(selected.actual_qty ?? 0)} ${selected.unit}`],
              [t("production.plannedStart"), fmtDate(selected.planned_start)],
              [t("production.plannedEnd"), fmtDate(selected.planned_end)],
              [t("production.externalNumber"), selected.external_number],
              [t("common.notes"), selected.notes],
            ]} />
            {(selected.status === "completed" || selected.status === "cancelled") && <Notice tone="info">{t("production.orderClosed")}</Notice>}
          </div>
        </Sheet>
      )}
    </Card>
  );
}

const TABS = ["reports", "orders", "plan", "calendar", "products"] as const;

export default function ProductionPage() {
  const { t } = useI18n();
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const tab = (TABS as readonly string[]).includes(params.get("tab") ?? "") ? params.get("tab")! : "reports";
  const [dialog, setDialog] = useState<"report" | "order" | null>(null);
  return (
    <div className="space-y-4">
      <PageHeader title={t("production.title")} subtitle={t("production.subtitle")} actions={can("production.write") && <>
        <Button size="sm" onClick={() => setDialog("order")}><Plus className="size-3.5" />{t("production.newOrder")}</Button>
        <Button size="sm" variant="primary" onClick={() => setDialog("report")}><Plus className="size-3.5" />{t("production.newReport")}</Button>
      </>} />
      <Tabs value={tab} onValueChange={(v) => setParams({ tab: v }, { replace: true })}>
        <TabsList className="mb-3">{TABS.map((k) => <TabsTrigger key={k} value={k}>{t(`production.tab.${k}` as MessageKey)}</TabsTrigger>)}</TabsList>
        <TabsContent value="reports"><ReportsTab /></TabsContent>
        <TabsContent value="orders"><OrdersTab /></TabsContent>
        <TabsContent value="plan"><PlanTab /></TabsContent>
        <TabsContent value="calendar"><CalendarTab /></TabsContent>
        <TabsContent value="products"><ProductsTab /></TabsContent>
      </Tabs>
      {dialog === "report" && <NewReportDialog onClose={() => setDialog(null)} />}
      {dialog === "order" && <OrderDialog onClose={() => setDialog(null)} />}
    </div>
  );
}
