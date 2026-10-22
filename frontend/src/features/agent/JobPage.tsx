import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  AlertTriangle, ArrowLeft, Check, CheckCheck, Circle, Download, FileSpreadsheet, FileText, Image as ImageIcon, Loader2, Minus, RotateCcw,
  Save, Table2, X, XCircle,
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { Link, useParams, useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { api, newIdempotencyKey } from "@/api/client";
import { useAgentInfo, useCompanyKey, useRecordTypes } from "@/api/hooks";
import type { JobDetail, JobItem, RecordPage, RecordSummary, StageRun, TableInfo } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/dialog";
import { EmptyState, ErrorState, Notice, Skeleton, Spinner } from "@/components/ui/feedback";
import { DefinitionList, PageHeader } from "@/components/ui/page";
import { ConfidenceBar, StatusBadge } from "@/components/ui/status";
import { useI18n, type MessageKey, type TFunction } from "@/i18n";
import { entityLabel, recordTypeLabel, stageLabel } from "@/i18n/labels";
import { toastError } from "@/lib/notify";
import { cn } from "@/lib/utils";
import { MappingDialog } from "./MappingDialog";
import { RecordPanel } from "./RecordPanel";

const STAGES = ["ingest", "parse", "interpret", "normalize", "validate", "match", "review", "commit", "report"] as const;
const LIVE = ["queued", "processing", "committing"];

type TabKey = "needs_correction" | "needs_approval" | "ready" | "approved" | "committed" | "closed" | "all";
const TAB_QUERY: Record<TabKey, { group?: string; status?: string }> = {
  needs_correction: { group: "needs_correction" },
  needs_approval: { group: "needs_approval" },
  ready: { group: "ready" },
  approved: { status: "approved" },
  committed: { status: "committed" },
  closed: { status: "rejected,failed" },
  all: {},
};
const TAB_LABEL: Record<TabKey, MessageKey> = {
  needs_correction: "group.needs_correction", needs_approval: "group.needs_approval", ready: "group.ready",
  approved: "status.approved", committed: "status.committed", closed: "agent.job.tabClosed", all: "common.all",
};

type StageState = "done" | "running" | "failed" | "waiting" | "pending" | "skipped";

function stageState(stage: string, runs: StageRun[], job: JobDetail): StageState {
  const mine = runs.filter((r) => r.stage === stage);
  if (mine.some((r) => r.status === "running")) return "running";
  const latest = new Map<string, StageRun>();
  for (const r of mine) latest.set(r.item_id ?? "job", r);
  if ([...latest.values()].some((r) => r.status === "failed")) return "failed";
  if (mine.length) return "done";
  if (stage === "ingest") return "done";
  if (stage === "review" && job.status === "awaiting_review") return "waiting";
  if (stage === "review" && ["committing", "completed"].includes(job.status)) return "done";
  if (stage === "report" && job.status === "completed") return "done";
  return "pending";
}

/** The stage a failed job stopped at: the first one whose runs failed, or the one its error names. */
function failedStageOf(job: JobDetail): string | undefined {
  if (job.status !== "failed") return job.error?.stage;
  return STAGES.find((s) => s === job.error?.stage || stageState(s, job.stage_runs, job) === "failed");
}

function StageTimeline({ job }: { job: JobDetail }) {
  const { t } = useI18n();
  const failedAt = job.status === "failed" ? STAGES.findIndex((s) => s === failedStageOf(job)) : -1;
  return (
    <ol className="flex gap-1 overflow-x-auto pb-1 scrollbar-thin" aria-label={t("agent.job.stages")}>
      {STAGES.map((stage, i) => {
        // Stages after the one a failed job stopped at did not run for it, whatever older runs recorded.
        let state = stageState(stage, job.stage_runs, job);
        if (failedAt >= 0 && i === failedAt) state = "failed";
        else if (failedAt >= 0 && i > failedAt && state !== "failed") state = "skipped";
        const ms = state === "skipped" ? 0 : job.stage_runs.filter((r) => r.stage === stage).reduce((a, r) => a + (r.duration_ms ?? 0), 0);
        return (
          <li key={stage} className="flex flex-1 items-center gap-1.5">
            <span className={cn("flex size-5 shrink-0 items-center justify-center rounded-full border text-2xs",
              state === "done" && "border-success bg-success text-white",
              state === "running" && "border-primary text-primary",
              state === "failed" && "border-danger bg-danger text-white",
              state === "waiting" && "border-warning bg-warning-soft text-warning",
              state === "pending" && "border-border-strong text-subtle",
              state === "skipped" && "border-dashed border-border-strong text-subtle")}>
              {state === "done" ? <Check className="size-3" /> : state === "running" ? <Loader2 className="size-3 animate-spin" />
                : state === "failed" ? <X className="size-3" /> : state === "waiting" ? <Circle className="size-2 fill-current" />
                  : state === "skipped" ? <Minus className="size-3" /> : i + 1}
            </span>
            <span className="shrink-0">
              <span className={cn("block whitespace-nowrap text-xs", state === "pending" || state === "skipped" ? "text-subtle" : "font-medium")}>{stageLabel(t, stage)}</span>
              <span className="block text-2xs text-subtle tabular">{ms ? (ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${ms} ms`) : state === "waiting" ? t("agent.job.waitingYou")
                : state === "skipped" ? t("agent.job.notRun") : " "}</span>
            </span>
            {i < STAGES.length - 1 && <span className={cn("hidden h-px min-w-2 flex-1 lg:block", state === "done" ? "bg-success/50" : "bg-border")} />}
          </li>
        );
      })}
    </ol>
  );
}

/** Processing report lines built from the report's figures, so they read in the user's language. */
function buildReportLines(t: TFunction, fmtNumber: (v: unknown) => string, job: JobDetail, failedStage: string | undefined): string[] {
  const r = job.report;
  const byStatus = r.records_by_status;
  const groups = r.review_groups;
  const records = Object.values(byStatus).reduce((a, b) => a + b, 0);
  const lines: string[] = [];
  if (job.status === "failed") lines.push(t("agent.report.failedAt", { stage: failedStage ? stageLabel(t, failedStage) : "?", message: job.error?.message ?? "" }));
  else if (records) lines.push(t("agent.report.success"));
  if (records) {
    lines.push(t("agent.report.recordsExtracted", { n: fmtNumber(records) }));
    lines.push(t("agent.report.fieldsExtracted", { n: fmtNumber(r.fields_extracted) }));
    lines.push(t("agent.report.fieldsValidated", { n: fmtNumber(r.fields_validated) }));
  }
  for (const [entity, n] of Object.entries(r.entities_matched).sort(([a], [b]) => a.localeCompare(b))) {
    lines.push(t("agent.report.entityMatched", { entity: entityLabel(t, entity), n: fmtNumber(n) }));
  }
  if (r.saved.length) {
    const numbers = r.saved.map((w) => w.number ?? w.target_id.slice(0, 8));
    lines.push(t("agent.report.saved", { n: fmtNumber(numbers.length), numbers: `${numbers.slice(0, 10).join(", ")}${numbers.length > 10 ? " …" : ""}` }));
  }
  const counts: [number | undefined, MessageKey][] = [
    [byStatus.approved, "agent.report.approvedWaiting"], [byStatus.failed, "agent.report.failedToSave"], [byStatus.rejected, "agent.report.rejectedN"],
    [groups.needs_correction, "agent.report.needCorrection"], [groups.needs_approval, "agent.report.needApproval"], [groups.ready, "agent.report.readyToApprove"],
  ];
  for (const [n, key] of counts) if (n) lines.push(t(key, { n: fmtNumber(n) }));
  return lines;
}

function ItemIcon({ format }: { format: string }) {
  if (["xlsx", "xls", "csv"].includes(format)) return <FileSpreadsheet className="size-4 text-success" />;
  if (["png", "jpg", "jpeg", "tiff", "webp"].includes(format)) return <ImageIcon className="size-4 text-violet" />;
  return <FileText className="size-4 text-info" />;
}

function Inputs({ job, onMap }: { job: JobDetail; onMap: (item: JobItem, table: TableInfo) => void }) {
  const { t, fmtNumber } = useI18n();
  const { can } = useAuth();
  return (
    <ul className="divide-y divide-border">
      {job.items_detail.map((item) => (
        <li key={item.id} className="px-4 py-2.5">
          <div className="flex flex-wrap items-center gap-2">
            <ItemIcon format={item.format} />
            {item.file ? (
              <button type="button" className="max-w-[24rem] truncate text-sm font-medium hover:underline"
                onClick={() => api.download(`/files/${item.file!.id}`, item.file!.name).catch(() => toast.error(t("common.error")))}>
                {item.file.name}
              </button>
            ) : <span className="text-sm font-medium">{item.format !== "form" ? t("agent.job.pastedText")
              : job.channel === "api" || job.source_kind === "api" ? t("agent.job.apiInput") : t("agent.job.formInput")}</span>}
            <Badge>{item.format.toUpperCase()}</Badge>
            <StatusBadge status={item.status} />
            {item.parser && <span className="text-2xs text-subtle">{t("agent.item.parser", { parser: item.parser })}</span>}
            {item.ocr_used && <Badge tone="violet">{t("agent.item.ocr", { conf: fmtNumber(item.ocr_confidence ?? 0, 0) })}</Badge>}
            {item.page_count && <span className="text-2xs text-subtle">{t("agent.item.pages", { n: item.page_count })}</span>}
            {item.file?.scan_status && item.file.scan_status !== "clean" && <Badge tone="warning">{t("agent.item.scan", { status: item.file.scan_status })}</Badge>}
          </div>
          {item.error && <p className="mt-1 text-xs text-danger">{item.error}</p>}
          {item.warnings.map((w, i) => <p key={i} className="mt-1 flex items-start gap-1 text-xs text-warning"><AlertTriangle className="mt-0.5 size-3 shrink-0" />{w}</p>)}
          {item.text_preview && !item.is_tabular && <p className="mt-1 line-clamp-2 text-xs text-muted">{item.text_preview}</p>}
          {item.tables.map((tb) => {
            const unconfirmed = tb.mapping.filter((m) => m.target_field && !m.confirmed).length;
            return (
              <div key={tb.table_seq} className="mt-2 flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface-2 px-2.5 py-1.5 text-xs">
                <Table2 className="size-3.5 text-subtle" />
                <span className="font-medium">{tb.sheet ? t("agent.record.sheet", { name: tb.sheet }) : tb.name}</span>
                <span className="text-muted">{t("agent.item.rows", { n: tb.n_rows ?? 0 })}{tb.header_row ? ` · ${t("agent.item.headerRow", { n: tb.header_row })}` : ""}</span>
                <span className="text-muted">{t("agent.job.mappedColumns", { n: tb.mapping.filter((m) => m.target_field).length, total: tb.headers.length })}</span>
                {unconfirmed > 0 && <Badge tone="warning">{t("agent.job.unconfirmedMapping", { n: unconfirmed })}</Badge>}
                <span className="flex-1" />
                {can("agent.review") && !["committing", "completed", "cancelled"].includes(job.status) && (
                  <Button size="xs" variant={unconfirmed ? "primary" : "secondary"} onClick={() => onMap(item, tb)}>{t("agent.mapping.title")}</Button>
                )}
              </div>
            );
          })}
        </li>
      ))}
    </ul>
  );
}

function RecordRow({ r, selected, onSelect }: { r: RecordSummary; selected: boolean; onSelect: () => void }) {
  const { t } = useI18n();
  const ref = r.row_ref;
  const where = ref.row ? t("agent.record.row", { n: ref.row }) : ref.page ? t("agent.record.pageN", { n: ref.page }) : null;
  return (
    <li>
      <button type="button" onClick={onSelect} aria-current={selected || undefined}
        className={cn("w-full border-l-2 px-3 py-2 text-left hover:bg-surface-2", selected ? "border-primary bg-primary-soft/50" : "border-transparent")}>
        <div className="flex items-center gap-2">
          <span className="text-2xs text-subtle tabular">#{r.seq}</span>
          <span className="min-w-0 flex-1 truncate text-sm font-medium">{r.summary ?? recordTypeLabel(t, r.record_type)}</span>
          {r.status === "needs_review" ? <StatusBadge status={r.review_group} /> : <StatusBadge status={r.status} />}
        </div>
        <div className="mt-0.5 flex items-center gap-2 text-2xs text-muted">
          {where && <span>{ref.sheet ? `${ref.sheet} · ` : ""}{where}</span>}
          {r.committed_number && <span className="font-medium text-success">{r.committed_number}</span>}
          {!!r.issues.error && <span className="text-danger">{t("agent.job.errors", { n: r.issues.error })}</span>}
          {!!r.issues.warning && <span className="text-warning">{t("agent.job.warnings", { n: r.issues.warning })}</span>}
          <span className="flex-1" />
          <ConfidenceBar value={r.overall_confidence} />
        </div>
      </button>
    </li>
  );
}

export default function JobPage() {
  const { jobId = "" } = useParams();
  const { t, fmtDateTime, fmtNumber } = useI18n();
  const { can } = useAuth();
  const { data: info } = useAgentInfo();
  const { data: recordTypes } = useRecordTypes();
  const company = useCompanyKey();
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();
  const selectedId = params.get("record");
  const [tab, setTab] = useState<TabKey | null>((params.get("tab") as TabKey) || null);
  const [mapping, setMapping] = useState<{ item: JobItem; table: TableInfo } | null>(null);
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);

  const jobQuery = useQuery({
    queryKey: [company, "agent-job", jobId],
    queryFn: () => api.get<JobDetail>(`/agent/jobs/${jobId}`),
    refetchInterval: (q) => (q.state.data && LIVE.includes(q.state.data.status) ? 1500 : false),
  });
  const job = jobQuery.data;
  const live = !!job && LIVE.includes(job.status);

  const counts = useQuery({
    queryKey: [company, "agent-records", jobId, "counts", job?.status, job?.attempt],
    queryFn: () => api.get<RecordPage>(`/agent/jobs/${jobId}/records`, { limit: 1 }),
    enabled: !!job,
    refetchInterval: live ? 2000 : false,
  });
  const groups = counts.data?.groups ?? {};
  const statuses = counts.data?.statuses ?? {};
  const tabCount = (k: TabKey) =>
    k === "all" ? counts.data?.total ?? 0 : k === "closed" ? (statuses.rejected ?? 0) + (statuses.failed ?? 0)
      : TAB_QUERY[k].group ? groups[k as "ready"] ?? 0 : statuses[k as "approved"] ?? 0;
  // Bulk approval needs the approve permission of every record type in the ready group; look the
  // types up only when the user cannot approve all of them anyway.
  const approvable = (code: string) => {
    const permission = recordTypes?.find((r) => r.code === code)?.approve_permission;
    return !!permission && can("agent.review", permission);
  };
  const approvesAll = !!recordTypes?.length && recordTypes.every((r) => approvable(r.code));
  const readyTypes = useQuery({
    queryKey: [company, "agent-records", jobId, "ready-types", groups.ready ?? 0],
    queryFn: () => api.get<RecordPage>(`/agent/jobs/${jobId}/records`, { group: "ready", limit: 500 }),
    enabled: !!groups.ready && !!recordTypes && !approvesAll && can("agent.review"),
    select: (page) => [...new Set(page.items.map((r) => r.record_type))],
  });
  const activeTab: TabKey = tab ?? ((["needs_correction", "needs_approval", "ready", "approved", "committed"] as TabKey[]).find((k) => tabCount(k) > 0) ?? "all");

  const list = useInfiniteQuery({
    queryKey: [company, "agent-records", jobId, activeTab, job?.status],
    queryFn: ({ pageParam }) => api.get<RecordPage>(`/agent/jobs/${jobId}/records`, { ...TAB_QUERY[activeTab], offset: pageParam, limit: 100 }),
    initialPageParam: 0,
    getNextPageParam: (last) => (last.offset + last.items.length < last.total ? last.offset + last.items.length : undefined),
    enabled: !!counts.data,
  });
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.items) ?? [], [list.data]);

  const select = (id: string | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set("record", id);
    else next.delete("record");
    setParams(next, { replace: true });
  };
  useEffect(() => {
    if (!selectedId && rows.length && window.matchMedia("(min-width: 1024px)").matches) select(rows[0].id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rows.length, selectedId]);

  const refreshAll = () => queryClient.invalidateQueries({ predicate: (q) => q.queryKey.includes(jobId) || q.queryKey.includes("agent-jobs") });

  const run = async (key: string, fn: () => Promise<unknown>, done?: string) => {
    setBusy(key);
    try {
      await fn();
      if (done) toast.success(done);
      await refreshAll();
    } catch (e) {
      toastError(e, t);
    } finally {
      setBusy(null);
    }
  };

  const goNext = (direction: 1 | -1) => {
    const index = rows.findIndex((r) => r.id === selectedId);
    const next = rows[index + direction];
    if (next) select(next.id);
  };

  if (jobQuery.error) return <ErrorState error={jobQuery.error} />;
  if (!job) return <div className="space-y-3"><Skeleton className="h-8 w-80" /><Skeleton className="h-24 w-full" /><Skeleton className="h-96 w-full" /></div>;

  const readyCount = groups.ready ?? 0;
  const approvedCount = statuses.approved ?? 0;
  const canApproveReady = approvesAll || (!!readyTypes.data?.length && readyTypes.data.every(approvable));
  const failedStage = failedStageOf(job);
  const reportLines = buildReportLines(t, fmtNumber, job, failedStage);
  const editable = !["committing", "completed", "cancelled"].includes(job.status);

  return (
    <div className="space-y-4">
      <Link to="/agent" className="inline-flex items-center gap-1 text-xs text-muted hover:text-fg"><ArrowLeft className="size-3.5" />{t("agent.job.back")}</Link>
      <PageHeader
        title={<span className="flex min-w-0 items-center gap-2"><span className="truncate">{job.title || `#${job.id.slice(0, 8)}`}</span><StatusBadge status={job.status} /></span>}
        subtitle={<>
          {fmtDateTime(job.created_at)}{job.submitted_by ? ` · ${job.submitted_by}` : ""} · {t(`agent.submit.policy.${job.import_policy}` as MessageKey)}
          {job.attempt > 1 ? ` · ${t("agent.job.attempt", { n: job.attempt })}` : ""}
        </>}
        actions={<>
          {job.status === "failed" && can("agent.submit") && (
            <Button size="sm" variant="primary" loading={busy === "retry"} onClick={() => run("retry", () => api.post(`/agent/jobs/${job.id}/retry`, {}), t("agent.job.retried"))}>
              <RotateCcw className="size-3.5" />{t("agent.job.retry")}
            </Button>
          )}
          {(job.stats.records?.failed || job.stats.records?.rejected || job.stats.records?.needs_review) ? (
            <Button size="sm" onClick={() => api.download(`/agent/jobs/${job.id}/error-report`, `biwork-errors-${job.id.slice(0, 8)}.xlsx`).catch((e) => toastError(e, t))}>
              <Download className="size-3.5" />{t("agent.job.errorReport")}
            </Button>
          ) : null}
          {can("agent.submit") && !["completed", "cancelled", "committing"].includes(job.status) && (
            <Button size="sm" variant="ghost" onClick={() => setConfirmCancel(true)}><XCircle className="size-3.5" />{t("agent.job.cancel")}</Button>
          )}
        </>}
      />

      {job.ai_is_mock && !info?.is_mock && <Notice tone="violet">{t("top.mockAi")}</Notice>}
      {job.duplicate_of_job_id && (
        <Notice tone="info">{t("agent.job.duplicateOf")} — <Link className="underline" to={`/agent/jobs/${job.duplicate_of_job_id}`}>#{job.duplicate_of_job_id.slice(0, 8)}</Link></Notice>
      )}
      {job.status === "failed" && job.error && (
        <Notice tone="danger" icon={<AlertTriangle className="size-4" />}>
          <p className="font-medium">{t("agent.job.failed", { stage: failedStage ? stageLabel(t, failedStage) : "?" })}</p>
          <p>{job.error.message}</p>
          {job.error.retryable === false && <p className="mt-1">{t("agent.job.notRetryable")}</p>}
        </Notice>
      )}
      {job.warnings.filter((w) => !w.startsWith("Mock AI") && !(job.duplicate_of_job_id && w.startsWith("Re-processing"))).map((w, i) => <Notice key={i} tone="warning">{w}</Notice>)}

      <Card className="px-4 py-3">
        <StageTimeline job={job} />
        {live && <p className="mt-2 flex items-center gap-1.5 text-xs text-muted"><Spinner className="size-3.5" />{job.status === "committing" ? t("agent.job.committing") : t("agent.job.processingLive")}</p>}
      </Card>

      <Card>
        <CardHeader title={t("agent.job.inputs")} subtitle={job.ai_provider && t("agent.job.aiInfo", { provider: job.ai_provider, model: job.ai_model ?? "—" })} />
        <Inputs job={job} onMap={(item, table) => setMapping({ item, table })} />
      </Card>

      <Card className="overflow-hidden">
        <CardHeader title={t("agent.job.records")} subtitle={counts.data ? t("common.recordCount", { n: counts.data.total }) : undefined} actions={editable && <>
          {readyCount > 0 && canApproveReady && (
            <Button size="sm" variant="success" loading={busy === "approve"}
              onClick={() => run("approve", async () => {
                const res = await api.post<{ approved: string[]; skipped: { seq: number; reason: string }[] }>(`/agent/jobs/${job.id}/approve`, { group: "ready" });
                toast.success(t("agent.job.approvedN", { n: res.approved.length }), { description: res.skipped.length ? t("agent.job.skippedN", { n: res.skipped.length }) : undefined });
              })}>
              <CheckCheck className="size-3.5" />{t("agent.job.approveReady", { n: readyCount })}
            </Button>
          )}
          {approvedCount > 0 && can("agent.review") && (
            <Button size="sm" variant="primary" loading={busy === "commit"}
              onClick={() => run("commit", () => api.post(`/agent/jobs/${job.id}/commit`, {}, { "Idempotency-Key": newIdempotencyKey() }), t("agent.job.commitQueued", { n: approvedCount }))}>
              <Save className="size-3.5" />{t("agent.job.commit", { n: approvedCount })}
            </Button>
          )}
        </>} />
        <div className="flex gap-0.5 overflow-x-auto border-b border-border px-2 scrollbar-thin" role="tablist">
          {(Object.keys(TAB_QUERY) as TabKey[]).map((k) => {
            const n = tabCount(k);
            if (n === 0 && k !== "all" && k !== activeTab) return null;
            return (
              <button key={k} role="tab" aria-selected={activeTab === k} onClick={() => { setTab(k); select(null); }}
                className={cn("-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-xs whitespace-nowrap",
                  activeTab === k ? "border-primary font-medium text-fg" : "border-transparent text-muted hover:text-fg")}>
                {t(TAB_LABEL[k])}<span className="rounded bg-surface-3 px-1.5 text-2xs tabular">{n}</span>
              </button>
            );
          })}
        </div>
        <div className="grid grid-cols-1 lg:grid-cols-[minmax(260px,340px)_minmax(0,1fr)]">
          <div className="border-b border-border lg:border-r lg:border-b-0">
            {list.isLoading || counts.isLoading ? <div className="space-y-2 p-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
              : rows.length === 0 ? <EmptyState title={live ? t("agent.job.processingLive") : t("agent.job.noRecords")} /> : (
                <ul className="max-h-[70vh] divide-y divide-border overflow-y-auto scrollbar-thin">
                  {rows.map((r) => <RecordRow key={r.id} r={r} selected={r.id === selectedId} onSelect={() => select(r.id)} />)}
                  {list.hasNextPage && <li className="p-2 text-center"><Button size="xs" variant="ghost" loading={list.isFetchingNextPage} onClick={() => list.fetchNextPage()}>{t("common.loadMore")}</Button></li>}
                </ul>
              )}
          </div>
          <div className="min-w-0">
            {selectedId ? (
              <RecordPanel key={selectedId} jobId={job.id} recordId={selectedId} editable={editable} onChanged={refreshAll}
                onNext={() => goNext(1)} onPrev={() => goNext(-1)} onClose={() => select(null)} />
            ) : <EmptyState title={t("agent.job.selectRecord")} />}
          </div>
        </div>
      </Card>

      <Card>
        <CardHeader title={t("agent.job.report")} />
        <div className="grid gap-4 p-4 md:grid-cols-2">
          <div>
            {reportLines.length === 0 ? <p className="text-sm text-muted">—</p> : (
              <ul className="space-y-1 text-sm">{reportLines.map((m, i) => <li key={i}>• {m}</li>)}</ul>
            )}
            {job.report.saved.length > 0 && (
              <ul className="mt-3 flex flex-wrap gap-1.5">
                {job.report.saved.map((s) => (
                  <li key={s.target_id}>
                    {can("audit.read") ? (
                      <Link to={`/trace/${s.target_table}/${s.target_id}`}><Badge tone="success">{s.number ?? s.target_id.slice(0, 8)}</Badge></Link>
                    ) : <Badge tone="success">{s.number ?? s.target_id.slice(0, 8)}</Badge>}
                  </li>
                ))}
              </ul>
            )}
          </div>
          <DefinitionList items={[
            [t("agent.report.fields"), `${fmtNumber(job.report.fields_extracted)} / ${fmtNumber(job.report.fields_validated)}`],
            [t("agent.report.matched"), Object.entries(job.report.entities_matched).map(([k, v]) => `${entityLabel(t, k)}: ${fmtNumber(v)}`).join(", ") || "—"],
            [t("agent.report.ai"), `${job.report.ai.provider} · ${job.report.ai.model}${job.report.ai.is_mock ? ` (${t("agent.col.mock")})` : ""}`],
            [t("analytics.calls"), `${fmtNumber(job.report.ai.calls)} · ${fmtNumber(job.report.ai.input_tokens)} / ${fmtNumber(job.report.ai.output_tokens)} · ${fmtNumber(job.report.ai.cost_usd, 4)} USD`],
            [t("agent.report.versions"), <span className="font-mono text-2xs">{job.report.pipeline_version} · {job.report.prompt_version}</span>],
          ]} />
        </div>
      </Card>

      {mapping && <MappingDialog jobId={job.id} item={mapping.item} table={mapping.table} onClose={() => setMapping(null)} onApplied={async () => {
        // Re-reading replaces the table's records: reload the lists, then drop the (possibly deleted) selection.
        await queryClient.invalidateQueries({ predicate: (q) => (q.queryKey.includes(jobId) && !q.queryKey.includes("agent-record")) || q.queryKey.includes("agent-jobs") });
        select(null);
      }} />}
      <ConfirmDialog open={confirmCancel} onOpenChange={setConfirmCancel} title={t("agent.job.cancel")} body={t("agent.job.cancelConfirm")} danger
        confirmLabel={t("agent.job.cancel")} loading={busy === "cancel"}
        onConfirm={() => run("cancel", () => api.post(`/agent/jobs/${job.id}/cancel`)).then(() => setConfirmCancel(false))} />
    </div>
  );
}
