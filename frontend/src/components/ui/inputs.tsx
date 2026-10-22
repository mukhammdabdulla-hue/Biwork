import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from "react";
import { cn } from "@/lib/utils";

const base =
  "w-full rounded-md border border-border bg-surface px-2.5 text-sm text-fg placeholder:text-subtle shadow-card " +
  "focus:border-primary focus:outline-none focus:ring-2 focus:ring-[var(--ring)] disabled:opacity-60 aria-[invalid=true]:border-danger";

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(({ className, ...props }, ref) => (
  <input ref={ref} className={cn(base, "h-8", className)} {...props} />
));
Input.displayName = "Input";

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(({ className, ...props }, ref) => (
  <textarea ref={ref} className={cn(base, "py-2 leading-relaxed min-h-20 resize-y", className)} {...props} />
));
Textarea.displayName = "Textarea";

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(({ className, children, ...props }, ref) => (
  <select ref={ref} className={cn(base, "h-8 pr-7 appearance-none bg-[length:12px] bg-[right_8px_center] bg-no-repeat", className)}
    style={{ backgroundImage: "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238a94a4' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")" }}
    {...props}>
    {children}
  </select>
));
Select.displayName = "Select";

export function Label({ htmlFor, children, className }: { htmlFor?: string; children: ReactNode; className?: string }) {
  return (
    <label htmlFor={htmlFor} className={cn("block text-xs font-medium text-muted mb-1", className)}>
      {children}
    </label>
  );
}

interface FieldProps {
  label: ReactNode;
  hint?: ReactNode;
  error?: string;
  required?: boolean;
  children: (id: string) => ReactNode;
  className?: string;
}

/** Label + control + hint/error, wired with ids for accessibility. */
export function Field({ label, hint, error, required, children, className }: FieldProps) {
  const id = useId();
  return (
    <div className={className}>
      <Label htmlFor={id}>
        {label}
        {required && <span className="text-danger ml-0.5">*</span>}
      </Label>
      {children(id)}
      {error ? (
        <p className="mt-1 text-xs text-danger" role="alert">{error}</p>
      ) : hint ? (
        <p className="mt-1 text-xs text-subtle">{hint}</p>
      ) : null}
    </div>
  );
}

export function Checkbox({ checked, onChange, label, disabled, id }: { checked: boolean; onChange: (v: boolean) => void; label?: ReactNode; disabled?: boolean; id?: string }) {
  return (
    <label className={cn("inline-flex items-center gap-2 text-sm cursor-pointer", disabled && "opacity-60 cursor-not-allowed")}>
      <input id={id} type="checkbox" className="size-3.5 accent-[var(--primary)]" checked={checked} disabled={disabled}
        onChange={(e) => onChange(e.target.checked)} />
      {label}
    </label>
  );
}
