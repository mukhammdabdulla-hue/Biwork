import type { ColumnDef } from "@tanstack/react-table";
import { Building2, Check, LogOut, Plus } from "lucide-react";
import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { api } from "@/api/client";
import type { CompanyMembership } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState, Notice } from "@/components/ui/feedback";
import { Field, Input } from "@/components/ui/inputs";
import { DataTable } from "@/components/ui/table";
import { useI18n } from "@/i18n";
import { roleName } from "@/i18n/labels";
import { useAction } from "@/lib/action";

/** Creates a company (platform administrators only; the creator becomes its administrator) and switches into it. */
export function NewCompanyDialog({ onClose }: { onClose: () => void }) {
  const { t } = useI18n();
  const { reloadUser } = useAuth();
  const navigate = useNavigate();
  const { run, busy } = useAction("companies");
  const [v, setV] = useState({ name: "", code: "", tin: "" });
  const submit = async () => {
    const created = await run("create", () => api.post<{ id: string }>("/companies", { name: v.name.trim(), code: v.code.trim(), tin: v.tin.trim() || null }),
      t("company.created", { name: v.name.trim() }));
    if (!created) return;
    onClose();
    await reloadUser(created.id);
    navigate("/");
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={t("company.new")} description={t("company.newHint")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button>
        <Button variant="primary" loading={busy === "create"} disabled={!v.name.trim() || !v.code.trim()} onClick={submit}>{t("common.create")}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("common.name")} required className="sm:col-span-2">{(id) => <Input id={id} value={v.name} maxLength={200} onChange={(e) => setV({ ...v, name: e.target.value })} />}</Field>
        <Field label={t("common.code")} required hint={t("company.codeHint")}>{(id) => <Input id={id} value={v.code} maxLength={50} onChange={(e) => setV({ ...v, code: e.target.value })} />}</Field>
        <Field label={t("supply.tin")}>{(id) => <Input id={id} value={v.tin} maxLength={32} inputMode="numeric" onChange={(e) => setV({ ...v, tin: e.target.value })} />}</Field>
      </div>
    </Dialog>
  );
}

/** Shown instead of the app when the account has no active company: a way out, and a way in for platform administrators. */
export function NoCompany() {
  const { t } = useI18n();
  const { user, logout } = useAuth();
  const [creating, setCreating] = useState(false);
  return (
    <EmptyState title="BIWORK" icon={<Building2 className="size-6" />} className="h-full">
      <p>{t(user?.is_platform_admin ? "company.noneAdmin" : "auth.noCompany")}</p>
      {user && <p className="mt-1 text-subtle">{user.email}</p>}
      <div className="mt-4 flex flex-wrap justify-center gap-2">
        {user?.is_platform_admin && <Button variant="primary" onClick={() => setCreating(true)}><Plus className="size-3.5" />{t("company.new")}</Button>}
        <Button onClick={() => void logout()}><LogOut className="size-3.5" />{t("top.signOut")}</Button>
      </div>
      {creating && <NewCompanyDialog onClose={() => setCreating(false)} />}
    </EmptyState>
  );
}

/** Administration › Companies: the companies the platform administrator belongs to, and creating new ones. */
export function CompaniesTab() {
  const { t } = useI18n();
  const { user, company, switchCompany } = useAuth();
  const navigate = useNavigate();
  const [creating, setCreating] = useState(false);
  const columns = useMemo<ColumnDef<CompanyMembership>[]>(() => [
    { header: t("common.name"), accessorKey: "name", cell: ({ row }) => <span className="inline-flex items-center gap-1.5 font-medium">{row.original.name}
      {row.original.is_sample_data && <Badge tone="violet">{t("common.sampleData")}</Badge>}</span> },
    { header: t("common.code"), accessorKey: "code" },
    { header: t("company.yourRoles"), id: "roles", accessorFn: (c) => c.roles.map((r) => roleName(t, r)).join(", ") },
    {
      id: "actions", header: "", enableSorting: false, cell: ({ row }) => row.original.id === company?.id
        ? <Badge tone="success"><Check className="size-3" />{t("company.current")}</Badge>
        : <Button size="xs" variant="ghost" onClick={() => { switchCompany(row.original.id); navigate("/"); }}>{t("company.switch")}</Button>,
    },
  ], [t, company, switchCompany, navigate]);
  return (
    <Card>
      <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2">
        <Notice tone="info" className="flex-1">{t("company.listHint")}</Notice>
        <Button size="sm" onClick={() => setCreating(true)}><Plus className="size-3.5" />{t("company.new")}</Button>
      </div>
      <DataTable columns={columns} data={user?.companies ?? []} getRowId={(c) => c.id} empty={<EmptyState title={t("common.noData")} />} />
      {creating && <NewCompanyDialog onClose={() => setCreating(false)} />}
    </Card>
  );
}
