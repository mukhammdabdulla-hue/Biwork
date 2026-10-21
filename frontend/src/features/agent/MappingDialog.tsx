import { useQuery } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { toast } from "sonner";
import { api } from "@/api/client";
import { useCompanyKey, useRecordTypes } from "@/api/hooks";
import type { JobItem, TableInfo } from "@/api/types";
import { Badge, type Tone } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Checkbox, Field, Select } from "@/components/ui/inputs";
import { ConfidenceBar } from "@/components/ui/status";
import { useI18n, type MessageKey } from "@/i18n";
import { toastError } from "@/lib/notify";

const BY_TONE: Record<string, Tone> = { rule: "info", template: "success", ai: "violet", user: "success" };

interface Props {
  jobId: string;
  item: JobItem;
  table: TableInfo;
  onClose: () => void;
  onApplied: () => void;
}

/** Review and correct how spreadsheet columns map to record fields (re-reads every row). */
export function MappingDialog({ jobId, item, table, onClose, onApplied }: Props) {
  const { t } = useI18n();
  const company = useCompanyKey();
  const { data: recordTypes } = useRecordTypes();
  const [recordType, setRecordType] = useState(table.record_type ?? "");
  const [mapping, setMapping] = useState<Record<number, string>>(() =>
    Object.fromEntries(table.headers.map((_, i) => [i, table.mapping.find((m) => m.source_index === i)?.target_field ?? ""])));
  const [saveTemplate, setSaveTemplate] = useState(true);
  const [saving, setSaving] = useState(false);
  const sample = useQuery({
    queryKey: [company, "item-rows", item.id, table.table_seq, "sample"],
    queryFn: () => api.get<{ rows: { row_number: number; cells: unknown[] }[] }>(`/agent/items/${item.id}/preview`, { table_seq: table.table_seq, limit: 3 }),
  });
  const rt = recordTypes?.find((r) => r.code === recordType);
  const targets = useMemo(() => [...(rt?.fields ?? []), ...(rt?.line_fields ?? [])].filter((f) => f.type !== "lines"), [rt]);
  const used = Object.values(mapping).filter(Boolean);
  const missingRequired = (rt?.fields ?? []).filter((f) => f.required && !used.includes(f.name) && !(rt?.line_fields ?? []).length);

  const apply = async () => {
    setSaving(true);
    try {
      await api.put(`/agent/jobs/${jobId}/items/${item.id}/mapping`, {
        table_seq: table.table_seq, record_type: recordType,
        mapping: Object.fromEntries(Object.entries(mapping).map(([k, v]) => [k, v || null])), save_template: saveTemplate,
      });
      toast.success(t("agent.mapping.applied"));
      onApplied();
      onClose();
    } catch (e) {
      toastError(e, t);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()} wide title={t("agent.mapping.title")} description={t("agent.mapping.hint")}
      footer={<>
        <span className="mr-auto self-center"><Checkbox checked={saveTemplate} onChange={setSaveTemplate} label={<span className="text-xs">{t("agent.mapping.saveTemplate")}</span>} /></span>
        <Button onClick={onClose}>{t("common.cancel")}</Button>
        <Button variant="primary" loading={saving} disabled={!recordType} onClick={apply}>{t("agent.mapping.apply")}</Button>
      </>}>
      <div className="space-y-3">
        <Field label={t("agent.mapping.recordType")} className="w-64">
          {(id) => (
            <Select id={id} value={recordType} onChange={(e) => setRecordType(e.target.value)}>
              <option value="">—</option>
              {(recordTypes ?? []).filter((r) => r.enabled).map((r) => <option key={r.code} value={r.code}>{r.label}</option>)}
            </Select>
          )}
        </Field>
        <div className="overflow-x-auto rounded-md border border-border scrollbar-thin">
          <table className="w-full text-xs">
            <thead className="bg-surface-2 text-left text-muted">
              <tr className="border-b border-border">
                <th scope="col" className="px-3 py-1.5 font-medium">{t("agent.mapping.column")}</th>
                <th scope="col" className="px-3 py-1.5 font-medium">{t("agent.mapping.sample")}</th>
                <th scope="col" className="px-3 py-1.5 font-medium">{t("agent.mapping.field")}</th>
                <th scope="col" className="px-3 py-1.5 font-medium">{t("agent.mapping.proposedBy")}</th>
              </tr>
            </thead>
            <tbody>
              {table.headers.map((h, i) => {
                const m = table.mapping.find((x) => x.source_index === i);
                const changed = (m?.target_field ?? "") !== mapping[i];
                const duplicate = mapping[i] && used.filter((u) => u === mapping[i]).length > 1;
                return (
                  <tr key={i} className="border-b border-border last:border-0">
                    <td className="px-3 py-1.5 font-medium">{h || `#${i + 1}`}</td>
                    <td className="max-w-48 truncate px-3 py-1.5 text-muted">
                      {(sample.data?.rows ?? []).map((r) => r.cells[i]).filter((v) => v !== null && v !== undefined && v !== "").slice(0, 3).map(String).join(" · ") || "—"}
                    </td>
                    <td className="px-3 py-1.5">
                      <Select aria-label={`${t("agent.mapping.field")}: ${h}`} value={mapping[i]} onChange={(e) => setMapping({ ...mapping, [i]: e.target.value })}
                        className="h-7 text-xs" aria-invalid={!!duplicate}>
                        <option value="">{t("agent.mapping.ignore")}</option>
                        {targets.map((f) => <option key={f.name} value={f.name}>{f.label}{f.required ? " *" : ""}</option>)}
                      </Select>
                    </td>
                    <td className="px-3 py-1.5">
                      {changed ? <Badge tone="info">{t("agent.mapping.by.user")}</Badge> : m?.target_field ? (
                        <span className="inline-flex items-center gap-2">
                          <Badge tone={m.confirmed ? BY_TONE[m.mapped_by] : "warning"}>{t(`agent.mapping.by.${m.mapped_by}` as MessageKey)}</Badge>
                          <ConfidenceBar value={m.confidence} />
                        </span>
                      ) : "—"}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        {missingRequired.length > 0 && (
          <p className="text-xs text-warning">{t("agent.mapping.missingRequired", { fields: missingRequired.map((f) => f.label).join(", ") })}</p>
        )}
        {table.preamble && table.preamble.length > 0 && <p className="text-2xs text-subtle">{t("agent.mapping.preamble")}: {table.preamble.join(" · ")}</p>}
      </div>
    </Dialog>
  );
}
