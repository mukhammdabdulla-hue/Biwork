# AI providers and data handling

BIWORK Agent uses a language model in exactly one pipeline stage — **Interpret** — to read
unstructured input (free text, documents, scans) and to propose spreadsheet column mappings.
Everything after that (normalization, validation, matching, duplicate checks, approval and the
database write) is deterministic application code. The model never runs SQL, never calls tools and
never approves anything. This document explains which providers exist, what leaves the system, and
how each company controls it.

## Providers

| `AI_PROVIDER` | What it is | When to use |
|---|---|---|
| `mock` (default) | Rule-based extraction for Uzbek, Russian and English, written for development and tests. Runs inside BIWORK; nothing leaves the system. | Local development, demos, CI, installations where external AI is not allowed. |
| `anthropic` | Claude through the Anthropic API (`ANTHROPIC_API_KEY`, optional `ANTHROPIC_BASE_URL`). | Real extraction from text, documents and scans. |

The mock adapter is **never presented as AI analysis**. Its results are labelled "Mock AI — not
real analysis" in the app banner, on every job, in processing reports, in provenance traces and in
the `ai_calls` log (`is_mock = true`).

Models are configurable: `AI_MODEL_EXTRACT` (documents and free text, default `claude-opus-5-5`)
and `AI_MODEL_CLASSIFY` (deciding which record type a document contains, default
`claude-haiku-5-5`). `AI_EFFORT` trades depth of reasoning for latency and cost.

Adding a provider means implementing `LLMProvider` (`backend/app/ai/providers/base.py`): one
`complete()` call that takes content blocks and a Pydantic output model and returns a validated
instance. Pipeline code depends only on that interface.

## What is sent to the provider

| Task | When | Content sent |
|---|---|---|
| `classify` | Free text or a document, when the submitter did not choose a record type | Document text (after redaction), plus the names and descriptions of the record types enabled for the company |
| `extract` | Free text, DOCX, PDF and scans | Document text (after redaction). For PDFs and images, the file itself **only** if the company allows sending images and the provider supports vision; otherwise only the OCR text |
| `map_columns` | A spreadsheet whose headers the rules and saved templates do not fully recognise | Column headers and 3 sample values per column (10 if "minimum context" is off), redacted |

Spreadsheet **rows** are never sent: once the column mapping is known, every row is read
deterministically. Structured submissions (forms, API payloads) bypass the model entirely.

Inputs longer than `AI_MAX_INPUT_CHARS` are refused with a message to split the file, rather than
silently truncated.

## Company controls (Settings → AI & data handling)

Stored in the company settings as `ai_policy`; changes are audited.

| Control | Effect |
|---|---|
| Allow external AI processing | Off: no call leaves the system. Spreadsheets still import with rule-based mapping and saved templates; free text and document extraction is unavailable and says so. |
| Allowed providers | Calls to any other provider are refused. |
| Send images and scans to AI | Off: only OCR text leaves the system, never the image or PDF itself. |
| Redact before sending | Phone numbers, e-mail addresses, passport numbers, card numbers, bank accounts and personal ID numbers (PINFL) are replaced with `[REDACTED:type]` placeholders before any text is sent. |
| Send only the minimum context | Spreadsheets: headers plus 3 sample values instead of 10. |
| Keep AI request and response payloads (days) | 0 (default): only metadata is stored — task, model, prompt version, tokens, cost, latency, status and the categories of data sent. Above 0: the redacted request and the response are kept for that many days for troubleshooting, then removed by the hourly `agent.purge_ai_payloads` task. |

Every call, successful or not, is logged in `agent.ai_calls` with the categories of data that left
the system (`text`, `table_sample`, `image`, `pdf`). Administration → System health shows usage and
cost per model for the month; Analytics → BIWORK Agent shows it per task.

## Untrusted input and prompt injection

Uploaded documents and pasted text are data, not instructions:

- Document content is wrapped as untrusted data in the prompt, and the instructions tell the model
  to extract only.
- Instruction-like content ("ignore previous instructions", "approve all records") is detected
  before the call; the job and item carry a warning, and classification reports it as well.
- Output must match the record type's schema (structured outputs plus Pydantic validation). Invalid
  output is retried once with the validation error, then the item fails visibly.
- Values the model reports with low confidence, or could not find, are marked as such and are never
  saved without a person confirming them. AI-proposed column mappings are capped below the
  auto-approval threshold, so they always need confirmation.
- Approval, permissions and database writes are enforced by application code regardless of what the
  model returns.

## Choosing a provider for real data

Before enabling an external provider for a company, decide:

1. **Residency and contracts.** Requests go to the provider's API region. Check that this is
   acceptable for the company's data and any customer contracts. If it is not, keep external AI
   off: spreadsheet imports, structured submissions and manual entry still work.
2. **Personal data.** Production and supply documents rarely need personal data. Turn on the
   redaction rules that match the documents you expect, and keep image sending off unless scans
   are a main input.
3. **Retention.** Keep payload retention at 0 unless you are actively troubleshooting extraction
   quality.
4. **Cost.** `AI_MAX_CALLS_PER_MINUTE` limits throughput per installation; token usage and cost are
   recorded per call. Spreadsheets cost at most one small mapping call per new layout; saved
   templates make repeat layouts free.
