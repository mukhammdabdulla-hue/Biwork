import { useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Check, ChevronDown, ChevronUp, CircleAlert, Info, Plus, RotateCcw, Trash2, X } from "lucide-react";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { toast } from "sonner";
import { api } from "@/api/client";
import { useCompanyKey } from "@/api/hooks";
import type { FieldView, Issue, RecordDetail } from "@/api/types";
import { useAuth } from "@/auth/AuthProvider";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { EntityPicker } from "@/components/ui/entity-picker";
import { ErrorState, Kbd, Notice, Skeleton } from "@/components/ui/feedback";
import { Input, Select, Textarea } from "@/components/ui/inputs";
import { SectionTitle } from "@/components/ui/page";
import { ConfidenceBar, FieldStateBadge, StatusBadge } from "@/components/ui/status";
import { useI18n } from "@/i18n";
import { codeLabel, enumLabel, fieldLabel, issueFieldLabel, issueMessage, recordTypeLabel } from "@/i18n/labels";
import { toastError } from "@/lib/notify";
import { cn } from "@/lib/utils";
import { SourcePreview } from "./SourcePreview";

interface Props {
  jobId: string;
  recordId: string;
  editable: boolean;
  onChanged: () => void;
  onNext: () => void;
  onPrev: () => void;
  onClose: () => void;
}

type RefChoice = { id: string; label: string } | null;

const EDITABLE = ["draft", "needs_review", "failed"];

function display(value: unknown): string {
  if (value === null || value === undefined) return "";
  return typeof value === "object" ? JSON.stringify(value) : String(value);
}

function FieldEditor({ field, label, value, onChange, disabled }: { field: FieldView; label: string; value: string; onChange: (v: string) => void; disabled: boolean }) {
  const { t } = useI18n();
  const common = { "aria-label": label, disabled, className: "h-7 text-xs" };
  if (field.enum) {
    return (
      <Select {...common} value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">—</option>
        {field.enum.map((o) => <option key={o} value={o}>{enumLabel(t, field.field, o)}</option>)}
        {value && !field.enum.includes(value) && <option value={value}>{value}</option>}
      </Select>
    );
  }
  if (field.type === "date") {
    const iso = /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : "";
    return iso || !value ? <Input {...common} type="date" value={iso} onChange={(e) => onChange(e.target.value)} />
      : <Input {...common} value={value} onChange={(e) => onChange(e.target.value)} placeholder={t("agent.record.dateHint")} />;
  }
  return <Input {...common} value={value} inputMode={["quantity", "decimal", "money", "integer"].includes(field.type) ? "decimal" : undefined}
    onChange={(e) => onChange(e.target.value)} />;
}

function FieldRow({ field, record, draft, refChoice, onDraft, onRef, disabled, issues }: {
  field: FieldView; record: RecordDetail; draft: string | undefined; refChoice: RefChoice | undefined; disabled: boolean; issues: Issue[];
  onDraft: (v: string) => void; onRef: (c: RefChoice) => void;
}) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const label = fieldLabel(t, record.record_type, field.field, field.label);
  const value = draft ?? field.raw_value ?? "";
  const normalized = display(field.normalized_value);
  const matched = refChoice !== undefined ? refChoice : field.match?.id ? { id: field.match.id, label: field.match.label ?? field.match.id } : null;
  const source = field.sources[0] ?? null;
  const changed = draft !== undefined || refChoice !== undefined;
  const cleared = refChoice === null && (draft === "" || !field.raw_value);
  // A reviewer may remove a wrongly extracted optional reference, or any value that is invalid.
  const canClear = !disabled && (field.reference ? !!matched || (!!field.raw_value && !cleared) : !!value)
    && (field.reference ? !field.required || field.state === "invalid" : field.state === "invalid");
  const clear = () => {
    if (field.reference) onRef(null);
    onDraft("");
  };
  const clearButton = canClear && (
    <Button size="xs" variant="ghost" onClick={clear} aria-label={`${t("agent.record.clear")}: ${label}`}>
      <X className="size-3" />{t("agent.record.clear")}
    </Button>
  );
  const fieldIssues = issues.filter((i) => i.field === field.field);
  const tone = field.state === "invalid" || field.state === "conflicting" ? "border-l-danger" : field.state === "low_confidence" ? "border-l-warning"
    : field.state === "missing" && field.required ? "border-l-danger" : field.state === "needs_approval" ? "border-l-info" : "border-l-transparent";
  return (
    <div className={cn("border-b border-l-2 border-border px-3 py-2 last:border-b-0", tone, changed && "bg-primary-soft/30")}>
      <div className="grid grid-cols-1 items-start gap-x-3 gap-y-1 sm:grid-cols-[140px_minmax(0,1fr)_auto]">
        <div className="pt-1">
          <p className="text-xs font-medium">{label}{field.required && <span className="ml-0.5 text-danger">*</span>}</p>
          {field.extracted_by === "user" && <p className="text-2xs text-subtle">{field.edited_by ? t("agent.record.editedBy", { name: field.edited_by }) : t("agent.record.byUser")}</p>}
          {field.extracted_by === "derived" && <p className="text-2xs text-subtle">{t("agent.record.derived")}</p>}
        </div>
        <div className="min-w-0 space-y-1">
          {field.reference ? (
            <>
              <div className="flex items-center gap-1">
                <div className="min-w-0 flex-1">
                  <EntityPicker entityType={field.reference} value={matched} disabled={disabled} initialQuery={field.raw_value ?? ""}
                    onSelect={(c) => onRef({ id: c.id, label: c.label })} className="h-7 text-xs" />
                </div>
                {clearButton}
              </div>
              <p className="truncate text-2xs text-muted">
                {t("agent.record.extracted")}: <span className={cn("text-fg", cleared && field.raw_value && "line-through")}>{field.raw_value ?? "—"}</span>
                {cleared ? <> · {t("agent.record.willClear")}</>
                  : field.match?.match_kind && field.match.id && refChoice === undefined && <> · {codeLabel(t, "agent.matchKind", field.match.match_kind)} {field.match.score != null && `${Math.round(field.match.score * 100)}%`}</>}
              </p>
              {!matched && field.candidates.length > 0 && !disabled && (
                <div className="flex flex-wrap gap-1">
                  {field.candidates.slice(0, 4).map((c) => (
                    <button key={c.entity_id} type="button" onClick={() => onRef({ id: c.entity_id, label: c.label })}
                      className="rounded border border-border bg-surface-2 px-1.5 py-0.5 text-2xs hover:border-primary hover:text-primary">
                      {c.label} · {Math.round(c.score * 100)}%
                    </button>
                  ))}
                </div>
              )}
            </>
          ) : (
            <>
              <div className="flex items-center gap-1">
                <div className="min-w-0 flex-1"><FieldEditor field={field} label={label} value={value} onChange={onDraft} disabled={disabled} /></div>
                {clearButton}
              </div>
              {normalized && normalized !== value && draft === undefined && (
                <p className="truncate text-2xs text-muted">{t("agent.record.normalized")}: <span className="font-medium text-fg">{normalized}</span></p>
              )}
            </>
          )}
          {field.alternatives.length > 0 && (
            <p className="text-2xs text-warning">{t("agent.record.alternatives", { values: field.alternatives.join(", ") })}</p>
          )}
          {field.state_reason && field.state !== "high_confidence" && !fieldIssues.some((i) => i.message === field.state_reason) && <p className="text-2xs text-muted">{field.state_reason}</p>}
          {fieldIssues.map((i) => (
            <p key={i.id} className={cn("text-2xs", i.severity === "error" ? "text-danger" : i.severity === "warning" ? "text-warning" : "text-muted")}>{issueMessage(t, i)}</p>
          ))}
        </div>
        <div className="flex flex-wrap items-center gap-2 sm:flex-col sm:items-end">
          <FieldStateBadge state={field.state} />
          <ConfidenceBar value={field.confidence} />
          {(source || record.row_ref.row) && (
            <button type="button" onClick={() => setOpen((o) => !o)} aria-expanded={open} className="text-2xs text-primary hover:underline">
              {t("agent.record.source")}{open ? <ChevronUp className="ml-0.5 inline size-3" /> : <ChevronDown className="ml-0.5 inline size-3" />}
            </button>
          )}
        </div>
      </div>
      {open && <div className="mt-2"><SourcePreview record={record} source={source} /></div>}
    </div>
  );
}

function IssueIcon({ severity }: { severity: string }) {
  return severity === "error" ? <CircleAlert className="mt-0.5 size-3.5 shrink-0 text-danger" />
    : severity === "warning" ? <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-warning" /> : <Info className="mt-0.5 size-3.5 shrink-0 text-info" />;
}

export function RecordPanel({ jobId, recordId, editable, onChanged, onNext, onPrev, onClose }: Props) {
  const { t, fmtDateTime } = useI18n();
  const { can } = useAuth();
  const company = useCompanyKey();
  const queryClient = useQueryClient();
  const key = useMemo(() => [company, "agent-record", jobId, recordId], [company, jobId, recordId]);
  const { data: record, error, isLoading } = useQuery({ queryKey: key, queryFn: () => api.get<RecordDetail>(`/agent/records/${recordId}`) });
  const [draft, setDraft] = useState<Record<string, string>>({});
  const [refs, setRefs] = useState<Record<string, RefChoice>>({});
  const [addLines, setAddLines] = useState(0);
  const [removeLines, setRemoveLines] = useState<number[]>([]);
  const [busy, setBusy] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState(false);
  const [comment, setComment] = useState("");

  const dirty = Object.keys(draft).length > 0 || Object.keys(refs).length > 0 || addLines > 0 || removeLines.length > 0;
  const canEdit = editable && !!record && EDITABLE.includes(record.status) && can("agent.review");
  const canApprove = editable && !!record && record.status === "needs_review" && can("agent.review", record.approve_permission);
  const canReject = editable && !!record && ["draft", "needs_review", "approved", "failed"].includes(record.status) && can("agent.review");
  const canReopen = editable && !!record && ["approved", "rejected"].includes(record.status) && can("agent.review");
  const hasErrors = !!record?.issues.some((i) => i.severity === "error");

  const reset = () => {
    setDraft({});
    setRefs({});
    setAddLines(0);
    setRemoveLines([]);
  };

  const save = useCallback(async (): Promise<RecordDetail | null> => {
    if (!record) return null;
    const references: Record<string, string | null> = {};
    for (const [k, v] of Object.entries(refs)) references[k] = v?.id ?? null;
    // An emptied value is sent as null, which clears it.
    const fields = Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, v === "" ? null : v]));
    const updated = await api.patch<RecordDetail>(`/agent/records/${record.id}`,
      { fields, references, add_lines: addLines, remove_lines: removeLines }, { "If-Match": `W/"${record.payload_version}"` });
    queryClient.setQueryData(key, updated);
    reset();
    return updated;
  }, [record, refs, draft, addLines, removeLines, queryClient, key]);

  const act = useCallback(async (name: string, fn: () => Promise<unknown>, message?: string) => {
    setBusy(name);
    try {
      await fn();
      if (message) toast.success(message);
      await queryClient.invalidateQueries({ queryKey: key });
      onChanged();
    } catch (e) {
      toastError(e, t);
      await queryClient.invalidateQueries({ queryKey: key });
    } finally {
      setBusy(null);
    }
  }, [key, onChanged, queryClient, t]);

  const approve = useCallback(() => act("approve", async () => {
    const current = dirty ? await save() : record;
    if (!current) return;
    if (current.issues.some((i) => i.severity === "error")) {
      toast.error(t("agent.record.fixErrors"));
      return;
    }
    await api.post(`/agent/records/${current.id}/approve`, {}, { "If-Match": `W/"${current.payload_version}"` });
    toast.success(t("agent.record.approved"));
    onNext();
  }), [act, dirty, save, record, onNext, t]);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;
      if (["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName) || target.isContentEditable || e.metaKey || e.ctrlKey || e.altKey) return;
      if (document.querySelector("[role=dialog]")) return;
      if (e.key === "j") onNext();
      else if (e.key === "k") onPrev();
      else if (e.key === "a" && canApprove && !hasErrors) void approve();
      else if (e.key === "r" && canReject) setRejecting(true);
      else if (e.key === "Escape") onClose();
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [onNext, onPrev, onClose, approve, canApprove, canReject, hasErrors]);

  if (error) return <ErrorState error={error} className="m-4" />;
  if (isLoading || !record) return <div className="space-y-2 p-4">{[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-9 w-full" />)}</div>;

  const issues = record.issues;
  const general = issues.filter((i) => !i.field || !record.fields.some((f) => f.field === i.field) && !record.lines.flat().some((f) => f.field === i.field));
  const ref = record.row_ref;
  const rowProps = (f: FieldView) => ({
    field: f, record, issues, disabled: !canEdit, draft: draft[f.field], refChoice: refs[f.field],
    onDraft: (v: string) => setDraft((d) => (v === (f.raw_value ?? "") ? Object.fromEntries(Object.entries(d).filter(([k]) => k !== f.field)) : { ...d, [f.field]: v })),
    onRef: (c: RefChoice) => {
      setRefs((r) => ({ ...r, [f.field]: c }));
      // Choosing an entity again undoes an earlier "clear" of the extracted text.
      if (c) setDraft((d) => (d[f.field] === "" ? Object.fromEntries(Object.entries(d).filter(([k]) => k !== f.field)) : d));
    },
  });

  return (
    <section className="flex flex-col" aria-labelledby={`record-${record.id}`}>
      <div className="flex flex-wrap items-start gap-2 border-b border-border px-4 py-3">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h3 id={`record-${record.id}`} className="text-base font-semibold">{recordTypeLabel(t, record.record_type, record.label)} #{record.seq}</h3>
            <StatusBadge status={record.status} />
            {record.status === "needs_review" && <StatusBadge status={record.review_group} />}
            {record.committed_number && <Badge tone="success">{t("agent.record.savedAs", { number: record.committed_number })}</Badge>}
          </div>
          <p className="mt-0.5 truncate text-xs text-muted">
            {record.summary}
            {ref.sheet && ` · ${t("agent.record.sheet", { name: ref.sheet })}`}
            {ref.row && ` · ${t("agent.record.row", { n: ref.row })}`}
            {ref.page && ` · ${t("agent.record.pageN", { n: ref.page })}`}
          </p>
        </div>
        <div className="flex items-center gap-1">
          <Button variant="ghost" size="icon" onClick={onPrev} aria-label={t("agent.record.prev")}><ChevronUp className="size-4" /></Button>
          <Button variant="ghost" size="icon" onClick={onNext} aria-label={t("agent.record.next")}><ChevronDown className="size-4" /></Button>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label={t("common.close")} className="lg:hidden"><X className="size-4" /></Button>
        </div>
      </div>

      <div className="space-y-4 p-4">
        {record.error && <Notice tone="danger">{record.error}</Notice>}
        {record.status === "needs_review" && canApprove && <Notice tone="info">{t("agent.record.approveHint")}</Notice>}

        <section>
          <SectionTitle>{t("agent.record.fields")}</SectionTitle>
          <div className="overflow-hidden rounded-md border border-border">
            {record.fields.map((f) => <FieldRow key={f.field} {...rowProps(f)} />)}
          </div>
        </section>

        {record.has_lines && (
          <section>
            <SectionTitle actions={canEdit && (
              <Button size="xs" variant="ghost" onClick={() => setAddLines((n) => n + 1)}><Plus className="size-3" />{t("agent.record.addLine")}{addLines > 0 && ` (+${addLines})`}</Button>
            )}>{t("agent.record.lines")} ({record.lines.length})</SectionTitle>
            <div className="space-y-2">
              {record.lines.map((line, i) => {
                const removed = removeLines.includes(i);
                return (
                  <div key={i} className={cn("overflow-hidden rounded-md border border-border", removed && "opacity-50")}>
                    <div className="flex items-center justify-between bg-surface-2 px-3 py-1 text-2xs text-muted">
                      <span>{t("agent.record.lineN", { n: i + 1 })}</span>
                      {canEdit && (
                        <Button size="xs" variant="ghost" onClick={() => setRemoveLines((r) => removed ? r.filter((x) => x !== i) : [...r, i])}>
                          {removed ? <RotateCcw className="size-3" /> : <Trash2 className="size-3" />}{removed ? t("common.cancel") : t("agent.record.removeLine")}
                        </Button>
                      )}
                    </div>
                    {line.map((f) => <FieldRow key={f.field} {...rowProps(f)} disabled={!canEdit || removed} />)}
                  </div>
                );
              })}
            </div>
          </section>
        )}

        <section>
          <SectionTitle>{t("agent.record.issues")}</SectionTitle>
          {issues.length === 0 ? <p className="flex items-center gap-1.5 text-xs text-success"><Check className="size-3.5" />{t("agent.record.noIssues")}</p> : (
            <ul className="space-y-1">
              {(general.length ? general : issues).map((i) => (
                <li key={i.id} className="flex items-start gap-1.5 text-xs">
                  <IssueIcon severity={i.severity} />
                  <span><span className="font-medium">{t(`agent.severity.${i.severity}`)}</span>{i.field && <span className="text-muted"> · {issueFieldLabel(t, record.record_type, i.field)}</span>}: {issueMessage(t, i)}</span>
                </li>
              ))}
            </ul>
          )}
        </section>

        {record.duplicates.length > 0 && (
          <section>
            <SectionTitle>{t("agent.record.duplicates")}</SectionTitle>
            <ul className="space-y-1">
              {record.duplicates.map((d) => (
                <li key={`${d.entity_type}-${d.entity_id}`} className="flex items-center gap-2 rounded-md border border-warning/30 bg-warning-soft px-2.5 py-1.5 text-xs">
                  <AlertTriangle className="size-3.5 text-warning" />
                  <span className="flex-1 truncate">{d.label}</span>
                  <Badge tone="warning">{codeLabel(t, "agent.dupKind", d.kind)}</Badge>
                  {can("audit.read") && d.entity_type !== "extracted_record" && <Link className="text-primary hover:underline" to={`/trace/${d.entity_type}/${d.entity_id}`}>{t("common.open")}</Link>}
                </li>
              ))}
            </ul>
          </section>
        )}

        {record.approvals.length > 0 && (
          <section>
            <SectionTitle>{t("agent.record.history")}</SectionTitle>
            <ul className="space-y-1 text-xs">
              {record.approvals.map((a, i) => (
                <li key={i} className="flex flex-wrap gap-x-2">
                  <span className="font-medium">{codeLabel(t, "agent.decision", a.decision)}</span>
                  <span className="text-muted">{a.by ?? "—"}{a.auto && ` (${t("agent.record.auto")})`} · {fmtDateTime(a.at)}</span>
                  {a.comment && <span className="w-full text-muted">“{a.comment}”</span>}
                </li>
              ))}
            </ul>
          </section>
        )}
        {record.committed && can("audit.read") && (
          <Link to={`/trace/${record.committed.target_table}/${record.committed.target_id}`} className="inline-block text-xs text-primary hover:underline">{t("agent.record.openTrace")} →</Link>
        )}
      </div>

      <div className="sticky bottom-0 flex flex-wrap items-center gap-2 border-t border-border bg-surface px-4 py-2.5">
        <p className="hidden flex-1 text-2xs text-subtle md:block"><Kbd>J</Kbd>/<Kbd>K</Kbd> {t("agent.record.navKeys")} · <Kbd>A</Kbd> {t("common.approve")} · <Kbd>R</Kbd> {t("common.reject")}</p>
        {dirty && <span className="text-2xs text-warning">{t("agent.record.unsaved")}</span>}
        {dirty && <Button size="sm" variant="ghost" onClick={reset}>{t("common.cancel")}</Button>}
        {canEdit && <Button size="sm" disabled={!dirty} loading={busy === "save"} onClick={() => act("save", save, t("common.saved"))}>{t("common.saveChanges")}</Button>}
        {canReopen && <Button size="sm" loading={busy === "reopen"} onClick={() => act("reopen", () => api.post(`/agent/records/${record.id}/reopen`))}><RotateCcw className="size-3.5" />{t("common.reopen")}</Button>}
        {canReject && <Button size="sm" variant="ghost" className="text-danger" onClick={() => setRejecting(true)}><X className="size-3.5" />{t("common.reject")}</Button>}
        {canApprove && (
          <Button size="sm" variant="success" disabled={hasErrors && !dirty} loading={busy === "approve"} onClick={approve}
            title={hasErrors ? t("agent.record.fixErrors") : undefined}>
            <Check className="size-3.5" />{dirty ? t("agent.record.saveApprove") : t("common.approve")}
          </Button>
        )}
      </div>

      <Dialog open={rejecting} onOpenChange={setRejecting} title={t("agent.record.rejectPrompt")}
        footer={<>
          <Button onClick={() => setRejecting(false)}>{t("common.cancel")}</Button>
          <Button variant="danger" loading={busy === "reject"} onClick={() => act("reject", async () => {
            await api.post(`/agent/records/${record.id}/reject`, { comment: comment || null });
            setRejecting(false);
            setComment("");
            onNext();
          }, t("agent.record.rejected"))}>{t("common.reject")}</Button>
        </>}>
        <Textarea autoFocus value={comment} onChange={(e) => setComment(e.target.value)} aria-label={t("common.reason")} placeholder={t("common.reason")} />
      </Dialog>
    </section>
  );
}
