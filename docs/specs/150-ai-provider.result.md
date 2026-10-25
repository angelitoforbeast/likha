# Result 150: chatbot AI provider (OpenAI / Local), fallback, per-request record

## Plan

Base: `feat/149-comment-replies` at 52974859. Base run before any change: `tests/Feature/Chatblast` = 882 tests, OK, 1 skipped (38 PHPUnit deprecations). The AI pool tests (`ChatbotAsyncAiTest`) are inside that folder.

### Design (details the brief leaves open)

**Providers (config only).** `config/services.php` gets `chatbot_ai`:
`providers.openai` (label OpenAI, url `https://api.openai.com/v1`, key = the existing `services.openai.key`) and `providers.local`
(label Local, url `CHATBOT_AI_LOCAL_URL`, key `CHATBOT_AI_LOCAL_KEY`, model `CHATBOT_AI_LOCAL_MODEL`, `localhost_only` true).
A third OpenAI-compatible provider is one more entry under `providers` (no UI). A new `ChatbotAiProviders` class reads them:
ready or not ("not set up"), the request URL (`<base>/chat/completions`, `/v1` added when missing, no double slash), the key
(no header when empty), the localhost check (http, host 127.0.0.1 or localhost, any port; anything else = not set up, never called).

**Chain.** `ChatbotAiChain` resolves the chain of a page: provider = page `chatbot.ai_provider` ?? site default ?? `openai`;
fallback = page `chatbot.ai_fallback` ?? site ?? (Local → `openai`, OpenAI → none). Chain = [provider] + fallback (no repeats).
OpenAI uses the node's model; Local uses its own model (page ?? site ?? env). Local timeout = page ?? site ?? 8 s.
The sync path (`FlowEngine::ai` when not async: CHATBOT_AI_ASYNC=false, Test tab, sequences, no pool) runs the chain in turn
inside today's 30 s; the async pool runs it inside the row's `deadline_at` (OPENAI_TIMEOUT from submit). An OpenAI-only chain
keeps today's exact call (`ChatbotAi::complete` body, URL, key, errors). Local: concurrency cap = cache-lock slots (default 4,
shared by workers and the pool, expire on their own), circuit breaker = cache counter (3 in a row → skip 30 s), both site-wide.
Unusable = non-2xx, no choices, empty after trim, or only a `<think>` block (stripped for non-OpenAI providers only, so OpenAI
parsing is unchanged). Errors are fixed texts per provider ("Local refused (HTTP 500).", "Local did not answer.", "Local did not
answer in time.", "Local gave an empty answer.", "Local is not set up."); OpenAI's texts stay as today. When all fail the step
fails with the last provider's text, as today.

**Pool.** A claimed row carries its chain snapshot (`chain` column, written at submit). On a failed attempt with a next provider
and time left, the pool starts the next attempt at once, the row staying `sending` under the same owner; only the final outcome
calls `finish()` (conditional, so a late answer, the sweep, a restarted or second pool never resume twice). Each attempt has a
token; a callback whose token is no longer current is ignored. OpenAI 429 keeps spec 087's rule (requeue, halve); the attempts
so far are kept on the row, so the retry goes on from OpenAI, not from Local. Local 429 = fall back at once. The sweep's late
message names the last provider of the chain ("OpenAI did not answer in time." for OpenAI-only rows, as today).

**Record.** New nullable columns on `chatbot_ai_requests`: `provider`, `model_used`, `chain`, `fell_back`, `fallback_reason`,
`ai_attempts` (JSON list of {provider, model, ms, ok, why}; no text), `latency_ms`, `prompt_tokens`, `completion_tokens`, `path`
(async | sync); `messages` and `context` become nullable. Sync and Test-tab calls write one row with status `sync` (nobody claims,
sweeps or resumes it). Retention: after 2 days `messages`, `answer` and `context` are nulled (customer text); rows go after 90 days.
Every write is guarded with `Schema::hasColumn`, so the AI step works as today before the migration.

**Settings.** Per page (page_settings, provider `chatbot`, history kept): `ai_provider`, `ai_fallback` (`openai`, `local` or
`none`), `ai_local_model`, `ai_local_timeout`. Site default: app_settings `chatbot.ai.*` (provider, fallback, local_model,
local_timeout, local_concurrency, breaker_failures, breaker_seconds) is the value read; each change also writes the history line
as a page_settings row on the reserved page `_all` (same key), shown in Changes as "Site default". The `ai_*` keys join
`ChatbotFields::RESERVED` and `PageSettings::SYSTEM_KEYS` (the grid and the generic endpoints refuse them). Chatbot keys named
like a key (`…key`, `api_key`) are refused by the generic endpoints. Endpoints (access = `/chatblast`, like the page):
`POST /chatbot/ai` (page_names[] ≤ 200, all or none), `POST /chatbot/ai/site`, `GET /chatbot/ai/compare` (page, from, to).

### Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Provider layer + chain in the sync path | backend | high | C.1, C.2, C.4, C.5, C.7, C.8, C.9, C.10, C.13 (sync), C.13b, C.15, C.16, C.17, C.18, E.1, E.2, E.5, E.6, E.7, E.8, E.9, E.10, F.1, F.2, F.4, F.5, F.9 (sync) |
| 2 | Async pool chain + finish / sweep / resume | backend | high | C.3, C.6, C.11, C.12, C.13 (pool), C.14, F.3, F.6, F.7, F.9, F.10 |
| 3 | Record columns, migration, retention | backend | medium | D.1, D.2, D.5, D.6, D.7, D.11, D.12, D.13, E.3 (rows) |
| 4 | Settings endpoints + Changes | backend | high | A.1–A.10 (server), B.1–B.7, E.3 (responses), E.4, F.8 |
| 5 | /chatbot/pages AI control + site default | frontend | medium | A.1 (screen), A.11, F.8 (screen) |
| 6 | Comparison endpoint + view | backend + frontend | medium | D.3, D.4, D.8, D.9, D.10, D.14 |
| 7 | Full Chatblast suite, key grep, review, result | — | — | all |

Not parallel: tasks 1–3 share `ChatbotAi*`; 4–6 share `ChatbotController` and `chatbot/index.blade.php`. Review: the
skeptic-reviewer (adversarial) after tasks 1–2, after 3–4, and after 5–6, in the foreground.

## Progress

- Stage 0: vendor copied from rel-137 (same composer.lock); base run 882 OK; spec and plan committed.

## Files

(filled at the end)

## Story changes

(none yet)

## Evidence per case

(filled per stage: case, test, red line, final result)

## Rulings

1. Site default "in app_settings with its own history line": app_settings holds the value the reply path reads; the history line
   is a page_settings row on the reserved page `_all`, written in the same transaction, so Changes and history work unchanged.
2. E.10: the request always goes to `…/v1/chat/completions`: `/v1` is added when the base lacks it (Ollama serves only `/v1`).
3. The `<think>` strip applies to non-OpenAI providers only, so OpenAI parsing stays byte-for-byte today's.
4. "Path sync" rows use status `sync` so the pool, the sweep and the resume never touch them.

## Merge danger

(filled at the end)

## Commits

(filled at the end)

## Open points

(none yet)
