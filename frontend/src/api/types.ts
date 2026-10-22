/**
 * Response shapes of the BIWORK API. Request bodies use the generated OpenAPI types
 * (`schema.d.ts`); responses are typed here because most endpoints return documents
 * assembled by serializers (see backend routers).
 */
import type { components } from "./schema";

export type Schemas = components["schemas"];
export type Num = number | string; // decimals are serialized as strings

export interface Page<T> {
  items: T[];
  next_cursor: string | null;
}

// --------------------------------------------------------------------------- identity

export interface CompanyMembership {
  id: string;
  name: string;
  code: string;
  is_sample_data: boolean;
  roles: { code: string; name: string }[];
  permissions: string[];
  modules: string[];
}

export interface Me {
  id: string;
  email: string;
  full_name: string;
  locale: string;
  is_platform_admin: boolean;
  mfa_enabled: boolean;
  companies: CompanyMembership[];
}

export interface ModuleInfo {
  code: string;
  name: string;
  description: string;
  available: boolean;
  phase: number;
  enabled: boolean;
  nav: { path: string; label: string; icon: string } | null;
}

export interface AiPolicy {
  external_ai_allowed: boolean;
  allowed_providers: string[];
  send_images_to_ai: boolean;
  redaction_rules: string[];
  minimize_context: boolean;
  retain_ai_payloads_days: number;
}

export interface CompanySettings {
  locale?: string;
  timezone?: string;
  default_currency?: string;
  date_order?: "DMY" | "MDY" | "YMD";
  production_output_warehouse_id?: string | null;
  ai_policy?: AiPolicy;
}

export interface CompanyDetail {
  id: string;
  name: string;
  code: string;
  tin: string | null;
  is_sample_data: boolean;
  settings: CompanySettings;
  modules: ModuleInfo[];
}

export interface Member {
  id: string;
  user_id: string;
  email: string;
  full_name: string;
  role: { id: string; code: string; name: string };
  is_active: boolean;
  department_ids: string[];
  last_login_at: string | null;
  mfa_enabled: boolean;
}

export interface Role {
  id: string;
  code: string;
  name: string;
  description: string | null;
  is_system: boolean;
  permissions: string[];
}

export interface Department {
  id: string;
  code: string;
  name: string;
  kind: "workshop" | "warehouse" | "office" | "other";
  parent_id: string | null;
  is_active: boolean;
  aliases: string[];
}

export interface ApiClient {
  id: string;
  name: string;
  key_prefix: string;
  scopes: string[];
  created_at: string;
  last_used_at: string | null;
  expires_at: string | null;
  revoked_at: string | null;
}

export interface Workflow {
  record_type: string;
  label: string;
  module: string;
  approve_permission: string;
  auto_approve: boolean;
  confidence_threshold: number;
}

// --------------------------------------------------------------------------- master data

export interface Unit {
  code: string;
  name: string;
  dimension: string;
  factor_to_base: Num;
  is_base: boolean;
  aliases: string[];
}

export interface Product {
  id: string;
  code: string;
  name: string;
  base_unit: string;
  product_type: string;
  category_id: string | null;
  reorder_level: Num | null;
  is_active: boolean;
  aliases: string[];
  version: number;
  source_record_id: string | null;
  created_at: string;
  updated_at: string;
}

export interface Category {
  id: string;
  code: string;
  name: string;
  parent_id: string | null;
}

export interface EntityCandidate {
  id: string;
  label: string;
  score: number;
  match_kind: string;
  data: Record<string, unknown>;
}

// --------------------------------------------------------------------------- agent

export type JobStatus = "queued" | "processing" | "awaiting_review" | "committing" | "completed" | "failed" | "cancelled";
export type RecordStatus = "draft" | "needs_review" | "approved" | "rejected" | "committed" | "failed";
export type ReviewGroup = "ready" | "needs_correction" | "needs_approval";
export type FieldState = "high_confidence" | "low_confidence" | "missing" | "conflicting" | "invalid" | "needs_approval";

export interface JobReport {
  job_id: string;
  status: JobStatus;
  messages: string[];
  records_by_status: Record<string, number>;
  review_groups: Record<string, number>;
  fields_extracted: number;
  fields_validated: number;
  entities_matched: Record<string, number>;
  saved: { seq: number; number: string | null; target_table: string; target_id: string; committed_at: string }[];
  ai: { calls: number; input_tokens: number; output_tokens: number; cost_usd: number; provider: string; model: string; is_mock: boolean };
  pipeline_version: string;
  prompt_version: string;
  warnings: string[];
}

export interface Job {
  id: string;
  title: string | null;
  status: JobStatus;
  current_stage: string | null;
  source_kind: string;
  channel: string;
  import_policy: string;
  target_record_type: string | null;
  created_at: string;
  started_at: string | null;
  finished_at: string | null;
  submitted_by: string | null;
  stats: { records?: Record<string, number>; total_records?: number; [key: string]: unknown };
  warnings: string[];
  error: { stage?: string; message?: string; code?: string; retryable?: boolean } | null;
  attempt: number;
  ai_provider: string | null;
  ai_model: string | null;
  ai_is_mock: boolean;
  prompt_version: string | null;
  pipeline_version: string;
  duplicate_of_job_id: string | null;
  items: number | null;
}

export interface ColumnMap {
  source_index: number;
  source_column: string;
  target_field: string | null;
  confidence: number;
  mapped_by: "rule" | "template" | "ai" | "user";
  confirmed: boolean;
}

export interface TableInfo {
  table_seq: number;
  name: string;
  sheet: string | null;
  headers: string[];
  header_row: number | null;
  n_rows: number | null;
  column_types: string[] | null;
  preamble: string[] | null;
  record_type: string | null;
  mapping: ColumnMap[];
}

export interface JobItem {
  id: string;
  position: number;
  format: string;
  status: "pending" | "parsed" | "interpreted" | "failed";
  error: string | null;
  file: { id: string; name: string; size: number; mime_type: string; scan_status: string } | null;
  text_preview: string | null;
  parser: string | null;
  ocr_used: boolean;
  ocr_confidence: number | null;
  page_count: number | null;
  warnings: string[];
  detected_record_type: string | null;
  is_tabular: boolean;
  tables: TableInfo[];
}

export interface StageRun {
  stage: string;
  status: "running" | "succeeded" | "failed";
  attempt: number;
  item_id: string | null;
  started_at: string;
  finished_at: string | null;
  duration_ms: number | null;
  error: string | null;
  metrics: Record<string, unknown>;
}

export interface JobDetail extends Job {
  items_detail: JobItem[];
  stage_runs: StageRun[];
  report: JobReport;
}

export interface RecordSummary {
  id: string;
  seq: number;
  job_id: string;
  item_id: string;
  record_type: string;
  target_module: string;
  status: RecordStatus;
  review_group: ReviewGroup | null;
  summary: string | null;
  overall_confidence: number | null;
  row_ref: { sheet?: string | null; row?: number; rows?: number[]; page?: number | null; table?: string; table_seq?: number; form?: boolean };
  error: string | null;
  payload_version: number;
  issues: Record<string, number>;
  committed_number: string | null;
  reviewed_at: string | null;
}

export interface RecordPage {
  total: number;
  offset: number;
  groups: Partial<Record<ReviewGroup, number>>;
  statuses: Partial<Record<RecordStatus, number>>;
  items: RecordSummary[];
}

export interface SourceRef {
  page: number | null;
  sheet: string | null;
  cell: string | null;
  bbox: number[] | null;
  text_start: number | null;
  text_end: number | null;
  excerpt: string | null;
}

export interface FieldView {
  field: string;
  label: string;
  type: string;
  required: boolean;
  enum: string[] | null;
  reference: string | null;
  raw_value: string | null;
  normalized_value: string | number | boolean | null;
  confidence: number | null;
  state: FieldState;
  state_reason: string | null;
  extracted_by: string;
  edited_by: string | null;
  edited_at: string | null;
  alternatives: string[];
  match: { id: string | null; label?: string; match_kind?: string; score?: number; text?: string; reason?: string } | null;
  candidates: { entity_id: string; label: string; score: number; match_kind: string; selected: boolean }[];
  sources: SourceRef[];
}

export interface Issue {
  id: number;
  field: string | null;
  severity: "error" | "warning" | "info";
  rule_code: string;
  message: string;
  stage: string;
  details: Record<string, unknown> | null;
}

export interface RecordDetail extends Omit<RecordSummary, "issues"> {
  label: string;
  has_lines: boolean;
  lines_label: string;
  payload: Record<string, unknown>;
  approve_permission: string;
  fields: FieldView[];
  lines: FieldView[][];
  issues: Issue[];
  duplicates: { entity_type: string; entity_id: string; label: string; score: number; kind: string; details: Record<string, unknown> | null }[];
  approvals: { decision: string; by: string | null; auto: boolean; comment: string | null; diff: Record<string, unknown> | null; at: string; payload_version: number }[];
  committed: { target_table: string; target_id: string; number: string | null; committed_at: string } | null;
  source: { item_id: string; format: string; file_id: string | null; ocr_used: boolean };
}

export interface FieldSpec {
  name: string;
  label: string;
  type: string;
  required: boolean;
  reference: string | null;
  enum: string[] | null;
  aliases: string[];
  description: string;
}

export interface RecordType {
  code: string;
  module: string;
  label: string;
  description: string;
  fields: FieldSpec[];
  line_fields: FieldSpec[];
  lines_label: string;
  approve_permission: string;
  enabled: boolean;
}

export interface AgentInfo {
  provider: string;
  is_mock: boolean;
  external: boolean;
  supports_vision: boolean;
  model_extract: string;
  model_classify: string;
  prompt_version: string;
  formats: string[];
  max_upload_mb: number;
  max_files_per_job: number;
  import_policies: string[];
  ocr_enabled: boolean;
  ocr_languages: string;
  malware_scanning: boolean;
  confidence_threshold: number;
}

export interface AgentStats {
  period_days: number;
  jobs_total: number;
  jobs_by_status: Record<string, number>;
  jobs_by_source: Record<string, number>;
  success_rate: number | null;
  jobs_by_day: { date: string; completed?: number; in_review?: number; failed?: number }[];
  records_by_status: Record<string, number>;
  review_queue: Record<string, number>;
  decisions: Record<string, number>;
  stages: { stage: string; avg_ms: number; failures: number }[];
  ai_usage: { task: string; model: string; calls: number; input_tokens: number; output_tokens: number; cost_usd: number; errors: number; avg_latency_ms: number }[];
  most_corrected_fields: { record_type: string; field: string; corrections: number }[];
}

export interface ItemPreview {
  format: string;
  ocr_used: boolean;
  full_text: string | null;
  pages: { page: number; text: string; lines: { text: string; bbox: number[]; conf?: number }[] | null; width: number | null; height: number | null; ocr: boolean }[];
  tables: TableInfo[];
}

// --------------------------------------------------------------------------- analytics

export interface Overview {
  is_sample_data: boolean;
  period: { from: string; to: string; days: number };
  unit: string | null;
  kpis: {
    total_production: { value: number; unit: string | null; delta_pct: number | null };
    plan_completion: { value: number | null; orders: number };
    active_orders: { value: number; planned: number };
    inventory_shortages: { value: number };
    overdue_tasks: { value: number };
    pending_approvals: { value: number; agent_records: number; purchase_requests: number; draft_reports: number };
    ingestion_success_rate: { value: number | null; jobs: number };
    failed_jobs: { value: number; currently_failed: number };
    defect_rate: { value: number | null; delta_pct: number | null };
  };
  charts: {
    production_trend: { date: string; produced: number; accepted: number; defective: number }[];
    by_product: { code: string; name: string; unit: string; produced: number; defective: number; defect_rate: number | null }[];
    by_department: { name: string; produced: number; defective: number }[];
    plan_vs_actual: PlanVsActual[];
  };
  /** title/detail are English; params (per kind) let the UI build the text in the user's language. */
  alerts: { severity: "danger" | "warning" | "info"; kind: string; title: string; detail: string; link: string; params?: Record<string, string | number | null> | null }[];
  recent_activity: { at: string; action: string; summary: string | null; actor: string; entity_type: string | null; entity_id: string | null }[];
}

export interface PlanVsActual {
  order_id: string;
  number: string;
  product: string;
  department: string | null;
  status: string;
  planned_start: string | null;
  planned_end: string | null;
  unit: string;
  planned_qty: Num;
  actual_qty: Num;
  completion_pct: number | null;
}

export interface Quality {
  period: { from: string; to: string };
  by_product: { code: string; name: string; produced: number; defective: number; reports: number; defect_rate: number | null }[];
  by_department: { name: string; produced: number; defective: number; defect_rate: number | null }[];
  daily: { date: string; produced: number; defective: number; defect_rate: number | null }[];
  worst_reports: { id: string; number: string; date: string; product: string; produced: number; defective: number; defect_rate: number | null }[];
  source: string;
}

// --------------------------------------------------------------------------- production / inventory / supply

export interface Ref {
  id: string;
  name?: string;
  code?: string;
  number?: string;
}

export interface ProductionOrder {
  id: string;
  number: string;
  external_number: string | null;
  product: Ref & { code: string; name: string };
  department: { id: string; name: string } | null;
  line_id: string | null;
  planned_qty: Num;
  unit: string;
  planned_start: string | null;
  planned_end: string | null;
  status: string;
  priority: string;
  notes: string | null;
  actual_qty: Num | null;
  version: number;
  created_at: string;
  source_record_id: string | null;
}

export interface ProductionReport {
  id: string;
  number: string;
  production_date: string;
  product: Ref & { code: string; name: string };
  department: { id: string; name: string };
  shift: { id: string; name: string } | null;
  order: { id: string; number: string } | null;
  line_id: string | null;
  qty_produced: Num;
  qty_accepted: Num | null;
  qty_defective: Num | null;
  unit: string;
  status: "draft" | "approved" | "cancelled";
  supersedes_id: string | null;
  notes: string | null;
  approved_at: string | null;
  version: number;
  created_at: string;
  source_record_id: string | null;
  stock_movements?: { id: string; number: string; type: string; qty: Num; unit: string; warehouse: string; reverses_id: string | null }[];
  superseded_by?: { id: string; number: string } | null;
  /** Why approved output is not in stock (e.g. output_not_posted); returned on create, approve, correct and read. */
  warnings?: { code: string; message: string }[];
}

export interface Shift {
  id: string;
  code: string;
  name: string;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
}

export interface Line {
  id: string;
  code: string;
  name: string;
  department_id: string;
  capacity_per_shift: Num | null;
  is_active: boolean;
}

export interface Warehouse {
  id: string;
  code: string;
  name: string;
  kind: string;
  is_active: boolean;
  department_id: string | null;
}

export interface Balance {
  warehouse: { id: string; code: string; name: string };
  product: { id: string; code: string; name: string; type: string };
  batch_code: string | null;
  qty: Num;
  unit: string;
  reorder_level: Num | null;
  updated_at: string;
}

export interface Shortage {
  product_id: string;
  code: string;
  name: string;
  unit: string;
  on_hand: Num;
  reorder_level: Num;
  missing: Num;
}

export interface Movement {
  id: string;
  number: string;
  movement_date: string;
  movement_type: string;
  direction: number;
  warehouse: { id: string; code: string; name: string };
  product: { id: string; code: string; name: string };
  qty: Num;
  unit: string;
  base_qty: Num;
  batch_code: string | null;
  reference: string | null;
  ref_type: string | null;
  ref_id: string | null;
  reverses_id: string | null;
  notes: string | null;
  created_at: string;
  source_record_id: string | null;
}

export interface Supplier {
  id: string;
  code: string;
  name: string;
  tin: string | null;
  contact_person: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  is_active: boolean;
}

export interface PurchaseRequest {
  id: string;
  number: string;
  external_number: string | null;
  request_date: string;
  needed_by: string | null;
  department: { id: string; name: string } | null;
  requested_by: string | null;
  supplier: { id: string; name: string } | null;
  status: string;
  priority: string;
  currency: string | null;
  total_estimated: Num | null;
  notes: string | null;
  source_file_id: string | null;
  decided_at: string | null;
  decision_comment: string | null;
  version: number;
  created_at: string;
  source_record_id: string | null;
  lines: { line_no: number; description: string; qty: Num; unit: string; product: { id: string; code: string; name: string } | null; est_price: Num | null }[];
}

export interface Task {
  id: string;
  number: string;
  title: string;
  description: string | null;
  assignee_id: string | null;
  assignee_name: string | null;
  department_id: string | null;
  due_date: string | null;
  status: "open" | "in_progress" | "done" | "cancelled";
  priority: "low" | "normal" | "high" | "urgent";
  ref_type: string | null;
  ref_id: string | null;
  overdue: boolean;
  version: number;
  created_at: string;
  completed_at: string | null;
  source_record_id: string | null;
}

export interface Notification {
  id: string;
  kind: string;
  title: string;
  body: string | null;
  link: string | null;
  created_at: string;
  read: boolean;
}

// --------------------------------------------------------------------------- audit / admin

export interface AuditEvent {
  id: number;
  occurred_at: string;
  actor_type: string;
  actor_id: string | null;
  actor_name: string | null;
  action: string;
  entity_type: string | null;
  entity_id: string | null;
  summary: string | null;
  changes: Record<string, unknown> | null;
  context: Record<string, unknown> | null;
  hash: string;
}

export interface Trace {
  entity_type: string;
  entity_id: string;
  created_via: "biwork_agent" | "direct";
  agent: {
    original_input: { job_id: string; submitted_at: string; submitted_by: string | null; channel: string; source_kind: string; text: string | null; payload: unknown; file: { id: string; name: string; sha256: string; size: number; format: string; scan_status: string } | null; parser: string | null; ocr_used: boolean; ocr_confidence: Num | null; row_ref: Record<string, unknown> };
    processing: { pipeline_version: string; prompt_version: string; ai_provider: string; ai_model: string; ai_is_mock: boolean; ai_calls: { task: string; provider: string; model: string; prompt_version: string; status: string; input_tokens: number | null; output_tokens: number | null; cost_usd: Num | null; at: string }[]; stages: { stage: string; status: string; attempt: number; started_at: string; duration_ms: number | null; error: string | null }[] };
    extracted_values: { field: string; raw_value: string | null; normalized_value: unknown; confidence: Num | null; state: string; extracted_by: string; edited_by: string | null; sources: SourceRef[] }[];
    validation_results: { stage: string; severity: string; rule: string; field: string | null; message: string }[];
    matches: { field: string | null; entity_type: string; label: string; score: Num; kind: string; selected: boolean }[];
    approvals: { decision: string; by: string | null; auto: boolean; comment: string | null; at: string }[];
    record: { id: string; record_type: string; status: string; payload: Record<string, unknown> };
    database_write: { target_table: string; target_id: string; number: string | null; idempotency_key: string; committed_at: string } | null;
  } | null;
  audit_events: AuditEvent[];
}

export interface SystemQueues {
  jobs_by_status: Record<string, number>;
  running_stages: { job_id: string; stage: string; started_at: string; attempt: number }[];
  recent_failures: { job_id: string; stage: string; error: string | null; at: string; attempt: number }[];
  ai_usage_this_month: { provider: string; model: string; calls: number; input_tokens: number; output_tokens: number; cost_usd: number; errors: number }[];
  queue_backlog: Record<string, unknown>;
  config: { ai_provider: string; ocr_enabled: boolean; malware_scanning: boolean; storage: string };
}
