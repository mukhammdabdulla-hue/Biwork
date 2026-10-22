import { useQuery } from "@tanstack/react-query";
import { ExternalLink } from "lucide-react";
import { useEffect, useState } from "react";
import { api, ApiError } from "@/api/client";
import { useCompanyKey } from "@/api/hooks";
import type { ItemPreview, RecordDetail, SourceRef } from "@/api/types";
import { Button } from "@/components/ui/button";
import { Spinner } from "@/components/ui/feedback";
import { useI18n } from "@/i18n";
import { cn } from "@/lib/utils";

function columnIndex(cell: string | null): number | null {
  const letters = cell?.match(/^[A-Z]+/)?.[0];
  if (!letters) return null;
  return [...letters].reduce((n, c) => n * 26 + c.charCodeAt(0) - 64, 0) - 1;
}

const IMAGE_FORMATS = ["png", "jpg", "jpeg", "tiff", "webp", "bmp"];

/** Shows where a value came from: the text span, the PDF/scan line, or the spreadsheet cell. */
export function SourcePreview({ record, source }: { record: RecordDetail; source: SourceRef | null }) {
  const { t } = useI18n();
  const company = useCompanyKey();
  const itemId = record.source.item_id;
  const tableSeq = record.row_ref.table_seq;
  const row = record.row_ref.row;
  const isTable = tableSeq !== undefined && !!row;

  const preview = useQuery({
    queryKey: [company, "item-preview", itemId],
    queryFn: () => api.get<ItemPreview>(`/agent/items/${itemId}/preview`),
    enabled: !isTable,
    staleTime: Infinity,
  });
  const headerRow = preview.data?.tables.find((tb) => tb.table_seq === tableSeq)?.header_row ?? 1;
  const offset = Math.max(0, (row ?? 0) - headerRow - 4);
  const rows = useQuery({
    queryKey: [company, "item-rows", itemId, tableSeq, offset],
    queryFn: () => api.get<{ rows: { row_number: number; cells: unknown[] }[] }>(`/agent/items/${itemId}/preview`, { table_seq: tableSeq, offset, limit: 9 }),
    enabled: isTable,
    staleTime: Infinity,
  });

  const [imageUrl, setImageUrl] = useState<string | null>(null);
  const isImage = IMAGE_FORMATS.includes(record.source.format) && !!record.source.file_id;
  useEffect(() => {
    if (!isImage) return;
    let url: string | null = null;
    api.objectUrl(`/files/${record.source.file_id}`).then((u) => { url = u; setImageUrl(u); }).catch(() => setImageUrl(null));
    return () => { if (url) URL.revokeObjectURL(url); };
  }, [isImage, record.source.file_id]);

  const openOriginal = async () => {
    const url = await api.objectUrl(`/files/${record.source.file_id}`);
    window.open(url, "_blank", "noopener");
  };

  const location = source && [source.sheet && t("agent.record.sheet", { name: source.sheet }), source.cell && t("agent.record.cell", { cell: source.cell }),
    source.page && t("agent.record.pageN", { n: source.page })].filter(Boolean).join(" · ");

  const restricted = [preview.error, rows.error].some((e) => e instanceof ApiError && e.code === "department_restricted");

  let body: React.ReactNode = null;
  if (restricted) {
    body = <p className="text-xs text-muted">{t("common.departmentRestricted")}</p>;
  } else if (isTable) {
    const col = columnIndex(source?.cell ?? null);
    const data = rows.data?.rows ?? [];
    body = rows.isLoading ? <Spinner /> : (
      <div className="overflow-x-auto scrollbar-thin">
        <table className="text-2xs">
          <tbody>
            {data.map((r) => (
              <tr key={r.row_number} className={cn("border-b border-border", r.row_number === row && "bg-primary-soft/60")}>
                <th scope="row" className="px-1.5 py-1 text-right font-normal text-subtle tabular">{r.row_number}</th>
                {r.cells.map((c, i) => (
                  <td key={i} className={cn("max-w-40 truncate px-1.5 py-1", r.row_number === row && i === col && "rounded bg-warning-soft font-semibold text-fg ring-1 ring-warning")}>
                    {c === null || c === undefined ? "" : String(c)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  } else if (preview.isLoading) {
    body = <Spinner />;
  } else if (preview.data) {
    const page = source?.page ? preview.data.pages.find((p) => p.page === source.page) : preview.data.pages[0];
    const bbox = source?.bbox;
    if (page?.lines?.length && page.width && page.height) {
      const w = page.width;
      const h = page.height;
      const focusY = bbox ? Math.max(0, bbox[1] - h * 0.12) : 0;
      const viewH = Math.min(h, h * 0.4);
      body = (
        <div className="relative overflow-hidden rounded border border-border bg-white">
          {isImage && imageUrl && <img src={imageUrl} alt="" className="absolute inset-0 w-full" style={{ transform: `translateY(-${(focusY / h) * 100}%)` }} />}
          <svg viewBox={`0 ${focusY} ${w} ${viewH}`} className="relative block w-full" role="img" aria-label={t("agent.preview.text")}>
            {!isImage && page.lines.map((ln, i) => (
              <text key={i} x={ln.bbox[0]} y={ln.bbox[3] - (ln.bbox[3] - ln.bbox[1]) * 0.2} fontSize={Math.max(6, (ln.bbox[3] - ln.bbox[1]) * 0.8)} fill="#334155">{ln.text}</text>
            ))}
            {bbox && <rect x={bbox[0] - 3} y={bbox[1] - 3} width={bbox[2] - bbox[0] + 6} height={bbox[3] - bbox[1] + 6} fill="rgb(245 158 11 / 0.18)" stroke="#d97706" strokeWidth={Math.max(1, w / 400)} rx={2} />}
          </svg>
        </div>
      );
    } else if (preview.data.full_text && source?.text_start != null && source.text_end != null) {
      const text = preview.data.full_text;
      const from = Math.max(0, source.text_start - 160);
      const to = Math.min(text.length, source.text_end + 160);
      body = (
        <p className="whitespace-pre-wrap rounded border border-border bg-surface-2 p-2 text-xs leading-relaxed">
          {from > 0 && "…"}{text.slice(from, source.text_start)}
          <mark className="rounded bg-warning-soft px-0.5 font-semibold text-fg ring-1 ring-warning">{text.slice(source.text_start, source.text_end)}</mark>
          {text.slice(source.text_end, to)}{to < text.length && "…"}
        </p>
      );
    }
  }
  if (!body && source?.excerpt) body = <p className="whitespace-pre-wrap rounded border border-border bg-surface-2 p-2 text-xs">{source.excerpt}</p>;

  return (
    <div className="space-y-1.5">
      <div className="flex items-center gap-2 text-2xs text-muted">
        <span className="font-medium uppercase tracking-wide text-subtle">{t("agent.preview.title")}</span>
        {location && <span>{location}</span>}
        {record.source.ocr_used && <span>· OCR</span>}
        <span className="flex-1" />
        {record.source.file_id && <Button size="xs" variant="ghost" onClick={openOriginal}><ExternalLink className="size-3" />{t("agent.preview.original")}</Button>}
      </div>
      {body ?? <p className="text-xs text-subtle">{t("agent.record.noSource")}</p>}
    </div>
  );
}
