# BIWORK

**Smart data. Better decisions.** BIWORK is an AI-powered management platform for manufacturing
companies: a shared core (users, permissions, master data, audit), **BIWORK Agent** — the central
engine that turns text, spreadsheets, documents and scans into validated records — and product
modules (Production, Inventory, Supply, Analytics; HR, EDO, Finance, Maintenance and Quality are
on the roadmap).

The design reference is [docs/architecture.md](docs/architecture.md) (its §15 lists what is
implemented). AI providers and data handling are described in [docs/ai-providers.md](docs/ai-providers.md).

> **Mock AI by default.** Without an API key the agent uses a rule-based mock adapter. It is
> labelled "Mock AI — not real analysis" everywhere it appears (banner, job pages, reports and
> provenance). Set `OPENAI_API_KEY`, `GEMINI_API_KEY` or `ANTHROPIC_API_KEY` (with `AI_PROVIDER=auto`)
> for real extraction; see [docs/ai-engine.md](docs/ai-engine.md).
>
> **Sample data is labelled.** `seed-demo` creates the company "BIWORK Demo Factory", flagged as
> sample data; the dashboard says so on every page view.

## What is in the repository

```
backend/                FastAPI app, BIWORK Agent pipeline, Celery tasks, Alembic migrations, tests
  app/core/             identity, permissions, master data, files, tasks, audit, matching, rules
  app/agent/            ingestion jobs, parsers (text, CSV, XLSX/XLS, DOCX, PDF, images + OCR), 9 pipeline stages
  app/ai/               provider interface, Claude adapter, mock adapter, prompts, policy, OCR
  app/modules/          production, inventory, supply (+ planned modules), module registry
  app/analytics/        executive overview, agent statistics, quality
frontend/               React 19 + TypeScript + Tailwind 4 web app, Playwright e2e tests
deploy/                 Dockerfiles, nginx config, Postgres init script
docker-compose.yml      full stack: Postgres, Redis, migrate, API, worker, beat, web (+ optional ClamAV)
.github/workflows/      CI: lint, layering contracts, backend tests, typecheck/build, e2e
```

## Run with Docker Compose

Requirements: Docker with Compose v2.

```bash
cp .env.example .env
# Edit .env: set every "change-me" value. For a quick local trial also set APP_ENV=dev
# (staging/prod refuse to start with development secrets).
docker compose up -d --build
docker compose run --rm migrate python -m app.cli seed-demo     # optional sample company
docker compose run --rm migrate python -m app.cli create-admin --email you@example.com --password 'A-strong-passw0rd'
```

Open http://localhost:8080. With sample data, sign in as `admin@biwork.local` (or `manager@`,
`operator@`, `viewer@`) with password `Biwork2026!demo`.

Services: `postgres` (data; owner role runs migrations, a separate non-owner role is used by the
app so row-level security applies), `redis` (Celery broker, rate limits, live events), `migrate`
(Alembic + role bootstrap, runs once), `api` (FastAPI), `worker` (Celery queues
`parse,ai,commit,default`), `beat` (stuck-stage watchdog, AI payload retention purge), `web` (nginx
serving the app and proxying `/api`). Uploaded files live on the `files` volume shared by the API
and the workers; set `STORAGE_BACKEND=s3` and `S3_*` for object storage. Malware scanning:
`docker compose --profile clamav up -d` and `CLAMAV_HOST=clamav`.

`COOKIE_SECURE=true` (required outside `dev`) needs HTTPS in front of the web container, except on
`localhost`, which browsers treat as secure.

### Production server

[docs/deploy-server.md](docs/deploy-server.md) is a step-by-step guide (in Uzbek) for a VPS:
Docker, firewall, `.env` with generated secrets, HTTPS, first administrator and company, daily
backups, restore, updates and troubleshooting. In short:

- `docker-compose.prod.yml` adds Caddy, which obtains and renews the TLS certificate for
  `BIWORK_DOMAIN` and is the only service exposed (ports 80/443). Enable it with
  `COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml` in `.env`.
- `deploy/backup.sh` writes a `pg_dump` of the database and an archive of uploaded files to
  `backups/` and keeps `BACKUP_KEEP_DAYS` days; run it from cron. `deploy/restore.sh` restores both
  after a typed confirmation and re-runs migrations.

## Local development

Requirements: Python 3.11+, Node.js 22, PostgreSQL 16, Redis 7 (optional in development),
Tesseract OCR with `eng`, `rus`, `uzb`, `uzb_cyrl` language data
(`apt install tesseract-ocr tesseract-ocr-rus tesseract-ocr-uzb tesseract-ocr-uzb-cyrl`).

```bash
# 1. Database roles and databases (owner runs migrations; biwork_app is subject to RLS)
sudo -u postgres psql <<'SQL'
CREATE ROLE biwork LOGIN PASSWORD 'biwork';
CREATE ROLE biwork_app LOGIN PASSWORD 'biwork_app' NOBYPASSRLS;
CREATE DATABASE biwork OWNER biwork;
CREATE DATABASE biwork_test OWNER biwork;
SQL

# 2. Backend
cd backend
python -m venv .venv && . .venv/bin/activate
pip install -e ".[dev]"
alembic upgrade head
python -m app.cli bootstrap
python -m app.cli seed-demo                       # optional sample company
CELERY_ALWAYS_EAGER=true uvicorn app.main:app --reload --port 8000
# With Redis, run a real worker instead of eager mode:
#   celery -A app.workers.celery_app worker -Q parse,ai,commit,default
#   celery -A app.workers.celery_app beat

# 3. Frontend (proxies /api to http://127.0.0.1:8000; override with BIWORK_API_URL)
cd ../frontend
npm install
npm run dev                                        # http://localhost:5173
```

The defaults in `backend/app/config.py` match the roles above, so no `.env` is needed for local
development. API documentation: http://localhost:8000/api/docs.

## Configuration

All settings are environment variables, validated at start-up; `.env.example` documents each one.
The important groups:

| Area | Variables |
|---|---|
| Database | `DATABASE_URL` (app role), `DATABASE_MIGRATION_URL` (owner), `DATABASE_APP_ROLE`, `REDIS_URL` |
| Security | `JWT_SIGNING_KEY`, `FIELD_ENCRYPTION_KEY` (Fernet, encrypts MFA secrets), `COOKIE_SECURE`, `CORS_ORIGINS`, lockout and rate-limit settings |
| Files | `STORAGE_BACKEND` (`local`/`s3`), `STORAGE_DIR`, `S3_*`, `MAX_UPLOAD_MB`, `MAX_FILES_PER_JOB`, `CLAMAV_HOST` |
| AI | `AI_PROVIDER` (`auto`/`openai`/`gemini`/`anthropic`/`demo`), `AI_PRIMARY_PROVIDER`, `AI_FALLBACK_PROVIDERS`, `<PROVIDER>_API_KEY`, `<PROVIDER>_MODEL_{DEFAULT,COMPLEX,ECONOMY,VISION}`, `AI_ROUTING_ENABLED`, `AI_REVIEW_REQUIRED`, timeouts, retries, `AI_MAX_CALLS_PER_MINUTE`, `AI_MAX_INPUT_TOKENS` ([docs/ai-engine.md](docs/ai-engine.md)) |
| OCR | `OCR_ENABLED`, `OCR_LANGS`, `TESSERACT_CMD` |
| Processing | `CELERY_ALWAYS_EAGER` (dev/tests only), `STAGE_TIMEOUT_SECONDS`, `AGENT_COMMIT_BATCH_SIZE`, `AGENT_CONFIDENCE_THRESHOLD` |

With `APP_ENV=staging` or `prod` the API refuses to start with the development JWT key, without
`FIELD_ENCRYPTION_KEY`, with insecure cookies, in eager mode, with an explicit `AI_PROVIDER` whose
API key is missing, or with `AI_PROVIDER=auto` and no provider key at all. Secrets belong in the environment or a secrets manager, never in the repository.

Per-company settings (language, time zone, currency, date order, finished-goods warehouse, AI
data-handling policy, enabled modules, auto-approval per record type) are edited in the app under
Settings and Administration.

## Database migrations

```bash
cd backend
alembic upgrade head          # apply (uses DATABASE_MIGRATION_URL, the owner role)
alembic check                 # verify models and migrations agree
alembic revision --autogenerate -m "describe the change"
```

Migrations run as the schema owner; the app role only receives grants. When a new table holds
company data, add it to row-level security in the migration (see `TENANT_TABLES` in
`migrations/versions/20261021_0001_initial_schema.py`). Foreign keys declared with `use_alter=True`
must be added explicitly with `op.create_foreign_key` at the end of a migration: autogenerate omits
them. Audit events and stock movements are append-only (UPDATE/DELETE revoked from the app role).

## Tests

```bash
# Backend: needs PostgreSQL with the biwork_test database and both roles (see Local development)
cd backend
ruff check app tests scripts
lint-imports                  # layering contracts: core <- ai <- modules <- agent
pytest                        # 79 tests, about 90 s

# Frontend
cd frontend
npm run typecheck && npm run build

# End-to-end: API running on :8000 with seed-demo data (CELERY_ALWAYS_EAGER=true is fine).
# Starts Vite automatically unless E2E_BASE_URL points at a running app.
npx playwright install chromium
npm run e2e
```

Acceptance scenarios from the specification and where they are tested:

| Scenario | Backend (`backend/tests`) | UI (`frontend/e2e`) |
|---|---|---|
| A — Uzbek text becomes a production report; missing values are not invented | `test_scenarios.py::test_scenario_a_*` | `scenario-a.spec.ts` |
| B — Excel import, column mapping with approval, saved templates | `test_scenario_b_*` | — |
| C — PDF purchase request with lines, routed for approval | `test_scenario_c_*` | — |
| D — duplicates and existing records are detected, never saved twice | `test_scenario_d_*` | — |
| E — company isolation and role/department permissions; approvals need the right permission | `test_scenario_e_*` | role checks in `scenario-a.spec.ts`, `smoke.spec.ts` |
| F — failures are retried; commits are idempotent after a crash | `test_scenario_f_*` | — |
| G — audit trace from source document to database change; tamper detection | `test_scenario_g_*` | provenance step in `scenario-a.spec.ts` |

`test_platform.py` covers authentication, MFA, permission escalation, report corrections and stock
reversals, idempotency and optimistic locking, import policies, auto-approval, AI policy, prompt injection, file formats and
AI payload retention; `test_units.py` covers normalization, parsers, OCR, matching and the mock
adapter; `test_ai_adapter.py` and `test_ai_providers.py` exercise the OpenAI, Gemini and Claude
adapters against a mock HTTP transport, and `test_ai_engine.py` covers routing, fallback, escalation,
caching and health.

## Using a real AI provider

```bash
AI_PROVIDER=auto                 # use whichever keys are set
AI_PRIMARY_PROVIDER=openai
OPENAI_API_KEY=sk-...
OPENAI_MODEL_DEFAULT=<model id>  # required for OpenAI and Gemini; see `python -m app.cli ai-models`
GEMINI_API_KEY=...               # optional fallback
ANTHROPIC_API_KEY=sk-ant-...     # optional fallback (models default to Claude Sonnet/Opus/Haiku 5.5)
```

`python -m app.cli ai-check` validates the configuration and makes one small call per provider.
Every adapter uses structured outputs, so responses are validated against the record type's schema
before they reach validation. Read [docs/ai-engine.md](docs/ai-engine.md) and
[docs/ai-providers.md](docs/ai-providers.md) before enabling an external provider for real company data.

## Known limitations

- HR, EDO, Finance, Maintenance and the full Quality module are planned (phase 4) and shown as
  "Soon" without placeholder data. Quality shows defect analytics from production reports meanwhile.
- The OpenAI, Gemini and Anthropic adapters are tested against stubbed HTTP only; no real-provider
  evaluation has been run yet (`python -m app.cli ai-eval` does it once keys exist). OpenTelemetry
  tracing, SSO and multi-step approval chains are not built yet.
- The Docker images were built and the compose stack was run end to end, except that the Tesseract
  packages could not be fetched in the build sandbox; the OCR code path is covered by the backend
  tests running against a local Tesseract installation.
