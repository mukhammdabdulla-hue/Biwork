import { useInfiniteQuery, useQuery } from "@tanstack/react-query";
import type { ColumnDef } from "@tanstack/react-table";
import { Bot, CheckCircle2, Plus, Undo2 } from "lucide-react";
import { useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { api, newIdempotencyKey } from "@/api/client";
import { useCompanyKey, useProducts, useUnits, useWarehouses } from "@/api/hooks";
import type { Balance, Movement, Page, Shortage, Warehouse } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ConfirmDialog, Dialog } from "@/components/ui/dialog";
import { EmptyState, ErrorState } from "@/components/ui/feedback";
import { Checkbox, Field, Input, Select, Textarea } from "@/components/ui/inputs";
import { PageHeader } from "@/components/ui/page";
import { DataTable } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useI18n, type MessageKey } from "@/i18n";
import { todayIso, useAction } from "@/lib/action";
import { cn, toNumber } from "@/lib/utils";

const TYPES = ["receipt", "issue", "adjustment_in", "adjustment_out", "production_output", "production_consumption", "transfer_in", "transfer_out"];

function WarehouseSelect({ value, onChange, all }: { value: string; onChange: (v: string) => void; all?: boolean }) {
  const { t } = useI18n();
  const { data } = useWarehouses();
  return (
    <Select value={value} onChange={(e) => onChange(e.target.value)} className="w-44" aria-label={t("common.warehouse")}>
      <option value="">{all ? t("inventory.allWarehouses") : "—"}</option>
      {(data ?? []).map((w) => <option key={w.id} value={w.id}>{w.code} · {w.name}</option>)}
    </Select>
  );
}

function BalancesTab() {
  const { t, fmtNumber, fmtRelative } = useI18n();
  const company = useCompanyKey();
  const [warehouse, setWarehouse] = useState("");
  const [zero, setZero] = useState(false);
  const { data, isLoading, error } = useQuery({
    queryKey: [company, "inventory", "balances", warehouse, zero],
    queryFn: () => api.get<Balance[]>("/inventory/balances", { warehouse_id: warehouse || undefined, include_zero: zero || undefined }),
  });
  const columns = useMemo<ColumnDef<Balance>[]>(() => [
    { header: t("common.warehouse"), id: "wh", accessorFn: (b) => b.warehouse.code, cell: ({ row }) => <span>{row.original.warehouse.code} <span className="text-muted">{row.original.warehouse.name}</span></span> },
    { header: t("common.product"), id: "product", accessorFn: (b) => b.product.code, cell: ({ row }) => <span><span className="font-medium">{row.original.product.code}</span> <span className="text-muted">{row.original.product.name}</span></span> },
    { header: t("common.type"), id: "type", accessorFn: (b) => b.product.type, cell: ({ getValue }) => t(`ptype.${getValue() as string}` as MessageKey) },
    { header: t("inventory.batch"), accessorKey: "batch_code", cell: ({ getValue }) => (getValue() as string) || "—" },
    {
      header: t("inventory.onHand"), id: "qty", accessorFn: (b) => Number(b.qty), meta: { align: "right" }, cell: ({ row }) => {
        const low = row.original.reorder_level != null && Number(row.original.qty) < Number(row.original.reorder_level);
        return <span className={cn("font-medium", low && "text-warning")}>{fmtNumber(row.original.qty)} {row.original.unit}</span>;
      },
    },
    { header: t("production.reorderLevel"), accessorKey: "reorder_level", meta: { align: "right" }, cell: ({ getValue }) => fmtNumber(getValue()) },
    { header: t("common.updated"), accessorKey: "updated_at", cell: ({ getValue }) => <span className="text-muted">{fmtRelative(getValue() as string)}</span> },
  ], [t, fmtNumber, fmtRelative]);
  return (
    <Card>
      <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2">
        <WarehouseSelect value={warehouse} onChange={setWarehouse} all />
        <Checkbox checked={zero} onChange={setZero} label={t("inventory.includeZero")} />
      </div>
      {error ? <ErrorState error={error} className="m-4" /> : <DataTable columns={columns} data={data ?? []} loading={isLoading} empty={<EmptyState title={t("common.noData")} />} />}
    </Card>
  );
}

function MovementDialog({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const { data: products } = useProducts();
  const { data: units } = useUnits();
  const { run, busy } = useAction("inventory");
  const [v, setV] = useState({ movement_date: todayIso(), movement_type: "receipt", warehouse_id: "", product_id: "", quantity: "", unit: "", batch_code: "", reference: "", notes: "" });
  const set = (k: keyof typeof v, value: string) => setV((s) => ({ ...s, [k]: value }));
  const product = products?.find((p) => p.id === v.product_id);
  const unit = v.unit || product?.base_unit || "";
  const valid = v.warehouse_id && v.product_id && unit && Number(v.quantity.replace(",", ".")) > 0;
  const submit = async () => {
    const ok = await run("create", () => api.post("/inventory/movements", {
      ...v, unit, quantity: v.quantity.replace(",", "."), batch_code: v.batch_code || null, reference: v.reference || null, notes: v.notes || null,
    }, { "Idempotency-Key": newIdempotencyKey() }), t("common.saved"));
    if (ok !== undefined) onClose();
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={t("inventory.newMovement")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button><Button variant="primary" disabled={!valid} loading={busy === "create"} onClick={submit}>{t("common.create")}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("inventory.movementType")} required>{(id) => <Select id={id} value={v.movement_type} onChange={(e) => set("movement_type", e.target.value)}>{TYPES.map((m) => <option key={m} value={m}>{t(`movement.${m}` as MessageKey)}</option>)}</Select>}</Field>
        <Field label={t("common.date")} required>{(id) => <Input id={id} type="date" value={v.movement_date} onChange={(e) => set("movement_date", e.target.value)} />}</Field>
        <Field label={t("common.warehouse")} required>{() => <WarehouseSelect value={v.warehouse_id} onChange={(x) => set("warehouse_id", x)} />}</Field>
        <Field label={t("common.product")} required>
          {(id) => <Select id={id} value={v.product_id} onChange={(e) => { set("product_id", e.target.value); set("unit", ""); }}><option value="">—</option>
            {(products ?? []).map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}</Select>}
        </Field>
        <Field label={t("common.quantity")} required>{(id) => <Input id={id} inputMode="decimal" value={v.quantity} onChange={(e) => set("quantity", e.target.value)} />}</Field>
        <Field label={t("common.unit")} required>
          {(id) => <Select id={id} value={unit} onChange={(e) => set("unit", e.target.value)}><option value="">—</option>
            {(units ?? []).map((u) => <option key={u.code} value={u.code}>{u.code} · {u.name}</option>)}</Select>}
        </Field>
        <Field label={t("inventory.batch")}>{(id) => <Input id={id} value={v.batch_code} onChange={(e) => set("batch_code", e.target.value)} />}</Field>
        <Field label={t("inventory.reference")}>{(id) => <Input id={id} value={v.reference} onChange={(e) => set("reference", e.target.value)} />}</Field>
        <Field label={t("common.notes")} className="sm:col-span-2">{(id) => <Textarea id={id} rows={2} value={v.notes} onChange={(e) => set("notes", e.target.value)} />}</Field>
      </div>
    </Dialog>
  );
}

function MovementsTab() {
  const { t, fmtNumber, fmtDate } = useI18n();
  const { can } = useAuth();
  const company = useCompanyKey();
  const [f, setF] = useState({ warehouse_id: "", movement_type: "", date_from: "", date_to: "" });
  const [reversing, setReversing] = useState<Movement | null>(null);
  const [reason, setReason] = useState("");
  const { run, busy } = useAction("inventory");
  const query = useInfiniteQuery({
    queryKey: [company, "inventory", "movements", f],
    queryFn: ({ pageParam }) => api.get<Page<Movement>>("/inventory/movements", { ...f, cursor: pageParam, limit: 100 }),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.next_cursor ?? undefined,
  });
  const rows = useMemo(() => query.data?.pages.flatMap((p) => p.items) ?? [], [query.data]);
  const reversed = useMemo(() => new Set(rows.map((r) => r.reverses_id).filter(Boolean)), [rows]);
  const columns = useMemo<ColumnDef<Movement>[]>(() => [
    { header: t("common.number"), accessorKey: "number", cell: ({ row }) => <span className="font-medium">{row.original.number}</span> },
    { header: t("common.date"), accessorKey: "movement_date", cell: ({ getValue }) => fmtDate(getValue() as string) },
    { header: t("common.type"), accessorKey: "movement_type", cell: ({ row }) => <span className="inline-flex gap-1">{t(`movement.${row.original.movement_type}` as MessageKey)}{row.original.reverses_id && <Badge>{t("inventory.reversal")}</Badge>}</span> },
    { header: t("common.warehouse"), id: "wh", accessorFn: (m) => m.warehouse.code },
    { header: t("common.product"), id: "product", accessorFn: (m) => m.product.code, cell: ({ row }) => <span>{row.original.product.code} <span className="text-muted">{row.original.product.name}</span></span> },
    {
      // qty is already signed (negative for issues and reversals); the sign is drawn separately.
      header: t("common.quantity"), id: "qty", accessorFn: (m) => Number(m.qty), meta: { align: "right" }, cell: ({ row }) => (
        <span className={cn("font-medium", Number(row.original.qty) > 0 ? "text-success" : "text-danger")}>{Number(row.original.qty) > 0 ? "+" : "−"}{fmtNumber(Math.abs(Number(row.original.qty)))} {row.original.unit}</span>
      ),
    },
    { header: t("inventory.reference"), accessorKey: "reference", cell: ({ row }) => <span className="inline-flex items-center gap-1 text-muted">{row.original.reference ?? "—"}{row.original.source_record_id && <Bot className="size-3 text-brand" aria-label={t("production.fromAgent")} />}</span> },
    {
      id: "actions", header: "", enableSorting: false, cell: ({ row }) => can("inventory.write") && !row.original.reverses_id && !reversed.has(row.original.id) && !row.original.ref_type ? (
        <Button size="xs" variant="ghost" onClick={(e) => { e.stopPropagation(); setReversing(row.original); }}><Undo2 className="size-3" />{t("inventory.reverse")}</Button>
      ) : null,
    },
  ], [t, fmtNumber, fmtDate, can, reversed]);
  return (
    <Card>
      <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2">
        <WarehouseSelect value={f.warehouse_id} onChange={(v) => setF((s) => ({ ...s, warehouse_id: v }))} all />
        <Select value={f.movement_type} onChange={(e) => setF((s) => ({ ...s, movement_type: e.target.value }))} className="w-48" aria-label={t("inventory.movementType")}>
          <option value="">{t("common.all")}</option>{TYPES.map((m) => <option key={m} value={m}>{t(`movement.${m}` as MessageKey)}</option>)}
        </Select>
        <Input type="date" value={f.date_from} onChange={(e) => setF((s) => ({ ...s, date_from: e.target.value }))} className="w-36" aria-label={t("common.from")} />
        <Input type="date" value={f.date_to} onChange={(e) => setF((s) => ({ ...s, date_to: e.target.value }))} className="w-36" aria-label={t("common.to")} />
      </div>
      {query.error ? <ErrorState error={query.error} className="m-4" /> : <DataTable columns={columns} data={rows} loading={query.isLoading} empty={<EmptyState title={t("common.noData")} />} />}
      {query.hasNextPage && <div className="border-t border-border p-2 text-center"><Button size="sm" variant="ghost" loading={query.isFetchingNextPage} onClick={() => query.fetchNextPage()}>{t("common.loadMore")}</Button></div>}
      <ConfirmDialog open={!!reversing} onOpenChange={(o) => !o && setReversing(null)} title={`${t("inventory.reverse")} ${reversing?.number ?? ""}`} body={t("inventory.reverseHint")}
        confirmLabel={t("inventory.reverse")} danger loading={busy === "reverse"} disabled={reason.trim().length < 3}
        onConfirm={async () => {
          if (!reversing) return;
          const ok = await run("reverse", () => api.post(`/inventory/movements/${reversing.id}/reverse`, { reason: reason.trim() }), t("common.saved"));
          if (ok !== undefined) { setReversing(null); setReason(""); }
        }}>
        <Field label={t("common.reason")} required hint={t("common.reasonHint")} className="mt-3">{(id) => <Textarea id={id} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />}</Field>
      </ConfirmDialog>
    </Card>
  );
}

function ShortagesTab() {
  const { t, fmtNumber } = useI18n();
  const company = useCompanyKey();
  const { data, isLoading, error } = useQuery({ queryKey: [company, "inventory", "shortages"], queryFn: () => api.get<Shortage[]>("/inventory/shortages") });
  const columns = useMemo<ColumnDef<Shortage>[]>(() => [
    { header: t("common.code"), accessorKey: "code", cell: ({ getValue }) => <span className="font-medium">{getValue() as string}</span> },
    { header: t("common.name"), accessorKey: "name" },
    { header: t("inventory.onHand"), id: "on", accessorFn: (s) => Number(s.on_hand), meta: { align: "right" }, cell: ({ row }) => `${fmtNumber(row.original.on_hand)} ${row.original.unit}` },
    { header: t("production.reorderLevel"), id: "rl", accessorFn: (s) => Number(s.reorder_level), meta: { align: "right" }, cell: ({ row }) => fmtNumber(row.original.reorder_level) },
    { header: t("inventory.missing"), id: "missing", accessorFn: (s) => toNumber(s.missing), meta: { align: "right" }, cell: ({ row }) => <span className="font-medium text-warning">{fmtNumber(row.original.missing)} {row.original.unit}</span> },
  ], [t, fmtNumber]);
  return (
    <Card>
      {error ? <ErrorState error={error} className="m-4" /> : (
        <DataTable columns={columns} data={data ?? []} loading={isLoading}
          empty={<EmptyState icon={<CheckCircle2 className="size-6 text-success" />} title={t("inventory.noShortages")} />} />
      )}
    </Card>
  );
}

function WarehousesTab() {
  const { t } = useI18n();
  const { can } = useAuth();
  const { data, isLoading, error } = useWarehouses();
  const { run, busy } = useAction("warehouses");
  const [open, setOpen] = useState(false);
  const [v, setV] = useState({ code: "", name: "", kind: "general" });
  const columns = useMemo<ColumnDef<Warehouse>[]>(() => [
    { header: t("common.code"), accessorKey: "code", cell: ({ getValue }) => <span className="font-medium">{getValue() as string}</span> },
    { header: t("common.name"), accessorKey: "name" },
    { header: t("inventory.kind"), accessorKey: "kind", cell: ({ getValue }) => t(`inventory.kind.${getValue() as string}` as MessageKey) },
    { header: t("common.status"), accessorKey: "is_active", cell: ({ getValue }) => (getValue() ? <Badge tone="success">{t("common.active")}</Badge> : <Badge>{t("common.inactive")}</Badge>) },
  ], [t]);
  return (
    <Card>
      {can("inventory.write", "master.write") && (
        <div className="flex justify-end border-b border-border px-4 py-2"><Button size="sm" onClick={() => setOpen(true)}><Plus className="size-3.5" />{t("inventory.newWarehouse")}</Button></div>
      )}
      {error ? <ErrorState error={error} className="m-4" /> : <DataTable columns={columns} data={data ?? []} loading={isLoading} empty={<EmptyState title={t("common.noData")} />} />}
      <Dialog open={open} onOpenChange={setOpen} title={t("inventory.newWarehouse")}
        footer={<><Button onClick={() => setOpen(false)}>{t("common.cancel")}</Button>
          <Button variant="primary" disabled={!v.code.trim() || !v.name.trim()} loading={busy === "create"} onClick={async () => {
            const ok = await run("create", () => api.post("/inventory/warehouses", v), t("common.saved"));
            if (ok !== undefined) { setOpen(false); setV({ code: "", name: "", kind: "general" }); }
          }}>{t("common.create")}</Button></>}>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t("common.code")} required>{(id) => <Input id={id} value={v.code} onChange={(e) => setV({ ...v, code: e.target.value })} />}</Field>
          <Field label={t("common.name")} required>{(id) => <Input id={id} value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />}</Field>
          <Field label={t("inventory.kind")}>{(id) => <Select id={id} value={v.kind} onChange={(e) => setV({ ...v, kind: e.target.value })}>
            {["general", "raw", "finished", "wip"].map((k) => <option key={k} value={k}>{t(`inventory.kind.${k}` as MessageKey)}</option>)}</Select>}</Field>
        </div>
      </Dialog>
    </Card>
  );
}

const TABS = ["balances", "movements", "shortages", "warehouses"] as const;

export default function InventoryPage() {
  const { t } = useI18n();
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const tab = (TABS as readonly string[]).includes(params.get("tab") ?? "") ? params.get("tab")! : "balances";
  const [open, setOpen] = useState(false);
  return (
    <div className="space-y-4">
      <PageHeader title={t("inventory.title")} subtitle={t("inventory.subtitle")}
        actions={can("inventory.write") && <Button size="sm" variant="primary" onClick={() => setOpen(true)}><Plus className="size-3.5" />{t("inventory.newMovement")}</Button>} />
      <Tabs value={tab} onValueChange={(v) => setParams({ tab: v }, { replace: true })}>
        <TabsList className="mb-3">{TABS.map((k) => <TabsTrigger key={k} value={k}>{t(`inventory.tab.${k}` as MessageKey)}</TabsTrigger>)}</TabsList>
        <TabsContent value="balances"><BalancesTab /></TabsContent>
        <TabsContent value="movements"><MovementsTab /></TabsContent>
        <TabsContent value="shortages"><ShortagesTab /></TabsContent>
        <TabsContent value="warehouses"><WarehousesTab /></TabsContent>
      </Tabs>
      {open && <MovementDialog onClose={() => setOpen(false)} />}
    </div>
  );
}
