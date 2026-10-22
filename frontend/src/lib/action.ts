import { useQueryClient } from "@tanstack/react-query";
import { useCallback, useState } from "react";
import { toast } from "sonner";
import { useI18n } from "@/i18n";
import { toastError } from "./notify";

/** Runs a write, reports the outcome and refreshes queries whose key contains `scope`. */
export function useAction(scope: string) {
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState<string | null>(null);
  const run = useCallback(async <T,>(name: string, fn: () => Promise<T>, success?: string): Promise<T | undefined> => {
    setBusy(name);
    try {
      const result = await fn();
      if (success) toast.success(success);
      await queryClient.invalidateQueries({ predicate: (q) => q.queryKey.includes(scope) || q.queryKey.includes("overview") });
      return result;
    } catch (e) {
      toastError(e, t);
      return undefined;
    } finally {
      setBusy(null);
    }
  }, [queryClient, scope, t]);
  return { run, busy };
}

export const ifMatch = (version: number) => ({ "If-Match": `W/"${version}"` });

export const todayIso = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};
