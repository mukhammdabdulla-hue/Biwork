# BIWORK backend

FastAPI service for the BIWORK platform. This first slice contains the BIWORK
Agent document intake pipeline.

## Run

```bash
cd backend
python -m venv .venv && . .venv/bin/activate
pip install -e ".[dev]"
pytest

# Claude (credentials from ANTHROPIC_API_KEY or an `ant auth login` profile)
uvicorn app.main:app --reload
# No credentials: clearly labeled mock extractor, no AI analysis
BIWORK_AI_PROVIDER=mock uvicorn app.main:app --reload
```

Settings use the `BIWORK_` prefix (see `app/core/config.py`): `AI_PROVIDER`,
`AI_MODEL` (default `claude-opus-5-5`), `AI_EFFORT` (default `high`),
`AI_TIMEOUT_SECONDS`, `AI_MAX_RETRIES`, `INTAKE_MAX_UPLOAD_BYTES`.

## Document intake

`POST /api/v1/agent/intake` takes a multipart form with exactly one of `file`
(PDF, PNG, JPEG, GIF, WEBP, CSV, plain text) or `text`, plus an optional
`document_type` hint (`invoice`, `work_order`, `specification`). It returns a
preview for review. **Nothing is saved.**

```bash
curl -F file=@invoice.pdf -F document_type=invoice localhost:8000/api/v1/agent/intake
```

### Pipeline

| Stage | Where | What happens |
|---|---|---|
| 1-2 Ingestion and parsing | `agent/intake/documents.py` | Size limits, file type from magic bytes (not the client's header), SHA-256 for provenance. DOCX/XLSX are rejected until dedicated parsers exist. |
| 3 AI interpretation | `agent/intake/extractors/` | `DocumentExtractor` protocol with a Claude adapter and a mock adapter. Claude gets the file as a `document`/`image` block and must answer in the `ExtractionEnvelope` JSON schema (structured outputs via `client.beta.messages.parse`). |
| 5 Validation | `agent/intake/validation.py` | Deterministic rules: required fields, ISO currency, date order, line x price = total, lines = subtotal, subtotal + tax = total, quantity reconciliation, spec min <= nominal <= max. |
| 7 Review preview | `agent/intake/service.py` | Per-field status, issues, summary counts, overall status. `requires_review` is always true. |

Normalization (4), entity matching (6), approval and writes (8) and audit (9)
come with the database layer.

### Field statuses

Every scalar the model extracts carries `value`, `confidence` (`high`/`low`),
`source_page` and `source_text`. Validation turns that into one of
`high_confidence`, `low_confidence`, `missing`, `conflicting` or `invalid`.
Low-confidence values always raise a warning, so they are never treated as
verified facts.

Overall status: `unsupported_document` (not an invoice, work order or
specification), `needs_correction` (any error), `needs_review` (warnings only),
or `ready_for_approval`.

### Safety and privacy

- Uploaded content is untrusted. It goes to the model in its own document
  block, never spliced into instructions, and the system prompt tells the
  model that text inside a document cannot change the rules or approve
  anything. The model only returns data; it has no tools and cannot write.
- Every model answer is validated twice: by the JSON schema at generation time
  and by the deterministic rules above.
- Claude requests use server-side fallback (`fallbacks: "default"`) so a
  false-positive safety decline is retried on another model; a final decline
  returns HTTP 422 with code `refused`.
- Using the Claude provider sends the full document to Anthropic's API. Keep
  `BIWORK_AI_PROVIDER=mock` for environments that must not send business
  documents to an external provider.
- Each result records `provider`, `model`, `prompt_version` and token usage so
  extractions can be traced and costed.
