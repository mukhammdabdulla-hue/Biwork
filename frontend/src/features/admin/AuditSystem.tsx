import { useInfiniteQuery, useQuery } from "@tanstack/react-query";
import { ChevronDown, ChevronRight, ShieldCheck, ShieldX } from "lucide-react";
import { Fragment, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "@/api/client";
import { useCompanyKey } from "@/api/hooks";
import type { AuditEvent, SystemQueues } from "@/api/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { EmptyState, ErrorState, Notice, Skeleton } from "@/components/ui/feedback";
import { Input } from "@/components/ui/inputs";
import { MiniTable } from "@/components/ui/charts";
import { DefinitionList } from "@/components/ui/page";
import { StatusBadge } from "@/components/ui/status";
import { useI18n, type MessageKey } from "@/i18n";
import { toastError } from "@/lib/notify";

export function JsonBlock({ value }: { value: unknown }) {
  return <pre className="max-h-64 overflow-auto rounded bg-surface-2 p-2 text-2xs leading-relaxed scrollbar-thin">{JSON.stringify(value, null, 2)}</pre>;
}

export function AuditTab() {
  const { t, fmtDateTime } = useI18n();
  const company = useCompanyKey();
  const [action, setAction] = useState("");
  const [entity, setEntity] = useState("");
  const [open, setOpen] = useState<number | null>(null);
  const [verify, setVerify] = useState<{ ok: boolean; checked: number; broken_at: number | null } | null>(null);
  const [verifying, setVerifying] = useState(false);
  const query = useInfiniteQuery({
    queryKey: [company, "audit", action, entity],
    queryFn: ({ pageParam }) => api.get<{ items: AuditEvent[]; next_before_id: number | null }>("/audit/events", { action: action || undefined, entity_type: entity || undefined, before_id: pageParam, limit: 100 }),
    initialPageParam: undefined as number | undefined,
    getNextPageParam: (l) => l.next_before_id ?? undefined,
  });
  const rows = query.data?.pages.flatMap((p) => p.items) ?? [];
  const runVerify = async () => {
    setVerifying(true);
    try {
      setVerify(await api.get("/audit/verify"));
    } catch (e) {
      toastError(e, t);
    } finally {
      setVerifying(false);
    }
  };
  return (
    <Card>
      <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2">
        <Input value={action} onChange={(e) => setAction(e.target.value)} placeholder={t("admin.audit.filterAction")} className="w-56" aria-label={t("admin.audit.action")} />
        <Input value={entity} onChange={(e) => setEntity(e.target.value)} placeholder={t("admin.audit.entity")} className="w-44" aria-label={t("admin.audit.entity")} />
        <span className="flex-1" />
        <Button size="sm" loading={verifying} onClick={runVerify}><ShieldCheck className="size-3.5" />{t("admin.audit.verify")}</Button>
      </div>
      {verify && (
        <div className="px-4 pt-3">
          {verify.ok ? <Notice tone="success" icon={<ShieldCheck className="size-4" />}>{t("admin.audit.verified", { n: verify.checked })}</Notice>
            : <Notice tone="danger" icon={<ShieldX className="size-4" />}>{t("admin.audit.broken", { id: verify.broken_at ?? "?" })}</Notice>}
        </div>
      )}
      {query.error ? <ErrorState error={query.error} className="m-4" /> : query.isLoading ? <Skeleton className="m-4 h-64" /> : rows.length === 0 ? <EmptyState title={t("common.noData")} /> : (
        <div className="overflow-x-auto scrollbar-thin">
          <table className="w-full text-xs">
            <thead className="bg-surface-2 text-left text-muted"><tr className="border-b border-border">
              <th className="w-6" /><th className="px-2 py-1.5 font-medium">#</th><th className="px-3 py-1.5 font-medium">{t("common.date")}</th>
              <th className="px-3 py-1.5 font-medium">{t("admin.audit.action")}</th><th className="px-3 py-1.5 font-medium">{t("admin.audit.actor")}</th>
              <th className="px-3 py-1.5 font-medium">{t("common.details")}</th>
            </tr></thead>
            <tbody>
              {rows.map((e) => (
                <Fragment key={e.id}>
                  <tr className="cursor-pointer border-b border-border hover:bg-surface-2" onClick={() => setOpen(open === e.id ? null : e.id)}>
                    <td className="pl-3">{open === e.id ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}</td>
                    <td className="px-2 py-1.5 text-subtle tabular">{e.id}</td>
                    <td className="px-3 py-1.5 whitespace-nowrap">{fmtDateTime(e.occurred_at)}</td>
                    <td className="px-3 py-1.5"><code className="text-2xs">{e.action}</code></td>
                    <td className="px-3 py-1.5">{e.actor_name ?? e.actor_type}{e.actor_type !== "user" && <Badge className="ml-1">{e.actor_type}</Badge>}</td>
                    <td className="max-w-[28rem] truncate px-3 py-1.5 text-muted">{e.summary ?? (e.entity_type ? `${e.entity_type} ${e.entity_id?.slice(0, 8)}` : "—")}</td>
                  </tr>
                  {open === e.id && (
                    <tr className="border-b border-border bg-surface-2/50">
                      <td colSpan={6} className="px-4 py-3">
                        <div className="grid gap-3 md:grid-cols-2">
                          <DefinitionList items={[
                            [t("admin.audit.entity"), e.entity_type ? <span>{e.entity_type} <code className="text-2xs">{e.entity_id}</code></span> : "—"],
                            [t("admin.audit.hash"), <code className="break-all text-2xs">{e.hash}</code>],
                            [t("common.trace"), e.entity_type && e.entity_id ? <Link className="text-primary hover:underline" to={`/trace/${e.entity_type}/${e.entity_id}`}>{t("common.open")}</Link> : "—"],
                          ]} />
                          <div className="space-y-2">
                            {e.changes && <div><p className="mb-1 text-2xs font-semibold uppercase text-subtle">{t("admin.audit.changes")}</p><JsonBlock value={e.changes} /></div>}
                            {e.context && <div><p className="mb-1 text-2xs font-semibold uppercase text-subtle">{t("admin.audit.context")}</p><JsonBlock value={e.context} /></div>}
                          </div>
                        </div>
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {query.hasNextPage && <div className="border-t border-border p-2 text-center"><Button size="sm" variant="ghost" loading={query.isFetchingNextPage} onClick={() => query.fetchNextPage()}>{t("common.loadMore")}</Button></div>}
    </Card>
  );
}

export function SystemTab() {
  const { t, fmtDateTime, fmtNumber } = useI18n();
  const company = useCompanyKey();
  const { data, isLoading, error } = useQuery({ queryKey: [company, "system"], queryFn: () => api.get<SystemQueues>("/admin/system/queues"), refetchInterval: 15_000 });
  if (error) return <ErrorState error={error} />;
  if (isLoading || !data) return <Skeleton className="h-64" />;
  return (
    <div className="grid gap-3 lg:grid-cols-2">
      <Card><CardHeader title={t("admin.system.jobs")} />
        <div className="flex flex-wrap gap-2 p-4">{Object.entries(data.jobs_by_status).map(([s, n]) => <span key={s} className="inline-flex items-center gap-1.5"><StatusBadge status={s} /><span className="text-sm font-medium tabular">{n}</span></span>)}</div>
      </Card>
      <Card><CardHeader title={t("admin.system.config")} />
        <div className="p-4"><DefinitionList items={[
          [t("settings.ai.provider"), data.config.ai_provider],
          ["OCR", data.config.ocr_enabled ? t("common.yes") : t("common.no")],
          [t("admin.system.malware"), data.config.malware_scanning ? t("common.yes") : t("admin.system.malwareOff")],
          [t("admin.system.storage"), data.config.storage],
        ]} /></div>
      </Card>
      <Card><CardHeader title={t("admin.system.running")} />
        <div className="p-4 pt-2">{data.running_stages.length === 0 ? <p className="text-xs text-muted">—</p> : (
          <MiniTable head={[t("analytics.stage"), "Job", t("common.created"), t("agent.job.attemptShort")]}
            rows={data.running_stages.map((r) => [t(`agent.stage.${r.stage}` as MessageKey), <Link key={r.job_id} className="text-primary hover:underline" to={`/agent/jobs/${r.job_id}`}>{r.job_id.slice(0, 8)}</Link>, fmtDateTime(r.started_at), r.attempt])} />
        )}</div>
      </Card>
      <Card><CardHeader title={t("admin.system.failures")} />
        <div className="p-4 pt-2">{data.recent_failures.length === 0 ? <p className="text-xs text-muted">{t("admin.system.noFailures")}</p> : (
          <ul className="space-y-1.5 text-xs">{data.recent_failures.map((f, i) => (
            <li key={i}><Link className="font-medium text-primary hover:underline" to={`/agent/jobs/${f.job_id}`}>{f.job_id.slice(0, 8)}</Link> · {t(`agent.stage.${f.stage}` as MessageKey)} · {fmtDateTime(f.at)}
              <p className="truncate text-danger">{f.error}</p></li>
          ))}</ul>
        )}</div>
      </Card>
      <Card className="lg:col-span-2"><CardHeader title={t("admin.system.ai")} />
        <div className="p-4 pt-2">{data.ai_usage_this_month.length === 0 ? <p className="text-xs text-muted">{t("analytics.noAi")}</p> : (
          <MiniTable head={[t("settings.ai.provider"), t("analytics.model"), t("analytics.calls"), t("analytics.tokens"), t("analytics.cost")]}
            rows={data.ai_usage_this_month.map((u) => [u.provider, u.model, `${u.calls}${u.errors ? ` (${u.errors} ✕)` : ""}`, `${fmtNumber(u.input_tokens)} / ${fmtNumber(u.output_tokens)}`, `$${u.cost_usd.toFixed(4)}`])} />
        )}</div>
      </Card>
      <Card className="lg:col-span-2"><CardHeader title={t("admin.system.queues")} /><div className="p-4"><JsonBlock value={data.queue_backlog} /></div></Card>
    </div>
  );
}
