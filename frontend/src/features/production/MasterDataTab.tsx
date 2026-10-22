import { useQuery } from "@tanstack/react-query";
import type { ColumnDef } from "@tanstack/react-table";
import { Plus } from "lucide-react";
import { useMemo, useState } from "react";
import { api } from "@/api/client";
import { useCategories, useCompanyKey, useDepartments, useShifts, useUnits } from "@/api/hooks";
import type { Category, Line, Shift } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState, ErrorState } from "@/components/ui/feedback";
import { Field, Input, Select } from "@/components/ui/inputs";
import { DataTable } from "@/components/ui/table";
import { useI18n } from "@/i18n";
import { useAction } from "@/lib/action";

function ShiftDialog({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const { run, busy } = useAction("shifts");
  const [v, setV] = useState({ code: "", name: "", starts_at: "", ends_at: "" });
  const set = (k: keyof typeof v, value: string) => setV((s) => ({ ...s, [k]: value }));
  const submit = async () => {
    const ok = await run("save", () => api.post("/production/shifts", {
      code: v.code.trim(), name: v.name.trim(), starts_at: v.starts_at || null, ends_at: v.ends_at || null,
    }), t("common.saved"));
    if (ok !== undefined) onClose();
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={t("production.newShift")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button><Button variant="primary" loading={busy === "save"} disabled={!v.code.trim() || !v.name.trim()} onClick={submit}>{t("common.save")}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("common.code")} required hint={t("production.shiftCodeHint")}>{(id) => <Input id={id} value={v.code} maxLength={20} onChange={(e) => set("code", e.target.value)} />}</Field>
        <Field label={t("common.name")} required>{(id) => <Input id={id} value={v.name} maxLength={100} onChange={(e) => set("name", e.target.value)} />}</Field>
        <Field label={t("production.shiftStart")}>{(id) => <Input id={id} type="time" value={v.starts_at} onChange={(e) => set("starts_at", e.target.value)} />}</Field>
        <Field label={t("production.shiftEnd")}>{(id) => <Input id={id} type="time" value={v.ends_at} onChange={(e) => set("ends_at", e.target.value)} />}</Field>
      </div>
    </Dialog>
  );
}

/** Shifts that reports (and BIWORK Agent) refer to; documents name them by code, e.g. "1-smena". */
function ShiftsCard() {
  const { t } = useI18n();
  const { can } = useAuth();
  const { data, isLoading, error } = useShifts();
  const [creating, setCreating] = useState(false);
  const columns = useMemo<ColumnDef<Shift>[]>(() => [
    { header: t("common.code"), accessorKey: "code", cell: ({ getValue }) => <span className="font-medium">{getValue() as string}</span> },
    { header: t("common.name"), accessorKey: "name" },
    { header: t("production.shiftStart"), accessorKey: "starts_at", cell: ({ getValue }) => (getValue() as string | null)?.slice(0, 5) ?? "—" },
    { header: t("production.shiftEnd"), accessorKey: "ends_at", cell: ({ getValue }) => (getValue() as string | null)?.slice(0, 5) ?? "—" },
    { header: t("common.status"), accessorKey: "is_active", cell: ({ getValue }) => (getValue() ? <Badge tone="success">{t("common.active")}</Badge> : <Badge>{t("common.inactive")}</Badge>) },
  ], [t]);
  return (
    <Card>
      <CardHeader title={t("production.tab.shifts")}
        actions={can("production.write", "master.write") && <Button size="sm" onClick={() => setCreating(true)}><Plus className="size-3.5" />{t("production.newShift")}</Button>} />
      {error ? <ErrorState error={error} className="m-4" /> : (
        <DataTable columns={columns} data={data ?? []} loading={isLoading} getRowId={(s) => s.id}
          empty={<EmptyState title={t("production.noShifts")} />} />
      )}
      {creating && <ShiftDialog onClose={() => setCreating(false)} />}
    </Card>
  );
}

function CategoryDialog({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const { data: categories } = useCategories();
  const { run, busy } = useAction("categories");
  const [v, setV] = useState({ code: "", name: "", parent_id: "" });
  const submit = async () => {
    const ok = await run("save", () => api.post("/master/product-categories", { code: v.code.trim(), name: v.name.trim(), parent_id: v.parent_id || null }), t("common.saved"));
    if (ok !== undefined) onClose();
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={t("production.newCategory")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button><Button variant="primary" loading={busy === "save"} disabled={!v.code.trim() || !v.name.trim()} onClick={submit}>{t("common.save")}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("common.code")} required>{(id) => <Input id={id} value={v.code} maxLength={50} onChange={(e) => setV({ ...v, code: e.target.value })} />}</Field>
        <Field label={t("common.name")} required>{(id) => <Input id={id} value={v.name} maxLength={200} onChange={(e) => setV({ ...v, name: e.target.value })} />}</Field>
        <Field label={t("production.parentCategory")} className="sm:col-span-2">
          {(id) => <Select id={id} value={v.parent_id} onChange={(e) => setV({ ...v, parent_id: e.target.value })}><option value="">—</option>
            {(categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}</Select>}
        </Field>
      </div>
    </Dialog>
  );
}

/** Categories group the product catalog (Products › Category). */
function CategoriesCard() {
  const { t } = useI18n();
  const { can } = useAuth();
  const { data, isLoading, error } = useCategories();
  const [creating, setCreating] = useState(false);
  const names = useMemo(() => new Map((data ?? []).map((c) => [c.id, c.name])), [data]);
  const columns = useMemo<ColumnDef<Category>[]>(() => [
    { header: t("common.code"), accessorKey: "code", cell: ({ getValue }) => <span className="font-medium">{getValue() as string}</span> },
    { header: t("common.name"), accessorKey: "name" },
    { header: t("production.parentCategory"), id: "parent", accessorFn: (c) => (c.parent_id ? names.get(c.parent_id) ?? "—" : "—") },
  ], [t, names]);
  return (
    <Card>
      <CardHeader title={t("production.categories")}
        actions={can("master.write") && <Button size="sm" onClick={() => setCreating(true)}><Plus className="size-3.5" />{t("production.newCategory")}</Button>} />
      {error ? <ErrorState error={error} className="m-4" /> : (
        <DataTable columns={columns} data={data ?? []} loading={isLoading} getRowId={(c) => c.id}
          empty={<EmptyState title={t("production.noCategories")} />} />
      )}
      {creating && <CategoryDialog onClose={() => setCreating(false)} />}
    </Card>
  );
}

function LineDialog({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const { data: departments } = useDepartments();
  const { data: units } = useUnits();
  const { run, busy } = useAction("lines");
  const [v, setV] = useState({ code: "", name: "", department_id: "", capacity_per_shift: "", unit: "" });
  const set = (k: keyof typeof v, value: string) => setV((s) => ({ ...s, [k]: value }));
  const capacity = v.capacity_per_shift.trim().replace(",", ".");
  const capacityValid = !capacity || Number(capacity) >= 0;
  const submit = async () => {
    const ok = await run("save", () => api.post("/production/lines", {
      code: v.code.trim(), name: v.name.trim(), department_id: v.department_id, capacity_per_shift: capacity || null, unit: v.unit || null,
    }), t("common.saved"));
    if (ok !== undefined) onClose();
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={t("production.newLine")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button>
        <Button variant="primary" loading={busy === "save"} disabled={!v.code.trim() || !v.name.trim() || !v.department_id || !capacityValid} onClick={submit}>{t("common.save")}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("common.code")} required>{(id) => <Input id={id} value={v.code} maxLength={50} onChange={(e) => set("code", e.target.value)} />}</Field>
        <Field label={t("common.name")} required>{(id) => <Input id={id} value={v.name} maxLength={200} onChange={(e) => set("name", e.target.value)} />}</Field>
        <Field label={t("common.department")} required className="sm:col-span-2">
          {(id) => <Select id={id} value={v.department_id} onChange={(e) => set("department_id", e.target.value)}><option value="">—</option>
            {(departments ?? []).filter((d) => d.kind === "workshop").map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</Select>}
        </Field>
        <Field label={t("production.capacityPerShift")} error={capacityValid ? undefined : t("common.invalidValue")}>
          {(id) => <Input id={id} inputMode="decimal" value={v.capacity_per_shift} onChange={(e) => set("capacity_per_shift", e.target.value)} />}
        </Field>
        <Field label={t("common.unit")}>
          {(id) => <Select id={id} value={v.unit} onChange={(e) => set("unit", e.target.value)}><option value="">—</option>
            {(units ?? []).map((u) => <option key={u.code} value={u.code}>{u.code} · {u.name}</option>)}</Select>}
        </Field>
      </div>
    </Dialog>
  );
}

/** Production lines inside a workshop, with their capacity per shift. */
function LinesCard() {
  const { t, fmtNumber } = useI18n();
  const { can } = useAuth();
  const company = useCompanyKey();
  const { data: departments } = useDepartments();
  const { data, isLoading, error } = useQuery({ queryKey: [company, "lines"], queryFn: () => api.get<Line[]>("/production/lines") });
  const [creating, setCreating] = useState(false);
  const depName = useMemo(() => new Map((departments ?? []).map((d) => [d.id, d.name])), [departments]);
  const columns = useMemo<ColumnDef<Line>[]>(() => [
    { header: t("common.code"), accessorKey: "code", cell: ({ getValue }) => <span className="font-medium">{getValue() as string}</span> },
    { header: t("common.name"), accessorKey: "name" },
    { header: t("common.department"), id: "dep", accessorFn: (l) => depName.get(l.department_id) ?? "—" },
    { header: t("production.capacityPerShift"), id: "capacity", accessorFn: (l) => Number(l.capacity_per_shift ?? 0), meta: { align: "right" }, cell: ({ row }) => fmtNumber(row.original.capacity_per_shift) },
    { header: t("common.status"), accessorKey: "is_active", cell: ({ getValue }) => (getValue() ? <Badge tone="success">{t("common.active")}</Badge> : <Badge>{t("common.inactive")}</Badge>) },
  ], [t, fmtNumber, depName]);
  return (
    <Card>
      <CardHeader title={t("production.lines")}
        actions={can("production.write", "master.write") && <Button size="sm" onClick={() => setCreating(true)}><Plus className="size-3.5" />{t("production.newLine")}</Button>} />
      {error ? <ErrorState error={error} className="m-4" /> : (
        <DataTable columns={columns} data={data ?? []} loading={isLoading} getRowId={(l) => l.id}
          empty={<EmptyState title={t("production.noLines")} />} />
      )}
      {creating && <LineDialog onClose={() => setCreating(false)} />}
    </Card>
  );
}

/** Reference data a new company sets up before reporting: product categories, shifts and production lines. */
export function MasterDataTab() {
  const { can } = useAuth();
  return (
    <div className="grid gap-3 xl:grid-cols-2">
      {can("master.read") && <CategoriesCard />}
      <ShiftsCard />
      <div className="min-w-0 xl:col-span-2"><LinesCard /></div>
    </div>
  );
}
