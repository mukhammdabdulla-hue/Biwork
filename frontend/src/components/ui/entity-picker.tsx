import * as Popover from "@radix-ui/react-popover";
import { useQuery } from "@tanstack/react-query";
import { Check, ChevronsUpDown, Search } from "lucide-react";
import { useState } from "react";
import { api } from "@/api/client";
import type { EntityCandidate } from "@/api/types";
import { useI18n } from "@/i18n";
import { cn } from "@/lib/utils";
import { Spinner } from "./feedback";

interface Props {
  entityType: string;
  value: { id: string | null; label?: string } | null;
  onSelect: (candidate: EntityCandidate) => void;
  placeholder?: string;
  disabled?: boolean;
  initialQuery?: string;
  className?: string;
}

/** Searches master data for a reference field (`/master/search/{entity}`) and lets a person choose. */
export function EntityPicker({ entityType, value, onSelect, placeholder, disabled, initialQuery = "", className }: Props) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState(initialQuery);
  const { data, isFetching } = useQuery({
    queryKey: ["entity-search", entityType, q],
    queryFn: () => api.get<EntityCandidate[]>(`/master/search/${entityType}`, { q }),
    enabled: open && q.trim().length > 0,
    staleTime: 30_000,
  });
  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      <Popover.Trigger asChild disabled={disabled}>
        <button type="button" className={cn("flex h-8 w-full items-center justify-between gap-2 rounded-md border border-border bg-surface px-2.5 text-left text-sm shadow-card",
          "hover:border-border-strong disabled:opacity-60", className)}>
          <span className={cn("truncate", !value?.id && "text-subtle")}>{value?.id ? value.label : placeholder ?? t("agent.record.chooseMatch")}</span>
          <ChevronsUpDown className="size-3.5 shrink-0 text-subtle" />
        </button>
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content align="start" sideOffset={4} className="z-50 w-[var(--radix-popover-trigger-width)] min-w-72 rounded-md border border-border bg-surface p-1.5 shadow-lg">
          <div className="flex items-center gap-2 border-b border-border px-1.5 pb-1.5">
            <Search className="size-3.5 text-subtle" />
            <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder={t("agent.record.searchEntity", { entity: entityType.replace("_", " ") })}
              className="h-7 w-full bg-transparent text-sm outline-none placeholder:text-subtle" />
            {isFetching && <Spinner className="size-3.5" />}
          </div>
          <ul className="max-h-64 overflow-y-auto py-1 scrollbar-thin" role="listbox">
            {(data ?? []).map((c) => (
              <li key={c.id}>
                <button type="button" role="option" aria-selected={value?.id === c.id}
                  onClick={() => { onSelect(c); setOpen(false); }}
                  className="flex w-full items-center justify-between gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-surface-3">
                  <span className="truncate">{c.label}</span>
                  <span className="flex items-center gap-1.5 text-2xs text-subtle">
                    {Math.round(c.score * 100)}%{value?.id === c.id && <Check className="size-3.5 text-primary" />}
                  </span>
                </button>
              </li>
            ))}
            {q && !isFetching && data?.length === 0 && <li className="px-2 py-2 text-xs text-muted">{t("agent.record.notMatched")}</li>}
          </ul>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}
