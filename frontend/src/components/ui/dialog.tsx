import * as RadixDialog from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import type { ReactNode } from "react";
import { useI18n } from "@/i18n";
import { cn } from "@/lib/utils";
import { Button } from "./button";

interface DialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  wide?: boolean;
}

export function Dialog({ open, onOpenChange, title, description, children, footer, wide }: DialogProps) {
  const { t } = useI18n();
  return (
    <RadixDialog.Root open={open} onOpenChange={onOpenChange}>
      <RadixDialog.Portal>
        <RadixDialog.Overlay className="fixed inset-0 z-40 bg-black/40 backdrop-blur-[1px]" />
        <RadixDialog.Content
          className={cn("fixed left-1/2 top-[8vh] z-50 -translate-x-1/2 w-[calc(100vw-2rem)] max-h-[84vh] flex flex-col",
            "rounded-lg border border-border bg-surface shadow-xl", wide ? "max-w-3xl" : "max-w-lg")}>
          <div className="flex items-start justify-between gap-4 border-b border-border px-4 py-3">
            <div>
              <RadixDialog.Title className="text-lg font-semibold">{title}</RadixDialog.Title>
              {description && <RadixDialog.Description className="mt-0.5 text-xs text-muted">{description}</RadixDialog.Description>}
            </div>
            <RadixDialog.Close asChild>
              <Button variant="ghost" size="icon" aria-label={t("common.close")}><X className="size-4" /></Button>
            </RadixDialog.Close>
          </div>
          <div className="overflow-y-auto px-4 py-3 scrollbar-thin">{children}</div>
          {footer && <div className="flex justify-end gap-2 border-t border-border px-4 py-3">{footer}</div>}
        </RadixDialog.Content>
      </RadixDialog.Portal>
    </RadixDialog.Root>
  );
}

/** Right-hand side panel (details, editors). */
export function Sheet({ open, onOpenChange, title, description, children, footer, width = "max-w-xl" }: DialogProps & { width?: string }) {
  const { t } = useI18n();
  return (
    <RadixDialog.Root open={open} onOpenChange={onOpenChange}>
      <RadixDialog.Portal>
        <RadixDialog.Overlay className="fixed inset-0 z-40 bg-black/30" />
        <RadixDialog.Content className={cn("fixed inset-y-0 right-0 z-50 flex w-full flex-col border-l border-border bg-surface shadow-xl", width)}>
          <div className="flex items-start justify-between gap-4 border-b border-border px-4 py-3">
            <div className="min-w-0">
              <RadixDialog.Title className="text-lg font-semibold truncate">{title}</RadixDialog.Title>
              {description && <RadixDialog.Description className="mt-0.5 text-xs text-muted">{description}</RadixDialog.Description>}
            </div>
            <RadixDialog.Close asChild>
              <Button variant="ghost" size="icon" aria-label={t("common.close")}><X className="size-4" /></Button>
            </RadixDialog.Close>
          </div>
          <div className="flex-1 overflow-y-auto px-4 py-3 scrollbar-thin">{children}</div>
          {footer && <div className="flex justify-end gap-2 border-t border-border px-4 py-3">{footer}</div>}
        </RadixDialog.Content>
      </RadixDialog.Portal>
    </RadixDialog.Root>
  );
}

interface ConfirmProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: ReactNode;
  body?: ReactNode;
  confirmLabel: string;
  onConfirm: () => void;
  danger?: boolean;
  loading?: boolean;
  /** Keeps the confirm button disabled, e.g. until a required reason is entered. */
  disabled?: boolean;
  children?: ReactNode;
}

export function ConfirmDialog({ open, onOpenChange, title, body, confirmLabel, onConfirm, danger, loading, disabled, children }: ConfirmProps) {
  const { t } = useI18n();
  return (
    <Dialog open={open} onOpenChange={onOpenChange} title={title}
      footer={<>
        <Button onClick={() => onOpenChange(false)}>{t("common.cancel")}</Button>
        <Button variant={danger ? "danger" : "primary"} loading={loading} disabled={disabled} onClick={onConfirm}>{confirmLabel}</Button>
      </>}>
      {body && <p className="text-sm text-muted">{body}</p>}
      {children}
    </Dialog>
  );
}
