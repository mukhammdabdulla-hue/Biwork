import type { ColumnDef } from "@tanstack/react-table";
import { Plus } from "lucide-react";
import { useMemo, useState } from "react";
import { api } from "@/api/client";
import { useShifts } from "@/api/hooks";
import type { Shift } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState, ErrorState } from "@/components/ui/feedback";
import { Field, Input } from "@/components/ui/inputs";
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
export function ShiftsTab() {
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
      {can("production.write", "master.write") && (
        <div className="flex items-center justify-end border-b border-border px-4 py-2">
          <Button size="sm" onClick={() => setCreating(true)}><Plus className="size-3.5" />{t("production.newShift")}</Button>
        </div>
      )}
      {error ? <ErrorState error={error} className="m-4" /> : (
        <DataTable columns={columns} data={data ?? []} loading={isLoading} getRowId={(s) => s.id}
          empty={<EmptyState title={t("production.noShifts")} />} />
      )}
      {creating && <ShiftDialog onClose={() => setCreating(false)} />}
    </Card>
  );
}
