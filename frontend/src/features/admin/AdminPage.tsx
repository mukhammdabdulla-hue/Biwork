import { useQuery } from "@tanstack/react-query";
import type { ColumnDef } from "@tanstack/react-table";
import { Copy, Plus, ShieldCheck } from "lucide-react";
import { useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { api } from "@/api/client";
import { useCompanyKey, useDepartments } from "@/api/hooks";
import type { ApiClient, Department, Member, Role, Workflow } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ConfirmDialog, Dialog } from "@/components/ui/dialog";
import { EmptyState, ErrorState, Notice } from "@/components/ui/feedback";
import { Checkbox, Field, Input, Select, Textarea } from "@/components/ui/inputs";
import { PageHeader } from "@/components/ui/page";
import { Switch } from "@/components/ui/switch";
import { DataTable } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useI18n, type MessageKey } from "@/i18n";
import { moduleName, recordTypeLabel, roleDescription, roleName } from "@/i18n/labels";
import { useAction } from "@/lib/action";
import { AuditTab, SystemTab } from "./AuditSystem";
import { CompaniesTab } from "./Companies";

function useRoles() {
  const company = useCompanyKey();
  return useQuery({ queryKey: [company, "admin", "roles"], queryFn: () => api.get<Role[]>("/roles") });
}

function MemberDialog({ member, onClose }: { member: Member | null; onClose: () => void }) {
  const { t } = useI18n();
  const { data: roles } = useRoles();
  const { data: departments } = useDepartments();
  const { run, busy } = useAction("admin");
  const [v, setV] = useState({ email: member?.email ?? "", full_name: member?.full_name ?? "", role: member?.role.code ?? "operator", password: "", department_ids: member?.department_ids ?? [] });
  const toggleDep = (id: string, on: boolean) => setV((s) => ({ ...s, department_ids: on ? [...s.department_ids, id] : s.department_ids.filter((d) => d !== id) }));
  const submit = async () => {
    const ok = member
      ? await run("save", () => api.patch(`/members/${member.id}`, { role: v.role, department_ids: v.department_ids }), t("common.saved"))
      : await run("save", () => api.post("/members", { ...v, password: v.password || null }), t("common.saved"));
    if (ok !== undefined) onClose();
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={member ? member.full_name : t("admin.addMember")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button><Button variant="primary" loading={busy === "save"} disabled={!member && (!v.email.trim() || !v.full_name.trim())} onClick={submit}>{t("common.save")}</Button></>}>
      <div className="grid gap-3">
        {!member && <>
          <Field label={t("auth.email")} required>{(id) => <Input id={id} type="email" value={v.email} onChange={(e) => setV({ ...v, email: e.target.value })} />}</Field>
          <Field label={t("admin.fullName")} required>{(id) => <Input id={id} value={v.full_name} onChange={(e) => setV({ ...v, full_name: e.target.value })} />}</Field>
          <Field label={t("admin.initialPassword")} hint={t("admin.initialPasswordHint")}>{(id) => <Input id={id} type="password" autoComplete="new-password" value={v.password} onChange={(e) => setV({ ...v, password: e.target.value })} />}</Field>
        </>}
        <Field label={t("admin.role")}>{(id) => <Select id={id} value={v.role} onChange={(e) => setV({ ...v, role: e.target.value })}>{(roles ?? []).map((r) => <option key={r.id} value={r.code}>{roleName(t, r)}</option>)}</Select>}</Field>
        <div>
          <p className="mb-1 text-xs font-medium text-muted">{t("admin.departmentScope")}</p>
          <div className="grid grid-cols-2 gap-1.5">
            {(departments ?? []).map((d) => <Checkbox key={d.id} checked={v.department_ids.includes(d.id)} onChange={(on) => toggleDep(d.id, on)} label={d.name} />)}
          </div>
          <p className="mt-1 text-xs text-subtle">{t("admin.departmentScopeHint")}</p>
        </div>
      </div>
    </Dialog>
  );
}

function MembersTab() {
  const { t, fmtRelative } = useI18n();
  const { can, user } = useAuth();
  const company = useCompanyKey();
  const { data: departments } = useDepartments();
  const { run } = useAction("admin");
  const [editing, setEditing] = useState<Member | "new" | null>(null);
  const { data, isLoading, error } = useQuery({ queryKey: [company, "admin", "members"], queryFn: () => api.get<Member[]>("/members") });
  const depName = useMemo(() => new Map((departments ?? []).map((d) => [d.id, d.name])), [departments]);
  const columns = useMemo<ColumnDef<Member>[]>(() => [
    { header: t("common.name"), accessorKey: "full_name", cell: ({ row }) => <div><p className="font-medium">{row.original.full_name}</p><p className="text-2xs text-muted">{row.original.email}</p></div> },
    { header: t("admin.role"), id: "role", accessorFn: (m) => roleName(t, m.role) },
    { header: t("admin.departmentScope"), id: "deps", enableSorting: false, cell: ({ row }) => <span className="text-muted">{row.original.department_ids.length ? row.original.department_ids.map((d) => depName.get(d) ?? "?").join(", ") : t("admin.allDepartments")}</span> },
    { header: t("admin.mfa"), accessorKey: "mfa_enabled", cell: ({ getValue }) => (getValue() ? <Badge tone="success"><ShieldCheck className="size-3" />{t("common.yes")}</Badge> : <span className="text-subtle">—</span>) },
    { header: t("admin.lastLogin"), accessorKey: "last_login_at", cell: ({ getValue }) => <span className="text-muted">{getValue() ? fmtRelative(getValue() as string) : "—"}</span> },
    { header: t("common.status"), accessorKey: "is_active", cell: ({ getValue }) => (getValue() ? <Badge tone="success">{t("common.active")}</Badge> : <Badge>{t("common.inactive")}</Badge>) },
    {
      id: "actions", header: "", enableSorting: false, cell: ({ row }) => can("admin.users") && row.original.user_id !== user?.id ? (
        <Button size="xs" variant="ghost" onClick={(e) => { e.stopPropagation(); void run("active", () => api.patch(`/members/${row.original.id}`, { is_active: !row.original.is_active }), t("common.saved")); }}>
          {row.original.is_active ? t("admin.deactivate") : t("admin.activate")}
        </Button>
      ) : null,
    },
  ], [t, fmtRelative, depName, can, user, run]);
  return (
    <Card>
      {can("admin.users") && <div className="flex justify-end border-b border-border px-4 py-2"><Button size="sm" onClick={() => setEditing("new")}><Plus className="size-3.5" />{t("admin.addMember")}</Button></div>}
      {error ? <ErrorState error={error} className="m-4" /> : <DataTable columns={columns} data={data ?? []} loading={isLoading} onRowClick={can("admin.users") ? setEditing : undefined} empty={<EmptyState title={t("common.noData")} />} />}
      {editing && <MemberDialog member={editing === "new" ? null : editing} onClose={() => setEditing(null)} />}
    </Card>
  );
}

function RolesTab() {
  const { t } = useI18n();
  const { can } = useAuth();
  const company = useCompanyKey();
  const { data, isLoading, error } = useRoles();
  const { data: catalog } = useQuery({ queryKey: [company, "admin", "permissions"], queryFn: () => api.get<string[]>("/permissions") });
  const { run, busy } = useAction("admin");
  const [open, setOpen] = useState(false);
  const [v, setV] = useState({ code: "", name: "", description: "", permissions: [] as string[] });
  const groups = useMemo(() => {
    const g = new Map<string, string[]>();
    for (const p of catalog ?? []) g.set(p.split(".")[0], [...(g.get(p.split(".")[0]) ?? []), p]);
    return [...g.entries()];
  }, [catalog]);
  return (
    <Card>
      {can("admin.roles") && <div className="flex justify-end border-b border-border px-4 py-2"><Button size="sm" onClick={() => setOpen(true)}><Plus className="size-3.5" />{t("admin.newRole")}</Button></div>}
      {error && <ErrorState error={error} className="m-4" />}
      {isLoading ? null : (
        <ul className="divide-y divide-border">
          {(data ?? []).map((r) => (
            <li key={r.id} className="px-4 py-3">
              <div className="flex items-center gap-2"><span className="font-medium">{r.is_system ? roleName(t, r) : r.name}</span><code className="text-2xs text-subtle">{r.code}</code>{r.is_system && <Badge>{t("admin.systemRole")}</Badge>}</div>
              {r.description && <p className="text-xs text-muted">{r.is_system ? roleDescription(t, r) : r.description}</p>}
              <div className="mt-1.5 flex flex-wrap gap-1">{r.permissions.map((p) => <Badge key={p} tone={p === "*" ? "brand" : "neutral"}>{p === "*" ? t("admin.allPermissions") : p}</Badge>)}</div>
            </li>
          ))}
        </ul>
      )}
      <Dialog open={open} onOpenChange={setOpen} wide title={t("admin.newRole")}
        footer={<><Button onClick={() => setOpen(false)}>{t("common.cancel")}</Button>
          <Button variant="primary" loading={busy === "create"} disabled={!v.code.trim() || !v.name.trim() || !v.permissions.length} onClick={async () => {
            const ok = await run("create", () => api.post("/roles", { ...v, description: v.description || null }), t("common.saved"));
            if (ok !== undefined) { setOpen(false); setV({ code: "", name: "", description: "", permissions: [] }); }
          }}>{t("common.create")}</Button></>}>
        <div className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t("common.code")} required>{(id) => <Input id={id} value={v.code} onChange={(e) => setV({ ...v, code: e.target.value })} />}</Field>
            <Field label={t("common.name")} required>{(id) => <Input id={id} value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />}</Field>
            <Field label={t("common.description")} className="sm:col-span-2">{(id) => <Textarea id={id} rows={2} value={v.description} onChange={(e) => setV({ ...v, description: e.target.value })} />}</Field>
          </div>
          <p className="text-xs text-muted">{t("admin.escalationHint")}</p>
          <div className="grid gap-3 sm:grid-cols-3">
            {groups.map(([g, perms]) => (
              <fieldset key={g} className="rounded-md border border-border p-2">
                <legend className="px-1 text-2xs font-semibold uppercase text-subtle">{g}</legend>
                <div className="space-y-1">{perms.map((p) => (
                  <Checkbox key={p} checked={v.permissions.includes(p)} disabled={!can(p)} label={<span className="text-xs">{p}</span>}
                    onChange={(on) => setV((s) => ({ ...s, permissions: on ? [...s.permissions, p] : s.permissions.filter((x) => x !== p) }))} />
                ))}</div>
              </fieldset>
            ))}
          </div>
        </div>
      </Dialog>
    </Card>
  );
}

function DepartmentsTab() {
  const { t } = useI18n();
  const { can } = useAuth();
  const { data, isLoading, error } = useDepartments();
  const { run, busy } = useAction("departments");
  const [editing, setEditing] = useState<Department | "new" | null>(null);
  const [v, setV] = useState({ code: "", name: "", kind: "workshop", aliases: "" });
  const openEditor = (d: Department | "new") => {
    setEditing(d);
    setV(d === "new" ? { code: "", name: "", kind: "workshop", aliases: "" } : { code: d.code, name: d.name, kind: d.kind, aliases: d.aliases.join(", ") });
  };
  const aliases = v.aliases.split(",").map((a) => a.trim()).filter(Boolean);
  const columns = useMemo<ColumnDef<Department>[]>(() => [
    { header: t("common.code"), accessorKey: "code", cell: ({ getValue }) => <span className="font-medium">{getValue() as string}</span> },
    { header: t("common.name"), accessorKey: "name" },
    { header: t("admin.kind"), accessorKey: "kind", cell: ({ getValue }) => t(`dkind.${getValue() as string}` as MessageKey) },
    { header: t("admin.aliases"), accessorKey: "aliases", enableSorting: false, cell: ({ getValue }) => <span className="text-muted">{(getValue() as string[]).join(", ") || "—"}</span> },
  ], [t]);
  return (
    <Card>
      {can("admin.company") && <div className="flex justify-end border-b border-border px-4 py-2"><Button size="sm" onClick={() => openEditor("new")}><Plus className="size-3.5" />{t("admin.newDepartment")}</Button></div>}
      {error ? <ErrorState error={error} className="m-4" /> : <DataTable columns={columns} data={data ?? []} loading={isLoading} onRowClick={can("admin.company") ? openEditor : undefined} empty={<EmptyState title={t("common.noData")} />} />}
      {editing && (
        <Dialog open onOpenChange={(o) => !o && setEditing(null)} title={editing === "new" ? t("admin.newDepartment") : editing.name}
          footer={<><Button onClick={() => setEditing(null)}>{t("common.cancel")}</Button>
            <Button variant="primary" loading={busy === "save"} disabled={editing === "new" && (!v.code.trim() || !v.name.trim())} onClick={async () => {
              const ok = editing === "new"
                ? await run("save", () => api.post("/departments", { code: v.code.trim(), name: v.name.trim(), kind: v.kind, aliases }), t("common.saved"))
                : await run("save", () => api.put(`/departments/${editing.id}/aliases`, { aliases }), t("common.saved"));
              if (ok !== undefined) setEditing(null);
            }}>{t("common.save")}</Button></>}>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t("common.code")} required>{(id) => <Input id={id} value={v.code} disabled={editing !== "new"} onChange={(e) => setV({ ...v, code: e.target.value })} />}</Field>
            <Field label={t("common.name")} required>{(id) => <Input id={id} value={v.name} disabled={editing !== "new"} onChange={(e) => setV({ ...v, name: e.target.value })} />}</Field>
            <Field label={t("admin.kind")}>{(id) => <Select id={id} value={v.kind} disabled={editing !== "new"} onChange={(e) => setV({ ...v, kind: e.target.value })}>{["workshop", "warehouse", "office", "other"].map((k) => <option key={k} value={k}>{t(`dkind.${k}` as MessageKey)}</option>)}</Select>}</Field>
            <Field label={t("admin.aliases")} hint={t("admin.aliasesHint")} className="sm:col-span-2">{(id) => <Input id={id} value={v.aliases} onChange={(e) => setV({ ...v, aliases: e.target.value })} />}</Field>
          </div>
        </Dialog>
      )}
    </Card>
  );
}

function WorkflowsTab() {
  const { t } = useI18n();
  const { can } = useAuth();
  const company = useCompanyKey();
  const { run } = useAction("workflows");
  const { data, isLoading, error } = useQuery({ queryKey: [company, "admin", "workflows"], queryFn: () => api.get<Workflow[]>("/workflows") });
  const [confirm, setConfirm] = useState<Workflow | null>(null);
  const save = (w: Workflow, patch: Partial<Workflow>) =>
    run(w.record_type, () => api.put(`/workflows/${w.record_type}`, { auto_approve: patch.auto_approve ?? w.auto_approve, confidence_threshold: patch.confidence_threshold ?? w.confidence_threshold }), t("common.saved"));
  return (
    <Card>
      <div className="border-b border-border px-4 py-3"><Notice tone="info">{t("admin.workflowsHint")}</Notice></div>
      {error && <ErrorState error={error} className="m-4" />}
      {!isLoading && (
        <div className="overflow-x-auto scrollbar-thin">
        <table className="w-full text-xs">
          <thead className="bg-surface-2 text-left text-muted"><tr className="border-b border-border">
            <th className="px-4 py-1.5 font-medium">{t("agent.submit.recordType")}</th><th className="px-3 py-1.5 font-medium">{t("admin.approver")}</th>
            <th className="px-3 py-1.5 font-medium">{t("admin.autoApprove")}</th><th className="px-3 py-1.5 font-medium">{t("admin.threshold")}</th>
          </tr></thead>
          <tbody>
            {(data ?? []).map((w) => (
              <tr key={w.record_type} className="border-b border-border last:border-0">
                <td className="px-4 py-2"><p className="font-medium">{recordTypeLabel(t, w.record_type, w.label)}</p><p className="text-2xs text-subtle">{moduleName(t, w.module)}</p></td>
                <td className="px-3 py-2"><code className="text-2xs">{w.approve_permission}</code></td>
                <td className="px-3 py-2"><Switch checked={w.auto_approve} disabled={!can("admin.company")} label={t("admin.autoApprove")}
                  onCheckedChange={(on) => (on ? setConfirm(w) : void save(w, { auto_approve: false }))} /></td>
                <td className="px-3 py-2">
                  <Select className="h-7 w-24 text-xs" value={String(w.confidence_threshold)} disabled={!can("admin.company")} aria-label={t("admin.threshold")}
                    onChange={(e) => void save(w, { confidence_threshold: Number(e.target.value) })}>
                    {[0.7, 0.8, 0.85, 0.9, 0.95, 0.99].map((x) => <option key={x} value={x}>{Math.round(x * 100)}%</option>)}
                  </Select>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
      )}
      <ConfirmDialog open={!!confirm} onOpenChange={(o) => !o && setConfirm(null)} title={t("admin.autoApproveConfirmTitle", { type: confirm ? recordTypeLabel(t, confirm.record_type, confirm.label) : "" })}
        body={t("admin.autoApproveConfirm")} confirmLabel={t("admin.autoApprove")}
        onConfirm={async () => { if (confirm) await save(confirm, { auto_approve: true }); setConfirm(null); }} />
    </Card>
  );
}

function ApiClientsTab() {
  const { t, fmtRelative, fmtDate } = useI18n();
  const { can } = useAuth();
  const company = useCompanyKey();
  const { run, busy } = useAction("admin");
  const { data, isLoading, error } = useQuery({ queryKey: [company, "admin", "api-clients"], queryFn: () => api.get<ApiClient[]>("/api-clients") });
  const [open, setOpen] = useState(false);
  const [v, setV] = useState({ name: "", scopes: "agent.submit, agent.read", expires: "365" });
  const [key, setKey] = useState<string | null>(null);
  const [revoking, setRevoking] = useState<ApiClient | null>(null);
  const columns = useMemo<ColumnDef<ApiClient>[]>(() => [
    { header: t("common.name"), accessorKey: "name", cell: ({ row }) => <div><p className="font-medium">{row.original.name}</p><code className="text-2xs text-subtle">{row.original.key_prefix}…</code></div> },
    { header: t("admin.scopes"), accessorKey: "scopes", enableSorting: false, cell: ({ getValue }) => <div className="flex flex-wrap gap-1">{(getValue() as string[]).map((s) => <Badge key={s}>{s}</Badge>)}</div> },
    { header: t("admin.lastUsed"), accessorKey: "last_used_at", cell: ({ getValue }) => <span className="text-muted">{getValue() ? fmtRelative(getValue() as string) : "—"}</span> },
    { header: t("admin.expires"), accessorKey: "expires_at", cell: ({ getValue }) => fmtDate(getValue() as string) },
    {
      id: "status", header: t("common.status"), cell: ({ row }) => row.original.revoked_at ? <Badge>{t("admin.revoked")}</Badge> : (
        can("admin.api_clients") ? <Button size="xs" variant="ghost" className="text-danger" onClick={() => setRevoking(row.original)}>{t("admin.revoke")}</Button> : <Badge tone="success">{t("common.active")}</Badge>
      ),
    },
  ], [t, fmtRelative, fmtDate, can]);
  return (
    <Card>
      {can("admin.api_clients") && <div className="flex justify-end border-b border-border px-4 py-2"><Button size="sm" onClick={() => setOpen(true)}><Plus className="size-3.5" />{t("admin.newApiClient")}</Button></div>}
      {error ? <ErrorState error={error} className="m-4" /> : <DataTable columns={columns} data={data ?? []} loading={isLoading} empty={<EmptyState title={t("common.noData")}>{t("admin.apiClientsHint")}</EmptyState>} />}
      <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (!o) setKey(null); }} title={t("admin.newApiClient")}
        footer={key ? <Button variant="primary" onClick={() => { setOpen(false); setKey(null); }}>{t("common.close")}</Button> : <>
          <Button onClick={() => setOpen(false)}>{t("common.cancel")}</Button>
          <Button variant="primary" loading={busy === "create"} disabled={!v.name.trim()} onClick={async () => {
            const res = await run("create", () => api.post<{ api_key: string }>("/api-clients", {
              name: v.name.trim(), scopes: v.scopes.split(",").map((s) => s.trim()).filter(Boolean), expires_in_days: v.expires ? Number(v.expires) : null,
            }));
            if (res) setKey(res.api_key);
          }}>{t("common.create")}</Button></>}>
        {key ? (
          <div className="space-y-2">
            <Notice tone="warning">{t("admin.keyOnce")}</Notice>
            <div className="flex gap-2"><code className="flex-1 break-all rounded border border-border bg-surface-2 p-2 text-xs">{key}</code>
              <Button size="icon" aria-label={t("common.copy")} onClick={() => navigator.clipboard.writeText(key).then(() => toast.success(t("common.copied")))}><Copy className="size-3.5" /></Button></div>
          </div>
        ) : (
          <div className="grid gap-3">
            <Field label={t("common.name")} required>{(id) => <Input id={id} value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} />}</Field>
            <Field label={t("admin.scopes")} hint={t("admin.scopesHint")}>{(id) => <Input id={id} value={v.scopes} onChange={(e) => setV({ ...v, scopes: e.target.value })} />}</Field>
            <Field label={t("admin.expiresDays")}>{(id) => <Input id={id} inputMode="numeric" value={v.expires} onChange={(e) => setV({ ...v, expires: e.target.value.replace(/\D/g, "") })} />}</Field>
          </div>
        )}
      </Dialog>
      <ConfirmDialog open={!!revoking} onOpenChange={(o) => !o && setRevoking(null)} title={t("admin.revokeTitle", { name: revoking?.name ?? "" })} danger confirmLabel={t("admin.revoke")}
        body={t("admin.revokeHint")} loading={busy === "revoke"}
        onConfirm={async () => { if (revoking) await run("revoke", () => api.delete(`/api-clients/${revoking.id}`), t("common.saved")); setRevoking(null); }} />
    </Card>
  );
}

const TABS: { key: string; label: MessageKey; perm: string[]; platform?: boolean }[] = [
  { key: "members", label: "admin.tab.members", perm: ["admin.users"] },
  { key: "roles", label: "admin.tab.roles", perm: ["admin.roles", "admin.users"] },
  { key: "departments", label: "admin.tab.departments", perm: ["admin.company"] },
  { key: "workflows", label: "admin.tab.workflows", perm: ["admin.company"] },
  { key: "api", label: "admin.tab.apiClients", perm: ["admin.api_clients"] },
  { key: "audit", label: "admin.tab.audit", perm: ["audit.read"] },
  { key: "system", label: "admin.tab.system", perm: ["admin.system"] },
  { key: "companies", label: "admin.tab.companies", perm: [], platform: true },
];

export default function AdminPage() {
  const { t } = useI18n();
  const { can, user } = useAuth();
  const [params, setParams] = useSearchParams();
  const visible = TABS.filter((x) => (x.platform ? user?.is_platform_admin : x.perm.some((p) => can(p))));
  const tab = visible.find((x) => x.key === params.get("tab"))?.key ?? visible[0]?.key;
  if (!tab) return <EmptyState title={t("common.forbidden")} />;
  return (
    <div className="space-y-4">
      <PageHeader title={t("admin.title")} subtitle={t("admin.subtitle")} />
      <Tabs value={tab} onValueChange={(v) => setParams({ tab: v }, { replace: true })}>
        <TabsList className="mb-3">{visible.map((x) => <TabsTrigger key={x.key} value={x.key}>{t(x.label)}</TabsTrigger>)}</TabsList>
        <TabsContent value="members"><MembersTab /></TabsContent>
        <TabsContent value="roles"><RolesTab /></TabsContent>
        <TabsContent value="departments"><DepartmentsTab /></TabsContent>
        <TabsContent value="workflows"><WorkflowsTab /></TabsContent>
        <TabsContent value="api"><ApiClientsTab /></TabsContent>
        <TabsContent value="audit"><AuditTab /></TabsContent>
        <TabsContent value="system"><SystemTab /></TabsContent>
        <TabsContent value="companies"><CompaniesTab /></TabsContent>
      </Tabs>
    </div>
  );
}
