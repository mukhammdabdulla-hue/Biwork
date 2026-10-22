import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import type { ColumnDef } from "@tanstack/react-table";
import { Bot, CheckCircle2, FileText, FileUp, Inbox, Paperclip, Search, Sparkles, X, XCircle } from "lucide-react";
import { useMemo, useRef, useState, type DragEvent } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { api, ApiError, newIdempotencyKey } from "@/api/client";
import { useAgentInfo, useCompanyKey, useRecordTypes } from "@/api/hooks";
import type { AgentStats, Job, Page } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { EmptyState, ErrorState, Notice } from "@/components/ui/feedback";
import { Checkbox, Field, Input, Select, Textarea } from "@/components/ui/inputs";
import { Menu, MenuContent, MenuItem, MenuTrigger } from "@/components/ui/menu";
import { KpiCard, PageHeader } from "@/components/ui/page";
import { StatusBadge } from "@/components/ui/status";
import { DataTable } from "@/components/ui/table";
import { useI18n, type MessageKey } from "@/i18n";
import { toastError } from "@/lib/notify";
import { cn } from "@/lib/utils";

const EXAMPLES: { key: MessageKey; text: string }[] = [
  { key: "agent.example.uz", text: "Bugun 500 dona A mahsulot ishlab chiqarildi. 480 dona sifat nazoratidan o'tdi, 20 donasi brak bo'ldi. Mas'ul sex — 1-sex, 1-smena." },
  { key: "agent.example.ru", text: "Вчера цех №2 выпустил 1200 шт продукции B-001, принято 1180 шт, брак 20 шт. Смена 2." },
  { key: "agent.example.table", text: "Sana\tSex\tMahsulot\tIshlab chiqarildi\tQabul qilindi\tBrak\n" +
    "bugun\t1-sex\tA mahsulot\t420\t410\t10\nbugun\t2-sex\tB mahsulot\t300\t295\t5" },
];

const SOURCE_KEYS: Record<string, MessageKey> = { text: "agent.source.text", file: "agent.source.file", form: "agent.source.form", api: "agent.source.api" };

function formatBytes(n: number) {
  return n < 1024 * 1024 ? `${Math.max(1, Math.round(n / 1024))} KB` : `${(n / 1024 / 1024).toFixed(1)} MB`;
}

interface Duplicate {
  jobId: string;
  status: string;
  title: string | null;
}

function SubmitCard() {
  const { t } = useI18n();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { can } = useAuth();
  const { data: info } = useAgentInfo();
  const { data: recordTypes } = useRecordTypes();
  const [text, setText] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [recordType, setRecordType] = useState("");
  const [policy, setPolicy] = useState("valid_rows_only");
  const [dragging, setDragging] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [duplicate, setDuplicate] = useState<Duplicate | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const maxBytes = (info?.max_upload_mb ?? 50) * 1024 * 1024;
  const maxFiles = info?.max_files_per_job ?? 20;
  // /agent/info lists extensions with a leading dot (".xlsx"); accept bare ones too.
  const formats = (info?.formats ?? []).map((f) => f.replace(/^\./, "").toLowerCase());
  const accept = formats.map((f) => `.${f}`).join(",");

  const addFiles = (list: FileList | File[]) => {
    setProblem(null);
    const next = [...files];
    for (const f of Array.from(list)) {
      const ext = f.name.split(".").pop()?.toLowerCase() ?? "";
      if (info && !formats.includes(ext)) {
        setProblem(t("agent.submit.badFormat", { name: f.name }));
        continue;
      }
      if (f.size > maxBytes) {
        setProblem(t("agent.submit.tooLarge", { name: f.name, mb: info?.max_upload_mb ?? 50 }));
        continue;
      }
      if (!next.some((x) => x.name === f.name && x.size === f.size)) next.push(f);
    }
    if (next.length > maxFiles) setProblem(t("agent.submit.tooMany", { n: maxFiles }));
    setFiles(next.slice(0, maxFiles));
  };

  const onDrop = (e: DragEvent) => {
    e.preventDefault();
    setDragging(false);
    if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
  };

  const submit = async (force = false) => {
    if (!text.trim() && files.length === 0) {
      setProblem(t("agent.submit.empty"));
      return;
    }
    setSubmitting(true);
    setProblem(null);
    const form = new FormData();
    if (text.trim()) form.append("text", text);
    files.forEach((f) => form.append("files", f, f.name));
    if (recordType) form.append("record_type", recordType);
    form.append("import_policy", policy);
    if (force) form.append("force", "true");
    try {
      const job = await api.upload<Job>("/agent/jobs", form, { "Idempotency-Key": newIdempotencyKey() });
      setText("");
      setFiles([]);
      setDuplicate(null);
      await queryClient.invalidateQueries({ predicate: (q) => q.queryKey.includes("agent-jobs") || q.queryKey.includes("agent-stats") });
      navigate(`/agent/jobs/${job.id}`);
    } catch (e) {
      if (e instanceof ApiError && e.code === "duplicate_upload" && e.problem.duplicate_of_job_id) {
        setDuplicate({ jobId: String(e.problem.duplicate_of_job_id), status: String(e.problem.duplicate_of_status ?? ""), title: (e.problem.duplicate_of_title as string) ?? null });
      } else if (e instanceof ApiError && e.status < 500) {
        setProblem(e.message);
      } else {
        toastError(e, t);
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (!can("agent.submit")) return null;
  const enabledTypes = (recordTypes ?? []).filter((r) => r.enabled);

  return (
    <Card>
      <CardHeader title={t("agent.submit.title")} actions={
        <Menu>
          <MenuTrigger asChild><Button size="xs" variant="ghost"><Sparkles className="size-3.5" />{t("agent.submit.example")}</Button></MenuTrigger>
          <MenuContent>
            {EXAMPLES.map((ex) => <MenuItem key={ex.key} onSelect={() => setText(ex.text)}>{t(ex.key)}</MenuItem>)}
          </MenuContent>
        </Menu>
      } />
      <div className="space-y-3 p-4">
        <Textarea aria-label={t("agent.submit.text")} value={text} onChange={(e) => setText(e.target.value)} rows={5}
          placeholder={t("agent.submit.textPlaceholder")} className="min-h-28 font-[inherit]"
          onKeyDown={(e) => { if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) void submit(); }} />
        <div onDragOver={(e) => { e.preventDefault(); setDragging(true); }} onDragLeave={() => setDragging(false)} onDrop={onDrop}
          className={cn("rounded-md border border-dashed px-3 py-3 text-center text-xs text-muted transition-colors",
            dragging ? "border-primary bg-primary-soft" : "border-border-strong bg-surface-2")}>
          <FileUp className="mx-auto mb-1 size-5 text-subtle" />
          {t("agent.submit.drop")}{" "}
          <button type="button" className="font-medium text-primary hover:underline" onClick={() => inputRef.current?.click()}>{t("agent.submit.browse")}</button>
          <input ref={inputRef} type="file" multiple className="sr-only" accept={accept} aria-label={t("agent.submit.files")}
            onChange={(e) => { if (e.target.files) addFiles(e.target.files); e.target.value = ""; }} />
          <p className="mt-1 text-2xs text-subtle">
            {info && `${formats.map((f) => f.toUpperCase()).join(", ")} · ${t("agent.submit.limits", { files: maxFiles, mb: info.max_upload_mb })}`}
            {info?.ocr_enabled && ` · ${t("agent.submit.ocr")}`}
          </p>
        </div>
        {files.length > 0 && (
          <ul className="flex flex-wrap gap-1.5">
            {files.map((f, i) => (
              <li key={`${f.name}-${i}`} className="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface-2 py-0.5 pr-0.5 pl-2 text-xs">
                <Paperclip className="size-3 text-subtle" />
                <span className="max-w-56 truncate">{f.name}</span>
                <span className="text-subtle">{formatBytes(f.size)}</span>
                <Button variant="ghost" size="icon" className="size-5" aria-label={`${t("agent.submit.removeFile")}: ${f.name}`}
                  onClick={() => setFiles(files.filter((_, j) => j !== i))}><X className="size-3" /></Button>
              </li>
            ))}
          </ul>
        )}
        {problem && <Notice tone="danger"><span role="alert">{problem}</span></Notice>}
        <div className="flex flex-wrap items-end gap-3">
          <Field label={t("agent.submit.recordType")} className="w-52">
            {(id) => (
              <Select id={id} value={recordType} onChange={(e) => setRecordType(e.target.value)}>
                <option value="">{t("agent.submit.auto")}</option>
                {enabledTypes.map((r) => <option key={r.code} value={r.code}>{r.label}</option>)}
              </Select>
            )}
          </Field>
          <Field label={t("agent.submit.policy")} className="w-52" hint={undefined}>
            {(id) => (
              <Select id={id} value={policy} onChange={(e) => setPolicy(e.target.value)} title={t(`agent.submit.policy.${policy}.hint` as MessageKey)}>
                {(info?.import_policies ?? ["valid_rows_only", "all_or_nothing", "stop_on_first_error"]).map((p) => (
                  <option key={p} value={p}>{t(`agent.submit.policy.${p}` as MessageKey)}</option>
                ))}
              </Select>
            )}
          </Field>
          <p className="min-w-40 flex-1 pb-1.5 text-2xs text-subtle">{t(`agent.submit.policy.${policy}.hint` as MessageKey)}</p>
          <Button variant="primary" onClick={() => submit()} loading={submitting}>
            <Bot className="size-3.5" />{submitting ? t("agent.submit.processing") : t("agent.submit.process")}
          </Button>
        </div>
      </div>

      <Dialog open={!!duplicate} onOpenChange={(o) => !o && setDuplicate(null)} title={t("agent.duplicate.title")}
        footer={<>
          <Button onClick={() => duplicate && navigate(`/agent/jobs/${duplicate.jobId}`)}>{t("agent.duplicate.open")}</Button>
          <Button variant="primary" loading={submitting} onClick={() => submit(true)}>{t("agent.duplicate.force")}</Button>
        </>}>
        <p className="text-sm text-muted">
          {t("agent.duplicate.body", { status: duplicate ? t(`status.${duplicate.status}` as MessageKey) : "" })}
        </p>
        {duplicate?.title && <p className="mt-2 truncate text-sm font-medium">{duplicate.title}</p>}
      </Dialog>
    </Card>
  );
}

function StatsRow() {
  const { t, fmtNumber } = useI18n();
  const company = useCompanyKey();
  const { data, isLoading } = useQuery({
    queryKey: [company, "agent-stats"],
    queryFn: () => api.get<AgentStats>("/agent/stats", { days: 30 }),
    refetchInterval: 30_000,
  });
  const review = Object.values(data?.review_queue ?? {}).reduce((a, b) => a + b, 0);
  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <KpiCard loading={isLoading} label={t("agent.stats.jobs")} value={fmtNumber(data?.jobs_total)} icon={<Inbox className="size-4" />} />
      <KpiCard loading={isLoading} label={t("agent.stats.success")} icon={<CheckCircle2 className="size-4" />}
        value={data?.success_rate == null ? "—" : `${fmtNumber(data.success_rate, 1)}%`} />
      <KpiCard loading={isLoading} label={t("agent.stats.review")} value={fmtNumber(review)} tone={review ? "warning" : "default"} icon={<FileText className="size-4" />}
        hint={data && t("agent.stats.reviewHint", { ready: data.review_queue.ready ?? 0, fix: data.review_queue.needs_correction ?? 0 })} />
      <KpiCard loading={isLoading} label={t("agent.stats.failed")} value={fmtNumber(data?.jobs_by_status.failed ?? 0)}
        tone={data?.jobs_by_status.failed ? "danger" : "default"} icon={<XCircle className="size-4" />} />
    </div>
  );
}

function History() {
  const { t, fmtRelative, fmtDateTime } = useI18n();
  const navigate = useNavigate();
  const company = useCompanyKey();
  const [params, setParams] = useSearchParams();
  const [q, setQ] = useState("");
  const status = params.get("status") ?? "";
  const source = params.get("source") ?? "";
  const mine = params.get("mine") === "1";
  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };
  const query = useInfiniteQuery({
    queryKey: [company, "agent-jobs", { q, status, source, mine }],
    queryFn: ({ pageParam }) => api.get<Page<Job>>("/agent/jobs", { q: q || undefined, status: status || undefined, source_kind: source || undefined, mine: mine || undefined, cursor: pageParam, limit: 30 }),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (last) => last.next_cursor ?? undefined,
    refetchInterval: (qry) => (qry.state.data?.pages.some((p) => p.items.some((j) => ["queued", "processing", "committing"].includes(j.status))) ? 3000 : 30_000),
  });
  const jobs = useMemo(() => query.data?.pages.flatMap((p) => p.items) ?? [], [query.data]);

  const columns = useMemo<ColumnDef<Job>[]>(() => [
    {
      header: t("agent.col.submission"), accessorKey: "title", cell: ({ row }) => (
        <div className="min-w-0 max-w-[28rem]">
          <p className="truncate font-medium text-fg">{row.original.title || `#${row.original.id.slice(0, 8)}`}</p>
          <p className="truncate text-2xs text-subtle">
            #{row.original.id.slice(0, 8)}
            {row.original.items ? ` · ${t("agent.col.items", { n: row.original.items })}` : ""}
            {row.original.ai_is_mock ? ` · ${t("agent.col.mock")}` : ""}
          </p>
        </div>
      ),
    },
    { header: t("agent.col.source"), accessorKey: "source_kind", cell: ({ getValue }) => <Badge>{t(SOURCE_KEYS[getValue() as string] ?? "agent.source.text")}</Badge> },
    {
      header: t("common.status"), accessorKey: "status", cell: ({ row }) => (
        <span className="inline-flex items-center gap-1.5">
          <StatusBadge status={row.original.status} />
          {row.original.status === "processing" && row.original.current_stage && (
            <span className="text-2xs text-muted">{t(`agent.stage.${row.original.current_stage}` as MessageKey)}</span>
          )}
        </span>
      ),
    },
    {
      header: t("agent.col.records"), id: "records", meta: { align: "right" }, enableSorting: false, cell: ({ row }) => {
        const r = row.original.stats.records ?? {};
        const total = row.original.stats.total_records ?? 0;
        return (
          <span className="tabular">
            {total}
            {!!r.needs_review && <span className="ml-1 text-warning">· {t("agent.col.toReview", { n: r.needs_review })}</span>}
            {!!r.committed && <span className="ml-1 text-success">· {t("agent.col.saved", { n: r.committed })}</span>}
          </span>
        );
      },
    },
    { header: t("common.by"), accessorKey: "submitted_by", cell: ({ getValue }) => <span className="text-muted">{(getValue() as string) ?? "—"}</span> },
    { header: t("agent.col.submitted"), accessorKey: "created_at", cell: ({ getValue }) => <span className="text-muted" title={fmtDateTime(getValue() as string)}>{fmtRelative(getValue() as string)}</span> },
  ], [t, fmtRelative, fmtDateTime]);

  return (
    <Card>
      <CardHeader title={t("agent.history")} />
      <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2">
        <div className="relative w-64 max-w-full">
          <Search className="pointer-events-none absolute top-2 left-2 size-4 text-subtle" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t("agent.history.search")} aria-label={t("common.search")} className="pl-7" />
        </div>
        <Select value={status} onChange={(e) => setParam("status", e.target.value)} className="w-44" aria-label={t("common.status")}>
          <option value="">{t("agent.history.allStatuses")}</option>
          {["queued", "processing", "awaiting_review", "committing", "completed", "failed", "cancelled"].map((s) => (
            <option key={s} value={s}>{t(`status.${s}` as MessageKey)}</option>
          ))}
        </Select>
        <Select value={source} onChange={(e) => setParam("source", e.target.value)} className="w-36" aria-label={t("agent.col.source")}>
          <option value="">{t("agent.history.allSources")}</option>
          {Object.entries(SOURCE_KEYS).map(([k, key]) => <option key={k} value={k}>{t(key)}</option>)}
        </Select>
        <Checkbox checked={mine} onChange={(v) => setParam("mine", v ? "1" : "")} label={t("agent.history.mine")} />
      </div>
      {query.error ? <ErrorState error={query.error} className="m-4" /> : (
        <DataTable columns={columns} data={jobs} loading={query.isLoading} getRowId={(j) => j.id}
          onRowClick={(j) => navigate(`/agent/jobs/${j.id}`)}
          empty={<EmptyState title={t("agent.history.empty")} />} />
      )}
      {query.hasNextPage && (
        <div className="border-t border-border p-2 text-center">
          <Button size="sm" variant="ghost" loading={query.isFetchingNextPage} onClick={() => query.fetchNextPage()}>{t("common.loadMore")}</Button>
        </div>
      )}
    </Card>
  );
}

export default function AgentPage() {
  const { t } = useI18n();
  const { data: info } = useAgentInfo();
  return (
    <div className="space-y-4">
      <PageHeader title={t("agent.title")} subtitle={t("agent.subtitle")}
        badge={info && (info.is_mock ? <Badge tone="violet">{t("agent.mockBadge")}</Badge> : <Badge tone="brand">{info.provider} · {info.model_extract}</Badge>)} />
      <StatsRow />
      <SubmitCard />
      <History />
    </div>
  );
}
