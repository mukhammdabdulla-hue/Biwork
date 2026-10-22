import { toast } from "sonner";
import { ApiError } from "@/api/client";
import type { TFunction } from "@/i18n";

/** Message for an API failure: the problem detail (validation details are already in it). */
export function errorMessage(error: unknown, t: TFunction): string {
  if (!(error instanceof ApiError)) return t("common.error");
  if (error.status !== 403) return error.message;
  if (error.code === "module_disabled") return t("common.moduleDisabled");
  if (error.code === "department_restricted") return t("common.departmentRestricted");
  // Keep the server's reason (e.g. "Missing permission: master.write") after the translated sentence.
  return error.problem.detail ? `${t("common.forbidden")} ${error.problem.detail}` : t("common.forbidden");
}

export function toastError(error: unknown, t: TFunction) {
  const requestId = error instanceof ApiError ? error.problem.request_id : undefined;
  toast.error(errorMessage(error, t), { description: requestId ? `Request ${requestId}` : undefined });
}
