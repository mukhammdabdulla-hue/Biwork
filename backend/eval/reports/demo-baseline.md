# BIWORK Agent — provider evaluation

Generated 2026-10-21 12:01 UTC on the synthetic dataset in `backend/eval/dataset`.

> **Demo mode rows are not AI results.** They measure the rule-based stand-in used when no provider credentials are configured.

| Provider / model | Type acc. | Field acc. | Schema valid | Dup. precision | Dup. recall | False accept | Correction rate | Avg latency | Cost / doc | Cost / validated record |
|---|---|---|---|---|---|---|---|---|---|---|
| demo (demo-rules-v1) — DEMO | 95.7% | 87.2% | 100.0% | 100.0% | 100.0% | 20.0% | 21.7% | 311 ms | $0.0000 | $0.0000 |

## Field accuracy by language

| Provider | en | ru | uz-Cyrl | uz-Latn |
|---|---|---|---|---|
| demo | 81.2% | 96.4% | 0.0% | 96.5% |

## Mismatches

- **demo** `pr-uz-cyrl-1`: No Production report data could be extracted from this content
- **demo** `pr-uz-conflict`: department: expected '1-sex', got '100 dona A mahsulot ishlab chiqarildi (aslida 10'
- **demo** `task-en-1`: priority: expected 'high', got None
- **demo** `doc-ru-1`: doc_type: expected 'invoice', got None
- **demo** `doc-en-1`: doc_type: expected 'contract', got None; currency: expected 'USD', got None
