import { useQuery } from "@tanstack/react-query";
import type { ColumnDef } from "@tanstack/react-table";
import { Plus, Search } from "lucide-react";
import { useMemo, useState } from "react";
import { api, newIdempotencyKey } from "@/api/client";
import { useCategories, useCompanyKey, useUnits } from "@/api/hooks";
import type { Page, Product } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState, ErrorState } from "@/components/ui/feedback";
import { Checkbox, Field, Input, Select } from "@/components/ui/inputs";
import { DataTable } from "@/components/ui/table";
import { useI18n, type MessageKey } from "@/i18n";
import { ifMatch, useAction } from "@/lib/action";

const TYPES = ["finished_good", "semi_finished", "raw_material", "consumable", "packaging", "service"];

function ProductDialog({ product, onClose }: { product: Product | null; onClose: () => void }) {
  const { t } = useI18n();
  const { data: units } = useUnits();
  const { data: categories } = useCategories();
  const { run, busy } = useAction("products");
  const [v, setV] = useState({
    code: product?.code ?? "", name: product?.name ?? "", base_unit: product?.base_unit ?? "pcs", product_type: product?.product_type ?? "finished_good",
    category_id: product?.category_id ?? "", reorder_level: product?.reorder_level != null ? String(product.reorder_level) : "",
    aliases: product?.aliases.join(", ") ?? "", is_active: product?.is_active ?? true,
  });
  const set = (k: keyof typeof v, value: string | boolean) => setV((s) => ({ ...s, [k]: value }));
  const body = {
    name: v.name.trim(), base_unit: v.base_unit, product_type: v.product_type, category_id: v.category_id || null,
    reorder_level: v.reorder_level ? v.reorder_level.replace(",", ".") : null,
    aliases: v.aliases.split(",").map((a) => a.trim()).filter(Boolean),
  };
  const submit = async () => {
    const ok = product
      ? await run("save", () => api.patch(`/master/products/${product.id}`, { ...body, is_active: v.is_active }, ifMatch(product.version)), t("common.saved"))
      : await run("save", () => api.post("/master/products", { ...body, code: v.code.trim() }, { "Idempotency-Key": newIdempotencyKey() }), t("common.saved"));
    if (ok !== undefined) onClose();
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={product ? `${product.code} · ${product.name}` : t("production.newProduct")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button><Button variant="primary" loading={busy === "save"} disabled={!v.name.trim() || (!product && !v.code.trim())} onClick={submit}>{t("common.save")}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("common.code")} required>{(id) => <Input id={id} value={v.code} disabled={!!product} onChange={(e) => set("code", e.target.value)} />}</Field>
        <Field label={t("common.name")} required>{(id) => <Input id={id} value={v.name} onChange={(e) => set("name", e.target.value)} />}</Field>
        <Field label={t("common.type")}>{(id) => <Select id={id} value={v.product_type} onChange={(e) => set("product_type", e.target.value)}>{TYPES.map((p) => <option key={p} value={p}>{t(`ptype.${p}` as MessageKey)}</option>)}</Select>}</Field>
        <Field label={t("production.baseUnit")}>{(id) => <Select id={id} value={v.base_unit} onChange={(e) => set("base_unit", e.target.value)}>{(units ?? []).map((u) => <option key={u.code} value={u.code}>{u.code} · {u.name}</option>)}</Select>}</Field>
        <Field label={t("production.category")}>{(id) => <Select id={id} value={v.category_id} onChange={(e) => set("category_id", e.target.value)}><option value="">—</option>{(categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select>}</Field>
        <Field label={t("production.reorderLevel")}>{(id) => <Input id={id} inputMode="decimal" value={v.reorder_level} onChange={(e) => set("reorder_level", e.target.value)} />}</Field>
        <Field label={t("production.aliases")} hint={t("production.aliasesHint")} className="sm:col-span-2">{(id) => <Input id={id} value={v.aliases} onChange={(e) => set("aliases", e.target.value)} />}</Field>
        {product && <Checkbox checked={v.is_active} onChange={(x) => set("is_active", x)} label={t("common.active")} />}
      </div>
    </Dialog>
  );
}

export function ProductsTab() {
  const { t, fmtNumber } = useI18n();
  const { can } = useAuth();
  const company = useCompanyKey();
  const [q, setQ] = useState("");
  const [type, setType] = useState("");
  const [inactive, setInactive] = useState(false);
  const [editing, setEditing] = useState<Product | "new" | null>(null);
  const { data, isLoading, error } = useQuery({
    queryKey: [company, "products", "list", q, type, inactive],
    queryFn: () => api.get<Page<Product>>("/master/products", { q: q || undefined, product_type: type || undefined, active: !inactive, limit: 500 }),
  });
  const columns = useMemo<ColumnDef<Product>[]>(() => [
    { header: t("common.code"), accessorKey: "code", cell: ({ getValue }) => <span className="font-medium">{getValue() as string}</span> },
    { header: t("common.name"), accessorKey: "name" },
    { header: t("common.type"), accessorKey: "product_type", cell: ({ getValue }) => t(`ptype.${getValue() as string}` as MessageKey) },
    { header: t("common.unit"), accessorKey: "base_unit" },
    { header: t("production.reorderLevel"), accessorKey: "reorder_level", meta: { align: "right" }, cell: ({ getValue }) => fmtNumber(getValue()) },
    { header: t("production.aliases"), accessorKey: "aliases", enableSorting: false, cell: ({ getValue }) => <span className="text-muted">{(getValue() as string[]).join(", ") || "—"}</span> },
    { header: t("common.status"), accessorKey: "is_active", cell: ({ getValue }) => (getValue() ? <Badge tone="success">{t("common.active")}</Badge> : <Badge>{t("common.inactive")}</Badge>) },
  ], [t, fmtNumber]);
  return (
    <Card>
      <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2">
        <div className="relative w-64 max-w-full">
          <Search className="pointer-events-none absolute top-2 left-2 size-4 text-subtle" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t("common.search")} aria-label={t("common.search")} className="pl-7" />
        </div>
        <Select value={type} onChange={(e) => setType(e.target.value)} className="w-44" aria-label={t("common.type")}>
          <option value="">{t("common.all")}</option>{TYPES.map((p) => <option key={p} value={p}>{t(`ptype.${p}` as MessageKey)}</option>)}
        </Select>
        <Checkbox checked={inactive} onChange={setInactive} label={t("common.showInactive")} />
        <span className="flex-1" />
        {can("master.write") && <Button size="sm" onClick={() => setEditing("new")}><Plus className="size-3.5" />{t("production.newProduct")}</Button>}
      </div>
      {error ? <ErrorState error={error} className="m-4" /> : (
        <DataTable columns={columns} data={data?.items ?? []} loading={isLoading} getRowId={(p) => p.id}
          onRowClick={can("master.write") ? (p) => setEditing(p) : undefined} empty={<EmptyState title={t("common.noData")} />} />
      )}
      {editing && <ProductDialog product={editing === "new" ? null : editing} onClose={() => setEditing(null)} />}
    </Card>
  );
}
