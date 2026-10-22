import * as RadixSwitch from "@radix-ui/react-switch";
import { cn } from "@/lib/utils";

export function Switch({ checked, onCheckedChange, disabled, id, label }: { checked: boolean; onCheckedChange: (v: boolean) => void; disabled?: boolean; id?: string; label?: string }) {
  return (
    <RadixSwitch.Root id={id} checked={checked} onCheckedChange={onCheckedChange} disabled={disabled} aria-label={label}
      className={cn("relative h-4.5 w-8 shrink-0 rounded-full border border-border-strong bg-surface-3 transition-colors",
        "data-[state=checked]:bg-primary data-[state=checked]:border-primary disabled:opacity-50")}>
      <RadixSwitch.Thumb className="block size-3.5 translate-x-0.5 rounded-full bg-white shadow transition-transform data-[state=checked]:translate-x-[14px]" />
    </RadixSwitch.Root>
  );
}
