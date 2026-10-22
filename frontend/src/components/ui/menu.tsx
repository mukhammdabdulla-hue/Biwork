import * as Dropdown from "@radix-ui/react-dropdown-menu";
import * as RadixTooltip from "@radix-ui/react-tooltip";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export const Menu = Dropdown.Root;
export const MenuTrigger = Dropdown.Trigger;

export function MenuContent({ children, align = "end", className }: { children: ReactNode; align?: "start" | "end"; className?: string }) {
  return (
    <Dropdown.Portal>
      <Dropdown.Content align={align} sideOffset={6}
        className={cn("z-50 min-w-48 rounded-md border border-border bg-surface p-1 shadow-lg text-sm", className)}>
        {children}
      </Dropdown.Content>
    </Dropdown.Portal>
  );
}

export function MenuItem({ children, onSelect, danger, disabled }: { children: ReactNode; onSelect?: () => void; danger?: boolean; disabled?: boolean }) {
  return (
    <Dropdown.Item disabled={disabled} onSelect={onSelect}
      className={cn("flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 outline-none data-[highlighted]:bg-surface-3 data-[disabled]:opacity-50",
        danger && "text-danger")}>
      {children}
    </Dropdown.Item>
  );
}

export function MenuLabel({ children }: { children: ReactNode }) {
  return <Dropdown.Label className="px-2 py-1 text-2xs font-medium uppercase tracking-wide text-subtle">{children}</Dropdown.Label>;
}

export const MenuSeparator = () => <Dropdown.Separator className="my-1 h-px bg-border" />;

export function Tooltip({ content, children, side = "top" }: { content: ReactNode; children: ReactNode; side?: "top" | "bottom" | "left" | "right" }) {
  return (
    <RadixTooltip.Root delayDuration={250}>
      <RadixTooltip.Trigger asChild>{children}</RadixTooltip.Trigger>
      <RadixTooltip.Portal>
        <RadixTooltip.Content side={side} sideOffset={6}
          className="z-50 max-w-xs rounded bg-[#0c1a2e] px-2 py-1 text-xs text-white shadow-lg dark:bg-surface-3 dark:text-fg">
          {content}
        </RadixTooltip.Content>
      </RadixTooltip.Portal>
    </RadixTooltip.Root>
  );
}
