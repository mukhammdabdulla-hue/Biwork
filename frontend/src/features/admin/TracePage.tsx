import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, Database, FileInput, ListChecks, ScanSearch, ShieldCheck, UserCheck, Workflow } from "lucide-react";
import type { ReactNode } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api } from "@/api/client";
import { useCompanyKey } from "@/api/hooks";
import type { Trace } from "@/api/types";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { MiniTable } from "@/components/ui/charts";
import { ErrorState, Notice, Skeleton } from "@/components/ui/feedback";
import { DefinitionList, PageHeader } from "@/components/ui/page";
import { ConfidenceBar, FieldStateBadge, StatusBadge } from "@/components/ui/status";
import { useI18n } from "@/i18n";
import { auditActionLabel, codeLabel, entityLabel, fieldLabel, issueFieldLabel, issueMessage, stageLabel } from "@/i18n/labels";
import { toastError } from "@/lib/notify";
import { toNumber } from "@/lib/utils";
import type { FieldState } from "@/api/types";
import { JsonBlock } from "./AuditSystem";

function Step({ icon, title, children }: { icon: ReactNode; title: string; children: ReactNode }) {
  return (
    <li className="relative pl-9">
      <span className="absolute top-0 left-0 flex size-6 items-center justify-center rounded-full bg-primary-soft text-primary">{icon}</span>
      <span className="absolute top-7 bottom-[-12px] left-3 w-px bg-border" aria-hidden />
      <h2 className="mb-2 text-base font-semibold">{title}</h2>
      <Card className="p-4">{children}</Card>
    </li>
  );
}

export default function TracePage() {
  const { entityType = "", entityId = "" } = useParams();
  const { t, fmtDateTime, fmtNumber } = useI18n();
  const navigate = useNavigate();
  const company = useCompanyKey();
  const { data, isLoading, error } = useQuery({ queryKey: [company, "trace", entityType, entityId], queryFn: () => api.get<Trace>(`/audit/trace/${entityType}/${entityId}`) });
  const a = data?.agent;
  return (
    <div className="space-y-4">
      <button type="button" onClick={() => navigate(-1)} className="inline-flex items-center gap-1 text-xs text-muted hover:text-fg"><ArrowLeft className="size-3.5" />{t("common.back")}</button>
      <PageHeader title={t("trace.title")} subtitle={<span>{entityType.replace(/_/g, " ")} · <code className="text-2xs">{entityId}</code></span>}
        badge={data && <Badge tone={data.created_via === "biwork_agent" ? "brand" : "neutral"}>{data.created_via === "biwork_agent" ? t("nav.agent") : t("trace.directShort")}</Badge>} />
      {error && <ErrorState error={error} />}
      {isLoading && <Skeleton className="h-96" />}
      {data && !a && <Notice tone="info">{t("trace.direct")}</Notice>}
      {a && (
        <ol className="space-y-6">
          <Step icon={<FileInput className="size-3.5" />} title={t("trace.input")}>
            <DefinitionList items={[
              [t("agent.col.submitted"), `${fmtDateTime(a.original_input.submitted_at)} · ${a.original_input.submitted_by ?? "—"}`],
              [t("agent.col.source"), `${codeLabel(t, "agent.source", a.original_input.source_kind)} · ${codeLabel(t, "agent.channel", a.original_input.channel)}`],
              [t("trace.job"), <Link className="text-primary hover:underline" to={`/agent/jobs/${a.original_input.job_id}`}>{a.original_input.job_id.slice(0, 8)}</Link>],
              [t("agent.submit.files"), a.original_input.file ? (
                <span className="space-y-0.5">
                  <button type="button" className="text-primary hover:underline" onClick={() => api.download(`/files/${a.original_input.file!.id}`, a.original_input.file!.name).catch((e) => toastError(e, t))}>{a.original_input.file.name}</button>
                  <span className="block text-2xs text-subtle">SHA-256 {a.original_input.file.sha256} · {a.original_input.file.format} · {t("agent.item.scan", { status: a.original_input.file.scan_status })}</span>
                </span>) : "—"],
              [t("trace.parser"), `${a.original_input.parser ?? "—"}${a.original_input.ocr_used ? ` · OCR ${fmtNumber(toNumber(a.original_input.ocr_confidence) ?? 0)}%` : ""}`],
              [t("trace.location"), Object.keys(a.original_input.row_ref ?? {}).length ? <code className="text-2xs">{JSON.stringify(a.original_input.row_ref)}</code> : "—"],
            ]} />
            {a.original_input.text && <pre className="mt-3 max-h-48 overflow-auto whitespace-pre-wrap rounded bg-surface-2 p-2 text-xs scrollbar-thin">{a.original_input.text}</pre>}
          </Step>
          <Step icon={<Workflow className="size-3.5" />} title={t("trace.processing")}>
            <DefinitionList items={[
              [t("agent.report.versions"), <code className="text-2xs">{a.processing.pipeline_version} · {a.processing.prompt_version}</code>],
              [t("agent.report.ai"), <span>{a.processing.ai_provider} · {a.processing.ai_model} {a.processing.ai_is_mock && <Badge tone="violet">{t("agent.mockBadge")}</Badge>}</span>],
            ]} />
            {a.processing.ai_calls.length > 0 && (
              <div className="mt-3 overflow-x-auto scrollbar-thin"><MiniTable head={[t("analytics.task"), t("analytics.model"), t("common.status"), t("analytics.tokens"), t("analytics.cost")]}
                rows={a.processing.ai_calls.map((c) => [c.task, `${c.provider} · ${c.model}`, c.status, `${fmtNumber(c.input_tokens)} / ${fmtNumber(c.output_tokens)}`, c.cost_usd != null ? fmtNumber(c.cost_usd, 4) : "—"])} /></div>
            )}
            <div className="mt-3 overflow-x-auto scrollbar-thin"><MiniTable head={[t("analytics.stage"), t("common.status"), t("agent.job.attemptShort"), t("trace.duration")]}
              rows={a.processing.stages.map((s, i) => [stageLabel(t, s.stage), <StatusBadge key={i} status={s.status} />, s.attempt, s.duration_ms != null ? `${fmtNumber(s.duration_ms)} ms` : "—"])} /></div>
          </Step>
          <Step icon={<ScanSearch className="size-3.5" />} title={t("trace.extracted")}>
            <div className="overflow-x-auto scrollbar-thin">
              <table className="w-full text-xs">
                <thead className="text-left text-muted"><tr className="border-b border-border">
                  <th className="py-1.5 pr-3 font-medium">{t("agent.mapping.field")}</th><th className="py-1.5 pr-3 font-medium">{t("agent.record.extracted")}</th>
                  <th className="py-1.5 pr-3 font-medium">{t("agent.record.normalized")}</th><th className="py-1.5 pr-3 font-medium">{t("agent.record.confidence")}</th>
                  <th className="py-1.5 pr-3 font-medium">{t("common.status")}</th><th className="py-1.5 font-medium">{t("agent.record.source")}</th>
                </tr></thead>
                <tbody>{a.extracted_values.map((v) => (
                  <tr key={v.field} className="border-b border-border last:border-0 align-top">
                    <td className="py-1.5 pr-3 font-medium">{fieldLabel(t, a.record.record_type, v.field)}</td>
                    <td className="py-1.5 pr-3">{v.raw_value ?? "—"}</td>
                    <td className="py-1.5 pr-3">{v.normalized_value == null ? "—" : String(v.normalized_value)}</td>
                    <td className="py-1.5 pr-3"><ConfidenceBar value={toNumber(v.confidence)} /></td>
                    <td className="py-1.5 pr-3"><FieldStateBadge state={v.state as FieldState} /><p className="text-2xs text-subtle">{v.extracted_by === "user" ? t("agent.record.byUser") : v.extracted_by === "derived" ? t("agent.record.derived") : v.extracted_by}{v.edited_by ? ` · ${v.edited_by}` : ""}</p></td>
                    <td className="max-w-64 py-1.5 text-2xs text-muted">{v.sources.map((s) => [s.sheet, s.cell, s.page && `p.${s.page}`].filter(Boolean).join(" ") || s.excerpt?.slice(0, 80)).join("; ") || "—"}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          </Step>
          <Step icon={<ListChecks className="size-3.5" />} title={t("trace.validation")}>
            {a.validation_results.length === 0 ? <p className="text-xs text-muted">—</p> : (
              <ul className="space-y-1 text-xs">{a.validation_results.map((v, i) => (
                <li key={i}><Badge tone={v.severity === "error" ? "danger" : v.severity === "warning" ? "warning" : "info"}>{codeLabel(t, "agent.severity", v.severity)}</Badge> <code className="text-2xs">{v.rule}</code>{v.field && ` · ${issueFieldLabel(t, a.record.record_type, v.field)}`}: {issueMessage(t, v)}</li>
              ))}</ul>
            )}
            {a.matches.length > 0 && (
              <div className="mt-3 overflow-x-auto scrollbar-thin"><MiniTable head={[t("agent.mapping.field"), t("trace.entity"), t("agent.record.matched"), t("trace.score")]}
                rows={a.matches.map((m) => [m.field ? issueFieldLabel(t, a.record.record_type, m.field) : "—", entityLabel(t, m.entity_type), `${m.label} (${codeLabel(t, "agent.matchKind", m.kind)})`, `${Math.round(Number(m.score) * 100)}%`])} /></div>
            )}
          </Step>
          <Step icon={<UserCheck className="size-3.5" />} title={t("trace.approvals")}>
            {a.approvals.length === 0 ? <p className="text-xs text-muted">—</p> : (
              <ul className="space-y-1 text-xs">{a.approvals.map((x, i) => (
                <li key={i}><span className="font-medium">{codeLabel(t, "agent.decision", x.decision)}</span> · {x.by ?? "—"}{x.auto && <Badge className="ml-1" tone="info">{t("agent.record.auto")}</Badge>} · {fmtDateTime(x.at)}{x.comment && <span className="text-muted"> — “{x.comment}”</span>}</li>
              ))}</ul>
            )}
          </Step>
          <Step icon={<Database className="size-3.5" />} title={t("trace.write")}>
            {a.database_write ? <DefinitionList items={[
              [t("trace.table"), <code className="text-2xs">{a.database_write.target_table}</code>],
              [t("common.number"), a.database_write.number],
              ["ID", <code className="text-2xs">{a.database_write.target_id}</code>],
              [t("trace.idempotency"), <code className="break-all text-2xs">{a.database_write.idempotency_key}</code>],
              [t("common.date"), fmtDateTime(a.database_write.committed_at)],
            ]} /> : <p className="text-xs text-muted">{t("trace.notWritten")}</p>}
            <details className="mt-3"><summary className="cursor-pointer text-xs text-muted">{t("trace.payload")}</summary><div className="mt-2"><JsonBlock value={a.record.payload} /></div></details>
          </Step>
        </ol>
      )}
      {data && (
        <section>
          <h2 className="mb-2 flex items-center gap-2 text-base font-semibold"><ShieldCheck className="size-4 text-success" />{t("trace.events")}</h2>
          <Card className="overflow-x-auto p-4 scrollbar-thin">
            <MiniTable head={["#", t("common.date"), t("admin.audit.action"), t("admin.audit.actor"), t("common.details")]}
              rows={data.audit_events.map((e) => [e.id, fmtDateTime(e.occurred_at), <span key={e.id} title={e.action}>{auditActionLabel(t, e.action)}</span>, e.actor_name ?? e.actor_type, e.summary ?? "—"])} />
          </Card>
        </section>
      )}
    </div>
  );
}
