import { toast } from "sonner";
import { ApiError } from "@/api/client";
import type { TFunction } from "@/i18n";

/** Message for an API failure: the problem detail (validation details are already in it). */
export function errorMessage(error: unknown, t: TFunction): string {
  if (error instanceof ApiError) return error.status === 403 ? t("common.forbidden") : error.message;
  return t("common.error");
}

export function toastError(error: unknown, t: TFunction) {
  const requestId = error instanceof ApiError ? error.problem.request_id : undefined;
  toast.error(errorMessage(error, t), { description: requestId ? `Request ${requestId}` : undefined });
}
