import { useQuery } from "@tanstack/react-query";
import type { ColumnDef } from "@tanstack/react-table";
import { Bot, Plus } from "lucide-react";
import { useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { api, newIdempotencyKey } from "@/api/client";
import { useCompanyKey, useDepartments, useDirectory } from "@/api/hooks";
import type { Page, Task } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog, Sheet } from "@/components/ui/dialog";
import { EmptyState, ErrorState } from "@/components/ui/feedback";
import { Checkbox, Field, Input, Select, Textarea } from "@/components/ui/inputs";
import { DefinitionList, PageHeader } from "@/components/ui/page";
import { PriorityBadge, StatusBadge } from "@/components/ui/status";
import { DataTable } from "@/components/ui/table";
import { useI18n, type MessageKey } from "@/i18n";
import { ifMatch, useAction } from "@/lib/action";
import { cn } from "@/lib/utils";

const NEXT: Record<string, { status: string; key: MessageKey }[]> = {
  open: [{ status: "in_progress", key: "tasks.start" }, { status: "done", key: "tasks.complete" }, { status: "cancelled", key: "common.cancel" }],
  in_progress: [{ status: "done", key: "tasks.complete" }, { status: "open", key: "common.reopen" }, { status: "cancelled", key: "common.cancel" }],
  done: [{ status: "open", key: "common.reopen" }],
  cancelled: [{ status: "open", key: "common.reopen" }],
};

function TaskDialog({ task, onClose }: { task: Task | null; onClose: () => void }) {
  const { t } = useI18n();
  const { data: people } = useDirectory();
  const { data: departments } = useDepartments();
  const { run, busy } = useAction("tasks");
  const [v, setV] = useState({
    title: task?.title ?? "", description: task?.description ?? "", assignee_id: task?.assignee_id ?? "", department_id: task?.department_id ?? "",
    due_date: task?.due_date ?? "", priority: task?.priority ?? "normal",
  });
  const submit = async () => {
    const body = { ...v, description: v.description || null, assignee_id: v.assignee_id || null, due_date: v.due_date || null };
    const ok = task
      ? await run("save", () => api.patch(`/tasks/${task.id}`, { title: body.title, description: body.description, assignee_id: body.assignee_id, due_date: body.due_date, priority: body.priority }, ifMatch(task.version)), t("common.saved"))
      : await run("save", () => api.post("/tasks", { ...body, department_id: v.department_id || null }, { "Idempotency-Key": newIdempotencyKey() }), t("common.saved"));
    if (ok !== undefined) onClose();
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} title={task ? `${task.number}` : t("tasks.new")}
      footer={<><Button onClick={onClose}>{t("common.cancel")}</Button><Button variant="primary" disabled={!v.title.trim()} loading={busy === "save"} onClick={submit}>{t("common.save")}</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t("tasks.titleField")} required className="sm:col-span-2">{(id) => <Input id={id} value={v.title} onChange={(e) => setV({ ...v, title: e.target.value })} />}</Field>
        <Field label={t("common.description")} className="sm:col-span-2">{(id) => <Textarea id={id} rows={3} value={v.description} onChange={(e) => setV({ ...v, description: e.target.value })} />}</Field>
        <Field label={t("tasks.assignee")}>{(id) => <Select id={id} value={v.assignee_id} onChange={(e) => setV({ ...v, assignee_id: e.target.value })}><option value="">{t("tasks.unassigned")}</option>{(people ?? []).map((p) => <option key={p.id} value={p.id}>{p.full_name}</option>)}</Select>}</Field>
        <Field label={t("tasks.dueDate")}>{(id) => <Input id={id} type="date" value={v.due_date} onChange={(e) => setV({ ...v, due_date: e.target.value })} />}</Field>
        <Field label={t("common.priority")}>{(id) => <Select id={id} value={v.priority} onChange={(e) => setV({ ...v, priority: e.target.value as Task["priority"] })}>{["low", "normal", "high", "urgent"].map((p) => <option key={p} value={p}>{t(`priority.${p}` as MessageKey)}</option>)}</Select>}</Field>
        {!task && <Field label={t("common.department")}>{(id) => <Select id={id} value={v.department_id} onChange={(e) => setV({ ...v, department_id: e.target.value })}><option value="">—</option>{(departments ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</Select>}</Field>}
      </div>
    </Dialog>
  );
}

const REF_LINKS: Record<string, (id: string) => string> = {
  purchase_request: (id) => `/supply?request=${id}`,
  production_report: (id) => `/production?report=${id}`,
  ingestion_job: (id) => `/agent/jobs/${id}`,
};

export default function TasksPage() {
  const { t, fmtDate, fmtDateTime } = useI18n();
  const { can } = useAuth();
  const company = useCompanyKey();
  const [params, setParams] = useSearchParams();
  const [status, setStatus] = useState("open,in_progress");
  const mine = params.get("mine") === "1";
  const overdue = params.get("overdue") === "1";
  const [editing, setEditing] = useState<Task | "new" | null>(null);
  const [selected, setSelected] = useState<Task | null>(null);
  const { run, busy } = useAction("tasks");
  const toggle = (k: string, on: boolean) => {
    const next = new URLSearchParams(params);
    if (on) next.set(k, "1");
    else next.delete(k);
    setParams(next, { replace: true });
  };
  const { data, isLoading, error } = useQuery({
    queryKey: [company, "tasks", status, mine, overdue],
    queryFn: () => api.get<Page<Task>>("/tasks", { status: status || undefined, mine: mine || undefined, overdue: overdue || undefined, limit: 500 }),
  });
  const columns = useMemo<ColumnDef<Task>[]>(() => [
    { header: t("common.number"), accessorKey: "number", cell: ({ row }) => <span className="font-medium">{row.original.number}</span> },
    { header: t("tasks.titleField"), accessorKey: "title", cell: ({ row }) => <span className="inline-flex items-center gap-1.5">{row.original.title}{row.original.source_record_id && <Bot className="size-3.5 text-brand" aria-label={t("production.fromAgent")} />}</span> },
    { header: t("tasks.assignee"), accessorKey: "assignee_name", cell: ({ getValue }) => (getValue() as string) ?? <span className="text-subtle">{t("tasks.unassigned")}</span> },
    { header: t("tasks.dueDate"), accessorKey: "due_date", cell: ({ row }) => <span className={cn(row.original.overdue && "font-medium text-danger")}>{fmtDate(row.original.due_date)}{row.original.overdue && ` · ${t("tasks.overdue")}`}</span> },
    { header: t("common.priority"), accessorKey: "priority", cell: ({ getValue }) => <PriorityBadge priority={getValue() as string} /> },
    { header: t("common.status"), accessorKey: "status", cell: ({ getValue }) => <StatusBadge status={getValue() as string} /> },
  ], [t, fmtDate]);
  const sel = selected && data?.items.find((x) => x.id === selected.id) || selected;
  return (
    <div className="space-y-4">
      <PageHeader title={t("tasks.title")} subtitle={t("tasks.subtitle")}
        actions={can("tasks.write") && <Button size="sm" variant="primary" onClick={() => setEditing("new")}><Plus className="size-3.5" />{t("tasks.new")}</Button>} />
      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-2">
          <Select value={status} onChange={(e) => setStatus(e.target.value)} className="w-44" aria-label={t("common.status")}>
            <option value="open,in_progress">{t("tasks.active")}</option>
            <option value="">{t("common.all")}</option>
            {["open", "in_progress", "done", "cancelled"].map((s) => <option key={s} value={s}>{t(`status.${s}` as MessageKey)}</option>)}
          </Select>
          <Checkbox checked={mine} onChange={(v) => toggle("mine", v)} label={t("tasks.mine")} />
          <Checkbox checked={overdue} onChange={(v) => toggle("overdue", v)} label={t("tasks.overdue")} />
        </div>
        {error ? <ErrorState error={error} className="m-4" /> : (
          <DataTable columns={columns} data={data?.items ?? []} loading={isLoading} onRowClick={setSelected} getRowId={(x) => x.id} selectedId={selected?.id}
            empty={<EmptyState title={t("common.noData")} />} />
        )}
      </Card>
      {sel && (
        <Sheet open onOpenChange={(o) => !o && setSelected(null)} title={`${sel.number} · ${sel.title}`}
          footer={can("tasks.write") && <>
            <Button variant="ghost" className="mr-auto" onClick={() => setEditing(sel)}>{t("common.edit")}</Button>
            {NEXT[sel.status].map((n) => (
              <Button key={n.status} variant={n.status === "done" ? "success" : n.status === "cancelled" ? "ghost" : "secondary"} loading={busy === n.status}
                onClick={() => run(n.status, () => api.patch(`/tasks/${sel.id}`, { status: n.status }, ifMatch(sel.version)), t("common.saved"))}>{t(n.key)}</Button>
            ))}
          </>}>
          <div className="space-y-3">
            <div className="flex gap-2"><StatusBadge status={sel.status} /><PriorityBadge priority={sel.priority} />{sel.overdue && <Badge tone="danger">{t("tasks.overdue")}</Badge>}</div>
            {sel.description && <p className="whitespace-pre-wrap text-sm">{sel.description}</p>}
            <DefinitionList items={[
              [t("tasks.assignee"), sel.assignee_name ?? t("tasks.unassigned")],
              [t("tasks.dueDate"), fmtDate(sel.due_date)],
              [t("common.created"), fmtDateTime(sel.created_at)],
              [t("status.done"), fmtDateTime(sel.completed_at)],
              [t("tasks.related"), sel.ref_type && sel.ref_id ? (REF_LINKS[sel.ref_type]
                ? <Link className="text-primary hover:underline" to={REF_LINKS[sel.ref_type](sel.ref_id)}>{sel.ref_type.replace("_", " ")}</Link> : sel.ref_type) : "—"],
            ]} />
          </div>
        </Sheet>
      )}
      {editing && <TaskDialog task={editing === "new" ? null : editing} onClose={() => setEditing(null)} />}
    </div>
  );
}
