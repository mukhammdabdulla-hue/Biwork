# BIWORK Agent — multi-provider AI engine

This document describes how BIWORK Agent uses AI models: which providers it supports, how a model
is chosen for each task, how failures are handled, how usage and cost are monitored, how results
reach the database, and how to evaluate providers on BIWORK's own documents. It complements
[architecture.md §6–7](architecture.md#6-biwork-agent-pipeline).

**In one sentence:** the AI proposes, deterministic code decides. A model only ever returns a
schema-validated proposal; normalization, validation, duplicate detection, permissions, human
review and the module services decide what is written.

---

## 1. Providers

| Provider | Role | SDK | Adapter |
|---|---|---|---|
| OpenAI | primary | `openai` (Responses API, `responses.parse` with a strict JSON schema, `store=false`) | `app/ai/providers/openai.py` |
| Google Gemini | alternative / fallback | `google-genai` (`models.generate_content`, `response_json_schema`) | `app/ai/providers/gemini.py` |
| Anthropic Claude | optional (evaluation, specialised tasks, fallback) | `anthropic` (`messages.parse`, `output_format`) | `app/ai/providers/anthropic.py` |
| Demo mode | used only when no credentials are configured | none — deterministic rules | `app/ai/providers/demo.py` |

All adapters implement the same `LLMProvider` protocol (`app/ai/providers/base.py`): a *task*
(classify, extract, map_columns), content blocks (text, image, PDF — always wrapped as
`<untrusted_data>`), and a Pydantic output schema in; a validated object plus usage metadata out.
Business logic never imports an adapter or names a model. Only official APIs and SDKs are used —
no browser automation, no unofficial endpoints. The SDKs' own retries are switched off so that the
engine counts and logs every attempt.

Each adapter maps provider outcomes to two error classes:

- `TransientAIError` — timeouts, connection errors, 408/409/429, 5xx (with `retry-after` when
  given). Retried with backoff, then the next provider, then the job is kept for a safe retry.
- `PermanentAIError(kind=…)` — `refused`, `truncated`, `auth`, `not_configured`,
  `invalid_output` (these fall back to the next provider), or `bad_request`, `too_large`, `policy`
  (these would fail the same way everywhere, so the item fails visibly).

## 2. Configuration

All settings are environment variables (or a secrets manager that injects them), validated at
start-up — the process refuses to start on an inconsistent AI configuration. See
[`backend/.env.example`](../backend/.env.example).

```bash
# Credentials (secrets: never in the frontend, browser requests, logs, or source control)
OPENAI_API_KEY=
GEMINI_API_KEY=
ANTHROPIC_API_KEY=

# Models per routing tier — placeholders you fill with ids your account can access
OPENAI_MODEL_DEFAULT=        # routine structured extraction (required when OPENAI_API_KEY is set)
OPENAI_MODEL_COMPLEX=        # escalation for ambiguous / conflicting / low-confidence input
OPENAI_MODEL_ECONOMY=        # classification, column mapping (defaults to DEFAULT)
OPENAI_MODEL_VISION=         # images and scans (defaults to DEFAULT)
GEMINI_MODEL_DEFAULT=        # same tiers: GEMINI_MODEL_COMPLEX / _ECONOMY / _VISION
ANTHROPIC_MODEL_DEFAULT=     # same tiers; ships with claude-sonnet-5-5 / -opus-5-5 / -haiku-5-5 defaults

AI_PROVIDER=auto             # auto | openai | gemini | anthropic | demo
AI_PRIMARY_PROVIDER=openai   # used first when AI_PROVIDER=auto
AI_FALLBACK_PROVIDERS=openai,gemini,anthropic
AI_ROUTING_ENABLED=true      # false: every task uses the DEFAULT tier, nothing escalates
AI_FALLBACK_ENABLED=true     # false: only the selected provider is called
AI_REVIEW_REQUIRED=true      # true: a person approves every AI-extracted record
```

Start-up validation:

- a provider whose key is set must have `<PROVIDER>_MODEL_DEFAULT`;
- `AI_PROVIDER=openai|gemini|anthropic` requires that provider's key;
- in `staging`/`prod`, `AI_PROVIDER=auto` with no credentials at all is refused — set
  `AI_PROVIDER=demo` to run the labelled demo on purpose.

**Choosing model identifiers.** Model ids change and are retired, so none are hard-coded for
OpenAI or Gemini. Use ids your account can access today and verify them with:

```bash
python -m app.cli ai-models            # lists models each configured provider serves and checks the configured ids
python -m app.cli ai-check             # prints the effective routing (no secrets)
```

Run `ai-models` after every model change and in your deployment pipeline; it exits non-zero when a
configured id is not available to the key.

Other settings: `AI_TIMEOUT_S` (60), `AI_MAX_RETRIES` (2 per provider), `AI_RETRY_BASE_DELAY_S`
(1), `AI_RETRY_MAX_DELAY_S` (20), `AI_MAX_CALLS_PER_MINUTE` (60 per provider and company),
`AI_MAX_INPUT_CHARS` (120 000), `AI_MAX_INPUT_TOKENS` (40 000, estimated), `AI_MAX_OUTPUT_TOKENS`
(16 000), `AI_ESCALATION_CONFIDENCE` (0.6), `AI_CACHE_TTL_S` (86 400; 0 disables),
`AI_HEALTH_FAILURE_THRESHOLD` (3), `AI_HEALTH_COOLDOWN_S` (120), `AI_PRICING_JSON`,
`AI_ROUTING_FILE`, `AI_EFFORT` and `AI_REFUSAL_FALLBACK` (Anthropic only), `OPENAI_BASE_URL`,
`OPENAI_ORGANIZATION`, `GEMINI_BASE_URL`, `ANTHROPIC_BASE_URL`.

Per company, `companies.settings.ai_policy` still applies on top (architecture §7.5):
`external_ai_allowed`, `allowed_providers` (now `openai`, `gemini`, `anthropic`, `demo`),
`send_images_to_ai`, `redaction_rules`, `minimize_context`, `retain_ai_payloads_days`. The router
never sends data to a provider the company has not allowed.

## 3. Task-based routing

`app/ai/router.py` turns a task into an ordered list of candidates (provider, model, tier). The
rules live in [`app/ai/routing.default.json`](../backend/app/ai/routing.default.json) and can be
replaced with `AI_ROUTING_FILE`; they name *tiers*, never model ids.

| Input / task | What runs | Tier |
|---|---|---|
| Excel / CSV | deterministic parsers; saved template → header rules → AI **only for unresolved columns** (one call per table, headers + 3 sample rows) | economy |
| Pasted tables | same as spreadsheets | economy |
| Text, Word, text PDF | parser → classify (skipped when the record type is given) → extract | economy → default |
| Scanned PDF / image | OCR (Tesseract, eng+rus+uzb+uzb_cyrl); the image goes to a vision model only when the company policy allows `send_images_to_ai` | default or vision |
| Structured API / form payload | validated directly, no AI | — |
| Uncertain result | escalate once to a stronger model | complex |
| Schema-invalid output | one repair attempt, then the next provider, then the complex tier | complex |

**Escalation rules** (`escalation` in the routing file, evidence-based, no model call):
mean field confidence below `AI_ESCALATION_CONFIDENCE`; conflicting values stated in the source;
at least half of the required fields missing; no records extracted; schema-invalid output. The
escalated result replaces the first one only when it is better supported (fewer concerns, or
higher mean confidence); otherwise the first, cheaper result is kept. Remaining concerns are stored
on the record (`ai_meta.concerns`) and raise an `ai_uncertain_extraction` warning, so the record
goes to human approval. Classification escalates to the default tier when nothing is recognised
with confidence.

**Provider order:** `AI_PROVIDER` when it names a provider, otherwise `AI_PRIMARY_PROVIDER`; then
`AI_FALLBACK_PROVIDERS` when `AI_FALLBACK_ENABLED`. Providers without a key, not allowed by the
company policy, or without a model for the tier are skipped; providers marked down by the health
monitor are tried last.

## 4. Data processing pipeline

| # | Step | Where | AI? |
|---|---|---|---|
| 1 | Receive text, Excel, CSV, PDF, Word, image, form or API input | `agent/pipeline/ingest.py`, `/api/v1/agent/jobs`, `/jobs/json` | no |
| 2 | Parse with format-specific tools (openpyxl/xlrd, python-docx, pdfplumber, Tesseract) | `agent/parsers`, `ai/ocr` | no |
| 3 | Identify document type and module | `extraction.classify` | economy tier |
| 4–5 | Extract entities into the record type's strict schema | `extraction.extract` | default / vision / complex |
| 6 | Normalize names, dates, currencies, units, identifiers | `agent/normalization.py`, `pipeline/normalize.py` | no |
| 7 | Validate with deterministic business rules | module `rules`, `pipeline/validate.py` | no |
| 8–9 | Match existing records; detect duplicates, conflicts, missing and suspicious values | module matchers, `pipeline/match.py` | no |
| 10 | Confidence / quality: per-field state + record group, from model confidence, OCR confidence, validation and matching evidence | `pipeline/match.py`, `extraction.assess_extraction` | no |
| 11 | Route uncertain or consequential records to review | `pipeline/review.py` | no |
| 12 | Save approved data through module services (permission-checked, idempotent, optimistic locking) | `pipeline/commit.py` | no |
| 13 | Record the result and audit history | `agent.*` tables, hash-chained `core.audit_events` | no |

The model never writes to the database, never sees SQL, and has no tools. Its only output is a
schema-constrained object that is re-validated with Pydantic before anything else happens.

### Entities and schemas

Every ingestible entity is a `RecordTypeSpec` (fields, types, aliases, required flags, Pydantic
write schema, validators, duplicate matcher, writer, permissions):

| Entity | Record type | Module | Notable deterministic checks |
|---|---|---|---|
| Products | `product` | core | unique code; same-name/different-code warning |
| Production orders | `production_order` | production | end before start, deadline passed, quantities |
| Production reports | `production_report` | production | accepted + defective vs produced, order/product/department consistency, duplicates |
| Inventory records | `stock_movement` | inventory | negative quantities, insufficient stock, unit conversion |
| Employees | `employee` *(new)* | core | personnel-number uniqueness, hire date, name completeness |
| Suppliers | `supplier` *(new)* | supply | TIN (STIR/ИНН) format and uniqueness, e-mail format |
| Procurement requests | `purchase_request` | supply | needed-by vs request date, items, units |
| Documents | `business_document` *(new)* | core | line amount = qty × price, Σ lines = total, currency, duplicate number per counterparty |
| Tasks | `task` | core | due date, assignee |

Each extracted field carries `value`, `confidence`, `source_quote`, `not_found` and
`alternatives`. The prompts forbid inventing values; a missing value stays `not_found` and becomes
a `missing` field (an error when required), never a guess. Field states shown to reviewers:
`high_confidence`, `low_confidence`, `missing`, `conflicting`, `invalid`, `needs_approval`;
record-level duplicates appear as blocking (natural-key collision) or warning candidates.

## 5. Human review and governance

`GET /api/v1/agent/records/{id}` returns everything the review screen needs:

- `original_input` — the source text (first 20 000 characters), file id/name, OCR flag, sheet/row/page;
- `fields` / `lines` — extracted, normalized values with state, confidence, alternatives and source excerpts;
- `issues` — validation results; `duplicates` — potential duplicates; `uncertain_values`;
- `proposed_change` — the create that approval would cause, target entity, values, references, permission;
- `ai` — provider, model, tier, routing reason, fallback, escalation, concerns, every AI call with
  tokens/cost/latency, and a `label` "Demo mode — simulated, not real AI analysis" for demo results;
- `approvals`, `committed` — the decision history and the final database write.

Actions: `PATCH /records/{id}` (correct; stored as `extraction_feedback`), `/approve`, `/reject`,
`/reopen`, `/jobs/{id}/approve` (bulk), `/jobs/{id}/commit`.

Approval is required by default. Automatic processing needs **both** an administrator enabling
auto-approval for that record type's workflow (`PUT /api/v1/workflows/{record_type}`) **and** either
deterministic input (spreadsheet mapping, forms, API payloads) or `AI_REVIEW_REQUIRED=false`; even
then only `ready` records whose every field is high-confidence qualify, and demo-mode results never
do. Source, extraction, corrections, approvals and the final write are linked (`GET /api/v1/audit/trace/{entity_type}/{entity_id}`) so every saved row can be walked back to its input.

Uploaded content is untrusted: it is wrapped in delimited data blocks (a document cannot close its
own wrapper), injection-like phrases are flagged to reviewers, calls have no tools, and nothing a
document says can change permissions, approvals or validation.

## 6. Reliability

- **Timeouts:** `AI_TIMEOUT_S` per call.
- **Retries:** transient errors are retried `AI_MAX_RETRIES` times per provider with exponential
  backoff and jitter; `retry-after` is honoured up to `AI_RETRY_MAX_DELAY_S`, beyond that the next
  provider is tried at once.
- **Rate limiting:** per provider and company (`AI_MAX_CALLS_PER_MINUTE`, Redis or in-process).
- **Fallback:** configurable chain of providers; the demo provider is never a fallback.
- **Health monitoring:** a per-process circuit breaker marks a provider `down` after
  `AI_HEALTH_FAILURE_THRESHOLD` consecutive transient/auth failures for `AI_HEALTH_COOLDOWN_S`;
  `GET /api/v1/agent/ai/status` shows state, failures, last error and p50 latency.
- **Background processing:** every job runs in Celery stages (`parse → interpret → prepare →
  commit`), each idempotent and recorded in `stage_runs`; a watchdog re-enqueues stuck stages.
- **No false success:** if every provider fails, the item is *not* marked processed — the job is
  `failed` with `retryable: true` and a clear message, and `POST /jobs/{id}/retry` resumes from the
  last successful stage. Commits are idempotent (`committed_writes`, natural keys, optimistic
  locking), so a retry never writes twice.

## 7. Usage monitoring and cost

Every attempt — successful, failed, retried, cached — is a row in `agent.ai_calls`: company, job,
item, task type, provider, model, tier, routing reason, attempt number, fallback source, prompt and
schema version, input/output tokens, estimated cost, latency, status, error, and (after validation)
the extraction `outcome` (records, ready / needs_review / needs_correction counts).

`GET /api/v1/agent/ai/usage?days=30` aggregates per provider/model/task/tier: calls, successes,
failures, retries, fallbacks, cache hits, tokens, cost, average and p95 latency; and totals including
records by status and review group, escalations, human-correction rate, **cost per document** and
**cost per successfully validated (committed) record**. `GET /api/v1/admin/system/queues` adds
provider health and monthly usage.

Cost controls built in:

- spreadsheets are read and calculated deterministically; AI maps only unresolved columns, once per table;
- forms/API payloads and a selected record type skip AI classification;
- economy models for classification and mapping, default for routine extraction, complex only on evidence;
- a company-scoped cache (key: company, task, prompt version, provider, model, content hash) avoids
  paying twice for the same classification or column mapping — results never cross companies;
- duplicate uploads are rejected before any AI call (content fingerprint);
- input limits (`AI_MAX_INPUT_CHARS`, `AI_MAX_INPUT_TOKENS`, `MAX_UPLOAD_MB`) and output limits per task.

**Prices.** Costs are estimates from a price table: Anthropic list prices ship in
`app/ai/budget.py`; OpenAI and Gemini prices change often and differ by account, so set them from
the providers' pricing pages:

```bash
AI_PRICING_JSON='{"<openai-default-model>": [<usd per 1M input tokens>, <usd per 1M output tokens>], "<gemini-model>": [..., ...]}'
```

Calls to a model without a price are counted as `unpriced_calls`, never as free.

**Estimated cost per document** (planning figure; measure yours with `ai-eval` and the usage endpoint):

| Step | Typical tokens (in / out) | Calls per text/PDF document |
|---|---|---|
| classify (economy) | ~1 000 / ~150 | 1 (0 when the record type is given; cached on resubmission) |
| extract (default) | ~2 000–4 000 / ~400–1 000 | 1 per record type found |
| escalation (complex) | same as extract | only for uncertain results (aim for < 10–15 %) |
| spreadsheet column mapping (economy) | ~800 / ~200 | 0–1 per table, independent of row count |

cost/document ≈ classify_in × p_in(economy) + classify_out × p_out(economy) + extract_in × p_in(default)
+ extract_out × p_out(default) + escalation_rate × (extract tokens × complex prices).

Example with the Anthropic prices in the repository (Haiku 5.5 economy, Sonnet 5.5 default, Opus 5.5
complex, 3 000/700 extract tokens, 10 % escalation): ≈ $0.0002 + $0.013 + $0.0026 ≈ **$0.016 per
document**, about **$16 per 1 000 documents**; a 5 000-row spreadsheet costs at most one economy call.
Verify prices on the provider's pricing page before relying on them.

## 8. Evaluation

`backend/eval/dataset/` holds a synthetic, anonymized dataset: Uzbek (Latin and Cyrillic), Russian
and English documents for every entity, including conflicting values, an injection attempt, total
mismatches, and duplicates of seeded master data. `seed.json` is the master data each run starts from.

```bash
# against a staging database — runs the real pipeline up to review, writes nothing to business tables
python -m app.cli ai-eval --provider openai --provider "openai:<other-model>" --provider gemini --provider anthropic \
    --out eval/report.json            # also writes eval/report.md
```

Each provider/model runs every case in a fresh evaluation company (flagged sample data) with fallback
off, and the report gives: record-type accuracy, field-level accuracy (overall and per language),
schema validity, duplicate-detection precision and recall, false-acceptance rate (records the
pipeline would mark `ready` although wrong), human-correction rate, average/p95 latency, cost per
document and cost per validated record, plus every mismatch.

Choose models on these measurements — on BIWORK's document types and rules — not on general
benchmarks or advertised prices. Grow the dataset with real (anonymized) documents: reviewers'
corrections are stored in `agent.extraction_feedback` and can be promoted into cases.

Demo-mode reference run (rule-based stand-in, **not AI**; all costs $0):

| Metric | Demo |
|---|---|
| Record-type accuracy | 95.7 % |
| Field accuracy | 87.2 % (en 81 %, ru 96 %, uz-Latn 97 %, uz-Cyrl 0 %) |
| Duplicate precision / recall | 100 % / 100 % |
| False-acceptance rate | 20 % |

The demo is weak exactly where real models are needed (Uzbek Cyrillic, free-form wording, document
types), which is what the evaluation is for.

## 9. Demo mode

With no provider credentials (or `AI_PROVIDER=demo`), the engine uses deterministic rules instead of
a model so the platform can be explored and tested. It is always labelled: jobs carry
`ai_is_mock=true`, `ai_mode="demo"` and a "Demo mode … not real AI analysis" warning; records carry
`ai.is_demo` and the review label; fields are `extracted_by="demo"`; usage rows are `is_mock` with
zero cost. Demo results are never auto-approved, and the demo provider is never used as a fallback
for a failing real provider.

## 10. Deployment checklist

1. Install dependencies (`pip install -e backend`), including `openai`, `google-genai`, `anthropic`.
2. Put API keys in the secrets manager / environment of the API **and** the Celery workers.
3. Set `*_MODEL_*` ids; run `python -m app.cli ai-models` and `ai-check`.
4. Set `AI_PRICING_JSON` for OpenAI/Gemini models.
5. Run migrations (`alembic upgrade head` — 0002 adds the routing/usage columns, `core.employees`,
   `core.business_documents`, and moves companies on the old default provider list to the new one).
6. Run `ai-eval` on staging with the candidate models and pick models from the report.
7. Review company AI policies (allowed providers, image sending, redaction).
8. Keep `AI_REVIEW_REQUIRED=true` until the evaluation shows a false-acceptance rate you accept;
   enable auto-approval per record type only for well-defined, low-risk workflows.
9. Watch `/api/v1/agent/ai/status` and `/api/v1/agent/ai/usage`.

## 11. Limitations

- Real-provider calls were tested only against stubbed HTTP (the SDKs' fake transports and a local
  stand-in server); no OpenAI/Gemini/Anthropic keys were available while building this. The first
  real run should be `ai-models`, then `ai-eval` on staging.
- OpenAI strict structured outputs and Gemini `response_json_schema` support a subset of JSON
  Schema; the envelopes use only objects, arrays, strings, numbers, booleans, nulls, enums and
  `minimum`/`maximum`. If a model rejects the schema, the error is a `bad_request` and is visible.
- Token counts before a call are estimates (chars ÷ 4, Cyrillic ÷ 2.5); providers bill actual usage,
  which is what `ai_calls` records.
- Provider health and the in-process cache are per worker process; use Redis (`REDIS_URL`) for a
  shared cache and rate limits.
- The evaluation dataset is synthetic and small (23 cases); it shows how to measure, not which
  model is best for your documents.
- Vision input is used only when a company enables `send_images_to_ai`; otherwise scans rely on OCR
  text quality (Tesseract with `uzb_cyrl`, `rus`, `uzb`, `eng`).
