import { useQuery } from "@tanstack/react-query";
import type { ColumnDef } from "@tanstack/react-table";
import { Bot, Download, Plus, Trash2 } from "lucide-react";
import { useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { api, newIdempotencyKey } from "@/api/client";
import { useCompanyKey, useDepartments, useProducts, useSuppliers, useUnits } from "@/api/hooks";
import type { Page, PurchaseRequest, Supplier } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog, Sheet } from "@/components/ui/dialog";
import { EmptyState, ErrorState } from "@/components/ui/feedback";
import { Field, Input, Select, Textarea } from "@/components/ui/inputs";
import { DefinitionList, PageHeader, SectionTitle } from "@/components/ui/page";
import { PriorityBadge, StatusBadge } from "@/components/ui/status";
import { DataTable } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useI18n, type MessageKey } from "@/i18n";
import { ifMatch, todayIso, useAction } from "@/lib/action";
import { toastError } from "@/lib/notify";

const PR_STATUSES = ["submitted", "approved", "ordered", "closed", "rejected", "cancelled"];
const NEXT: Record<string, { status: string; key: MessageKey; perm: string; variant: "success" | "secondary" | "ghost" }[]> = {
  submitted: [
    { status: "approved", key: "supply.approveRequest", perm: "supply.approve", variant: "success" },
    { status: "rejected", key: "supply.rejectRequest", perm: "supply.approve", variant: "ghost" },
    { status: "cancelled", key: "common.cancelRequest", perm: "supply.write", variant: "ghost" },
  ],
  approved: [
    { status: "ordered", key: "supply.markOrdered", perm: "supply.write", variant: "secondary" },
    { status: "cancelled", key: "common.cancelRequest", perm: "supply.write", variant: "ghost" },
  ],
  ordered: [{ status: "closed", key: "supply.close", perm: "supply.write", variant: "secondary" }],
};

function RequestSheet({ id, onClose }: { id: string; onClose: () => void }) {
  const { t, fmtNumber, fmtDate, fmtDateTime } = useI18n();
  const { can } = useAuth();
  const company = useCompanyKey();
  const { run, busy } = useAction("supply");
  const [comment, setComment] = useState("");
  const { data: pr, error } = useQuery({ queryKey: [company, "supply", "pr", id], queryFn: () => api.get<PurchaseRequest>(`/supply/purchase-requests/${id}`) });
  const actions = (pr ? NEXT[pr.status] ?? [] : []).filter((a) => can(a.perm));
  return (
    <Sheet open onOpenChange={(o) => !o && onClose()} width="max-w-2xl" title={pr ? pr.number : "…"} description={pr && `${fmtDate(pr.request_date)} · ${pr.department?.name ?? "—"}`}
      footer={pr && <>
        {can("audit.read") && <Link className="mr-auto self-center text-xs text-primary hover:underline" to={`/trace/purchase_request/${pr.id}`}>{t("common.trace")}</Link>}
        {actions.map((a) => (
          <Button key={a.status} variant={a.variant} className={a.variant === "ghost" ? "text-danger" : undefined} loading={busy === a.status}
            onClick={async () => {
              const ok = await run(a.status, () => api.post(`/supply/purchase-requests/${pr.id}/status`, { status: a.status, comment: comment || null }, ifMatch(pr.version)), t("common.saved"));
              if (ok !== undefined) setComment("");
            }}>{t(a.key)}</Button>
        ))}
      </>}>
      {error && <ErrorState error={error} />}
      {pr && (
        <div className="space-y-4">
          <div className="flex flex-wrap gap-2"><StatusBadge status={pr.status} /><PriorityBadge priority={pr.priority} />
            {pr.source_record_id && <Badge tone="brand"><Bot className="size-3" />{t("production.fromAgent")}</Badge>}</div>
          <DefinitionList items={[
            [t("supply.requestedBy"), pr.requested_by],
            [t("supply.neededBy"), fmtDate(pr.needed_by)],
            [t("supply.supplier"), pr.supplier?.name],
            [t("production.externalNumber"), pr.external_number],
            [t("supply.totalEstimated"), pr.total_estimated != null ? `${fmtNumber(pr.total_estimated)} ${pr.currency ?? ""}` : "—"],
            [t("common.notes"), pr.notes],
            [t("supply.decision"), pr.decision_comment ? `${pr.decision_comment} (${fmtDateTime(pr.decided_at)})` : pr.decided_at ? fmtDateTime(pr.decided_at) : "—"],
          ]} />
          {pr.source_file_id && (
            <Button size="sm" onClick={() => api.download(`/files/${pr.source_file_id}`, `${pr.number}-source`).catch((e) => toastError(e, t))}>
              <Download className="size-3.5" />{t("supply.sourceDocument")}
            </Button>
          )}
          <section>
            <SectionTitle>{t("supply.lines")}</SectionTitle>
            <table className="w-full text-xs">
              <thead className="text-left text-muted"><tr className="border-b border-border">
                <th className="py-1.5 pr-2 font-medium">#</th><th className="py-1.5 pr-2 font-medium">{t("supply.item")}</th>
                <th className="py-1.5 pr-2 text-right font-medium">{t("common.quantity")}</th><th className="py-1.5 text-right font-medium">{t("supply.price")}</th>
              </tr></thead>
              <tbody>{pr.lines.map((l) => (
                <tr key={l.line_no} className="border-b border-border last:border-0">
                  <td className="py-1.5 pr-2 text-subtle">{l.line_no}</td>
                  <td className="py-1.5 pr-2">{l.description}{l.product && <span className="ml-1 text-muted">({l.product.code})</span>}</td>
                  <td className="py-1.5 pr-2 text-right tabular">{fmtNumber(l.qty)} {l.unit}</td>
                  <td className="py-1.5 text-right tabular">{fmtNumber(l.est_price)}</td>
                </tr>
              ))}</tbody>
            </table>
          </section>
          {actions.length > 0 && <Field label={t("supply.decision")}>{(fid) => <Textarea id={fid} rows={2} value={comment} onChange={(e) => setComment(e.target.value)} />}</Field>}
        </div>
      )}
    </Sheet>
  );
}

interface LineDraft { description: string; quantity: string; unit: string; product_id: string; estimated_unit_price: string }
const emptyLine = (): LineDraft => ({ description: "", quantity: "", unit: "pcs", product_id: "", estimated_unit_price: "" });

function NewRequestDialog({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const { data: departments } = useDepartments();
  const { data: suppliers } = useSuppliers();
  const { data: products } = useProducts();
  const { data: units } = useUnits();
  const { run, busy } = useAction("supply");
  const [v, setV] = useState({ request_date: todayIso(), needed_by: "", department_id: "", requested_by: "", supplier_id: "", priority: "normal", currency: "UZS", notes: "" });
  const [lines, setLines] = useState<LineDraft[]>([emptyLine()]);
  const setLine = (i: number, patch: Partial<LineDraft>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)));
  const valid = lines.length > 0 && lines.every((l) => l.description.trim() && Number(l.quantity.replace(",", ".")) > 0 && l.unit);
  const submit = async () => {
    const ok = await run("create", () => api.post("/supply/purchase-requests", {
      ...v, needed_by: v.needed_by || null, department_id: v.department_id || null, requested_by: v.requested_by || null, supplier_id: v.supplier_id || null, notes: v.notes || null,
      lines: lines.map((l) => ({ description: l.description.trim(), quantity: l.quantity.replace(",", "."), unit: l.unit, product_id: l.product_id || null,
        estimated_unit_price: l.estimated_unit_price ? l.estimated_unit_price.replace(",", ".") : null })),
    }, { "Idempotency-Key": newIdempotencyKey() }), t("common.saved"));
    if (ok !== undefined) onClose();
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} wide title={t("supply.newRequest")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button><Button variant="primary" disabled={!valid} loading={busy === "create"} onClick={submit}>{t("common.submit")}</Button></>}>
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t("supply.requestDate")} required>{(id) => <Input id={id} type="date" value={v.request_date} onChange={(e) => setV({ ...v, request_date: e.target.value })} />}</Field>
          <Field label={t("supply.neededBy")}>{(id) => <Input id={id} type="date" value={v.needed_by} onChange={(e) => setV({ ...v, needed_by: e.target.value })} />}</Field>
          <Field label={t("common.priority")}>{(id) => <Select id={id} value={v.priority} onChange={(e) => setV({ ...v, priority: e.target.value })}>{["low", "normal", "high", "urgent"].map((p) => <option key={p} value={p}>{t(`priority.${p}` as MessageKey)}</option>)}</Select>}</Field>
          <Field label={t("common.department")}>{(id) => <Select id={id} value={v.department_id} onChange={(e) => setV({ ...v, department_id: e.target.value })}><option value="">—</option>{(departments ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</Select>}</Field>
          <Field label={t("supply.supplier")}>{(id) => <Select id={id} value={v.supplier_id} onChange={(e) => setV({ ...v, supplier_id: e.target.value })}><option value="">—</option>{(suppliers ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select>}</Field>
          <Field label={t("supply.requestedBy")}>{(id) => <Input id={id} value={v.requested_by} onChange={(e) => setV({ ...v, requested_by: e.target.value })} />}</Field>
        </div>
        <section>
          <SectionTitle actions={<Button size="xs" variant="ghost" onClick={() => setLines([...lines, emptyLine()])}><Plus className="size-3" />{t("agent.record.addLine")}</Button>}>{t("supply.lines")}</SectionTitle>
          <div className="space-y-2">
            {lines.map((l, i) => (
              <div key={i} className="grid items-end gap-2 rounded-md border border-border p-2 sm:grid-cols-[2fr_1.4fr_0.8fr_0.8fr_0.9fr_auto]">
                <Field label={t("supply.item")} required>{(id) => <Input id={id} value={l.description} onChange={(e) => setLine(i, { description: e.target.value })} />}</Field>
                <Field label={t("common.product")}>{(id) => <Select id={id} value={l.product_id} onChange={(e) => {
                  const p = products?.find((x) => x.id === e.target.value);
                  setLine(i, { product_id: e.target.value, unit: p?.base_unit ?? l.unit, description: l.description || p?.name || "" });
                }}><option value="">—</option>{(products ?? []).map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}</Select>}</Field>
                <Field label={t("common.quantity")} required>{(id) => <Input id={id} inputMode="decimal" value={l.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} />}</Field>
                <Field label={t("common.unit")}>{(id) => <Select id={id} value={l.unit} onChange={(e) => setLine(i, { unit: e.target.value })}>{(units ?? []).map((u) => <option key={u.code} value={u.code}>{u.code}</option>)}</Select>}</Field>
                <Field label={t("supply.price")}>{(id) => <Input id={id} inputMode="decimal" value={l.estimated_unit_price} onChange={(e) => setLine(i, { estimated_unit_price: e.target.value })} />}</Field>
                <Button variant="ghost" size="icon" disabled={lines.length === 1} onClick={() => setLines(lines.filter((_, j) => j !== i))} aria-label={t("agent.record.removeLine")}><Trash2 className="size-3.5" /></Button>
              </div>
            ))}
          </div>
        </section>
        <Field label={t("common.notes")}>{(id) => <Textarea id={id} rows={2} value={v.notes} onChange={(e) => setV({ ...v, notes: e.target.value })} />}</Field>
      </div>
    </Dialog>
  );
}

function RequestsTab() {
  const { t, fmtNumber, fmtDate } = useI18n();
  const company = useCompanyKey();
  const [params, setParams] = useSearchParams();
  const [status, setStatus] = useState(params.get("status") ?? "");
  const selected = params.get("request");
  const open = (id: string | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set("request", id);
    else next.delete("request");
    setParams(next, { replace: true });
  };
  const { data, isLoading, error } = useQuery({
    queryKey: [company, "supply", "prs", status],
    queryFn: () => api.get<Page<PurchaseRequest>>("/supply/purchase-requests", { status: status || undefined, limit: 500 }),
  });
  const columns = useMemo<ColumnDef<PurchaseRequest>[]>(() => [
    { header: t("common.number"), accessorKey: "number", cell: ({ row }) => <span className="font-medium">{row.original.number}</span> },
    { header: t("supply.requestDate"), accessorKey: "request_date", cell: ({ getValue }) => fmtDate(getValue() as string) },
    { header: t("common.department"), id: "dep", accessorFn: (p) => p.department?.name ?? "—" },
    { header: t("supply.lines"), id: "lines", accessorFn: (p) => p.lines.length, meta: { align: "right" }, cell: ({ row }) => <span title={row.original.lines.map((l) => l.description).join(", ")}>{row.original.lines.length}</span> },
    { header: t("supply.totalEstimated"), id: "total", accessorFn: (p) => Number(p.total_estimated ?? 0), meta: { align: "right" }, cell: ({ row }) => row.original.total_estimated != null ? `${fmtNumber(row.original.total_estimated)} ${row.original.currency ?? ""}` : "—" },
    { header: t("supply.neededBy"), accessorKey: "needed_by", cell: ({ getValue }) => fmtDate(getValue() as string) },
    { header: t("common.priority"), accessorKey: "priority", cell: ({ getValue }) => <PriorityBadge priority={getValue() as string} /> },
    { header: t("common.status"), accessorKey: "status", cell: ({ row }) => <span className="inline-flex gap-1"><StatusBadge status={row.original.status} />{row.original.source_record_id && <Bot className="size-3.5 text-brand" aria-label={t("production.fromAgent")} />}</span> },
  ], [t, fmtNumber, fmtDate]);
  return (
    <Card>
      <div className="flex items-center gap-2 border-b border-border px-4 py-2">
        <Select value={status} onChange={(e) => setStatus(e.target.value)} className="w-44" aria-label={t("common.status")}>
          <option value="">{t("agent.history.allStatuses")}</option>{PR_STATUSES.map((s) => <option key={s} value={s}>{t(`status.${s}` as MessageKey)}</option>)}
        </Select>
      </div>
      {error ? <ErrorState error={error} className="m-4" /> : (
        <DataTable columns={columns} data={data?.items ?? []} loading={isLoading} onRowClick={(p) => open(p.id)} getRowId={(p) => p.id} selectedId={selected}
          empty={<EmptyState title={t("common.noData")} />} />
      )}
      {selected && <RequestSheet id={selected} onClose={() => open(null)} />}
    </Card>
  );
}

function SuppliersTab() {
  const { t } = useI18n();
  const { can } = useAuth();
  const { data, isLoading, error } = useSuppliers();
  const { run, busy } = useAction("suppliers");
  const [open, setOpen] = useState(false);
  const blank = { code: "", name: "", tin: "", contact_person: "", phone: "", email: "", address: "", aliases: "" };
  const [v, setV] = useState(blank);
  const columns = useMemo<ColumnDef<Supplier>[]>(() => [
    { header: t("common.code"), accessorKey: "code", cell: ({ getValue }) => <span className="font-medium">{getValue() as string}</span> },
    { header: t("common.name"), accessorKey: "name" },
    { header: t("supply.tin"), accessorKey: "tin", cell: ({ getValue }) => (getValue() as string) ?? "—" },
    { header: t("supply.contact"), accessorKey: "contact_person", cell: ({ getValue }) => (getValue() as string) ?? "—" },
    { header: t("supply.phone"), accessorKey: "phone", cell: ({ getValue }) => (getValue() as string) ?? "—" },
    { header: t("supply.email"), accessorKey: "email", cell: ({ getValue }) => (getValue() as string) ?? "—" },
  ], [t]);
  const fields: [keyof typeof blank, MessageKey, boolean][] = [["code", "common.code", true], ["name", "common.name", true], ["tin", "supply.tin", false],
    ["contact_person", "supply.contact", false], ["phone", "supply.phone", false], ["email", "supply.email", false], ["address", "supply.address", false], ["aliases", "production.aliases", false]];
  return (
    <Card>
      {can("supply.write") && <div className="flex justify-end border-b border-border px-4 py-2"><Button size="sm" onClick={() => setOpen(true)}><Plus className="size-3.5" />{t("supply.newSupplier")}</Button></div>}
      {error ? <ErrorState error={error} className="m-4" /> : <DataTable columns={columns} data={data ?? []} loading={isLoading} empty={<EmptyState title={t("common.noData")} />} />}
      <Dialog open={open} onOpenChange={setOpen} title={t("supply.newSupplier")}
        footer={<><Button onClick={() => setOpen(false)}>{t("common.cancel")}</Button>
          <Button variant="primary" disabled={!v.code.trim() || !v.name.trim()} loading={busy === "create"} onClick={async () => {
            const body = Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x.trim() || null]));
            const ok = await run("create", () => api.post("/supply/suppliers", { ...body, aliases: v.aliases.split(",").map((a) => a.trim()).filter(Boolean) }), t("common.saved"));
            if (ok !== undefined) { setOpen(false); setV(blank); }
          }}>{t("common.create")}</Button></>}>
        <div className="grid gap-3 sm:grid-cols-2">
          {fields.map(([k, key, req]) => (
            <Field key={k} label={t(key)} required={req} hint={k === "aliases" ? t("production.aliasesHint") : undefined} className={k === "address" || k === "aliases" ? "sm:col-span-2" : undefined}>
              {(id) => <Input id={id} value={v[k]} onChange={(e) => setV({ ...v, [k]: e.target.value })} />}
            </Field>
          ))}
        </div>
      </Dialog>
    </Card>
  );
}

export default function SupplyPage() {
  const { t } = useI18n();
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const tab = params.get("tab") === "suppliers" ? "suppliers" : "requests";
  const [open, setOpen] = useState(false);
  return (
    <div className="space-y-4">
      <PageHeader title={t("supply.title")} subtitle={t("supply.subtitle")}
        actions={can("supply.write") && <Button size="sm" variant="primary" onClick={() => setOpen(true)}><Plus className="size-3.5" />{t("supply.newRequest")}</Button>} />
      <Tabs value={tab} onValueChange={(v) => setParams({ tab: v }, { replace: true })}>
        <TabsList className="mb-3">
          <TabsTrigger value="requests">{t("supply.tab.requests")}</TabsTrigger>
          <TabsTrigger value="suppliers">{t("supply.tab.suppliers")}</TabsTrigger>
        </TabsList>
        <TabsContent value="requests"><RequestsTab /></TabsContent>
        <TabsContent value="suppliers"><SuppliersTab /></TabsContent>
      </Tabs>
      {open && <NewRequestDialog onClose={() => setOpen(false)} />}
    </div>
  );
}
