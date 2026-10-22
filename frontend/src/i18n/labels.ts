/**
 * Labels for codes the API returns (record types, fields, enum values, stages, audit actions…).
 * Keys are derived from the code; an unknown code falls back to the API's English label, then to
 * the code itself, so new backend codes still render.
 */
import { en } from "./en";
import { hasMessage, type TFunction } from "./index";

/** A backend code (decision, match kind…) in the user's language, or the fallback / readable code when unknown. */
export function codeLabel(t: TFunction, prefix: string, code: string, fallback?: string | null): string {
  const key = `${prefix}.${code}`;
  return hasMessage(key) ? t(key) : fallback || code.replace(/_/g, " ");
}

export function recordTypeLabel(t: TFunction, code: string, fallback?: string | null): string {
  return codeLabel(t, "recordType", code, fallback);
}

/** Field label; `field` may be a line path such as "lines.0.item". Record-type keys override the generic one. */
export function fieldLabel(t: TFunction, recordType: string | null | undefined, field: string, fallback?: string | null): string {
  const name = field.split(".").pop() ?? field;
  for (const key of [`field.${recordType}.${name}`, `field.${name}`]) if (hasMessage(key)) return t(key);
  return fallback || name.replace(/_/g, " ");
}

/** Where a validation issue points: the field label, with the item number for line fields. */
export function issueFieldLabel(t: TFunction, recordType: string | null | undefined, field: string): string {
  const line = /^lines\.(\d+)(?:\.|$)/.exec(field);
  if (!line) return fieldLabel(t, recordType, field);
  const item = t("agent.record.lineN", { n: Number(line[1]) + 1 });
  return field.split(".").length > 2 ? `${item} · ${fieldLabel(t, recordType, field)}` : item;
}

/** Enum fields whose values share dictionaries with the rest of the UI. */
const ENUM_PREFIX: Record<string, string> = {
  priority: "priority", movement_type: "movement", product_type: "ptype", doc_type: "docType", status: "employeeStatus",
};

export function enumLabel(t: TFunction, field: string, value: string): string {
  const prefix = ENUM_PREFIX[field];
  return prefix ? codeLabel(t, prefix, value, value) : value;
}

export function stageLabel(t: TFunction, stage: string): string {
  return codeLabel(t, "agent.stage", stage, stage);
}

/** Entity types the matcher links to (product, department, shift…). */
export function entityLabel(t: TFunction, entityType: string): string {
  return codeLabel(t, "entity", entityType);
}

/** Validation messages are English; rules whose message has no parameters are shown translated. */
export function issueMessage(t: TFunction, issue: { rule_code?: string; rule?: string; field: string | null; message: string }): string {
  const code = issue.rule_code ?? issue.rule ?? "";
  const name = issue.field?.split(".").pop();
  for (const key of [name && `rule.${code}.${name}`, `rule.${code}`]) if (key && hasMessage(key)) return t(key);
  return issue.message;
}

/** Audit action ("agent.record.corrected") in the user's language, else the event summary, else the code. */
export function auditActionLabel(t: TFunction, action: string, summary?: string | null): string {
  const key = `audit.${action}`;
  return hasMessage(key) ? t(key) : summary || action;
}

export function moduleName(t: TFunction, code: string, fallback?: string | null): string {
  return codeLabel(t, "nav", code, fallback);
}

export function moduleDescription(t: TFunction, code: string, fallback?: string | null): string {
  const key = `moduleInfo.${code}`;
  return hasMessage(key) ? t(key) : fallback ?? "";
}

/** System role names and descriptions are translated; a custom role keeps what the company typed. */
export function roleName(t: TFunction, role: { code: string; name: string }): string {
  const key = `role.${role.code}`;
  return hasMessage(key) && en[key] === role.name ? t(key) : role.name;
}

export function roleDescription(t: TFunction, role: { code: string; description: string | null }): string | null {
  const key = `role.${role.code}.description`;
  return hasMessage(key) && en[key] === role.description ? t(key) : role.description;
}
