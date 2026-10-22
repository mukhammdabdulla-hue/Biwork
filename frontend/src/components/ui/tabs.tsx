import * as RadixTabs from "@radix-ui/react-tabs";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export const Tabs = RadixTabs.Root;
export const TabsContent = RadixTabs.Content;

export function TabsList({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <RadixTabs.List className={cn("flex gap-0.5 border-b border-border overflow-x-auto scrollbar-thin", className)}>{children}</RadixTabs.List>
  );
}

export function TabsTrigger({ value, children, count }: { value: string; children: ReactNode; count?: number }) {
  return (
    <RadixTabs.Trigger value={value}
      className={cn("relative -mb-px inline-flex items-center gap-1.5 px-3 py-2 text-sm text-muted whitespace-nowrap border-b-2 border-transparent",
        "hover:text-fg data-[state=active]:text-fg data-[state=active]:border-primary data-[state=active]:font-medium")}>
      {children}
      {count !== undefined && <span className="rounded bg-surface-3 px-1.5 text-2xs text-muted tabular">{count}</span>}
    </RadixTabs.Trigger>
  );
}
