# Plan: spec 210 AI key pool, failover, cost and billing

Base: `8cdb1a7b` (the same tree as `vps2/main`; `git diff HEAD vps2/main` is empty). Branch `feat/210-ai-key-pool`.

## What is there (confirmed on this base)

- `ChatbotAiChain::forPage` builds the provider chain (page `chatbot.ai_*`, site `app_settings chatbot.ai.*`); `complete()` is the
  waiting path, `ChatbotAiPool` the async path (Guzzle async, one ticket per row). Both use `ChatbotAiChain::request()` and
  `ChatbotAiProviders::parse()`, OpenAI-shaped only. Providers in `services.chatbot_ai.providers`: `openai` (key from
  `services.openai.key`), `local`. The breaker is cache-only and only for providers with `breaker_failures`.
- `chatbot_ai_requests` keeps the record (`provider`, `model_used`, `ai_attempts`, tokens, `path`); the sweep deletes
  `answered`/`failed` rows after 2 days and `resumed`/`sync` rows after 90.
- `page_settings` (`PageSettings::set` keeps history, `is_secret` is encrypted with `Crypt`), reserved page `_all`.
- Boardroom `HttpAdapter` has `classify()` (billing / 401 / 403 / 404 model / 429 / 529 / 5xx / 400 kinds) and
  `httpFailure()`; `AnthropicAdapter` and `DeepSeekAdapter` build payloads and parse answers into `ProviderResult`.
- `ForbeastWatch`: counters in cache, switches and events as `page_settings` rows (history = the event feed).
- `Gateways::active($page)` and `Gateways::config($gw)` give each gateway's URL and site key; `ForbeastSite` calls
  `<gateway url>/v1/site/...` with `Bearer <site key>`.
- The site-wide AI settings (`POST /chatbot/ai/site`) are gated by `NavAccess::allows($role, '/chatblast')`.

## Design

### Storage (no new table)

Keys live in `page_settings` on the reserved page `_all`, provider `ai_keys`:

| Key | Value | Notes |
|---|---|---|
| `k<id>.secret` | the key text, `is_secret` (encrypted) | on delete every row of it (active and history) gets `value = null` |
| `k<id>.info` | JSON `{provider, label, last4, hash, enabled, deleted}` | `hash` = HMAC-SHA256 of the key with the app key, for duplicates; every add, edit, enable, disable and delete is a new row (history: who, when) |
| `k<id>.health` | JSON `{state: ok|down, reason, until, rest, at, test, tested_at}` | the down mark, persisted; a test writes it too (with the user) |
| `event` | JSON `{page, provider, from, to, reason, at}` | one row per switch away from a key; the history is the feed on the key screen |
| `alert` | JSON `{at, page, error}` | "all keys failed", at most one per hour |
| `usage.<gateway>.paused` | JSON `{at, error}` | Forbeast answered 401/403 to the usage sender |

`<id>` is 8 random lowercase hex characters. Rows are written with `system = true`; the page-settings screen cannot clear
`ai_keys` rows (guard added to `deactivate`).

New nullable columns on `chatbot_ai_requests` (one migration, reversible): `key_id` (12), `key_label` (100), `key_last4` (4),
`cost_micros` (unsigned big int), `cost_flag` (20: `no_usage` | `no_price`), `usage_status` (16), `usage_gateway` (20),
`usage_sent_at`, `usage_result` (20), indexes `(usage_status, usage_gateway, id)` and `(key_id, created_at)`.

### Providers

`services.chatbot_ai.providers` gets `anthropic` (label Claude, default model `claude-haiku-4-5`, timeout 20) and `deepseek`
(label DeepSeek, default model `deepseek-chat`, timeout 20), both `pooled => true`; `openai` becomes `pooled => true` too.
`ChatbotAiWire` (new) builds the request and reads the answer per provider:

- OpenAI: the chat-completions body and parse of today (`ChatbotAi::payload` / `ChatbotAi::parse`), plus the Boardroom
  classification of the error.
- Claude: a small subclass of `AnthropicAdapter` that turns chat messages into `system` + `messages` and exposes its
  `parse()`/`httpFailure()`.
- DeepSeek: a small subclass of `DeepSeekAdapter`, messages passed as they are.

Every answer becomes `{ok, text, error, prompt_tokens, completion_tokens, kind}`; `kind` is `ok`, `no_credit`, `invalid`,
`rate_limited`, `failing` (5xx/529/timeout/network), `model`, `request` (400/422/empty). The error text stays a fixed sentence
with the provider label and HTTP status (never the provider's text).

### Key choice and failover (`ChatbotAiKeys`, new)

- `usable($provider)`: the provider's keys that are enabled, not deleted, decryptable and not resting, rotated round-robin
  (cache counter). None in the pool and provider `openai`: the legacy `services.openai.key` (label "Server key").
- The chain is flattened into steps `(provider, key)`. A failed step moves to the next key of the same provider; a `model` or
  `request` failure skips the provider's other keys (it is not the key's fault) and no key is marked.
- Marks: `no_credit` rests 30 min, `rate_limited` 2 min, `failing` 1 min after 3 failures in 5 min (config); a key that fails
  again after a rest gets double the last rest, capped at 6 hours; `invalid` (401/403) has no end and waits for a passing
  Test. Success clears the mark. A short cache lock makes 20 calls that hit the same dead key write one mark and one event.
- Optional per-key cap (`key_concurrency`, null = no cap, as OpenAI today) with cache-lock slots like Local.
- Every chain whose steps all failed with at least one pooled key: error "All AI keys failed.", one `alert` row per hour.
- Pool: the 429 of the legacy OpenAI key keeps today's slow-down and requeue; a pooled key's 429 marks it and moves on.

### Cost

`services.chatbot_ai.prices` (USD per 1M tokens, input and output) with defaults for the models in use. `cost_micros` =
`ceil(tokens_in × in + tokens_out × out)` (USD per 1M tokens is micro-USD per token), so a tiny call is never 0. No usage
block (`prompt_tokens` or `completion_tokens` null) → `cost_flag = no_usage`, cost null; model not in the list (after
dropping a date suffix) → `no_price`, cost null. Only the winning attempt is costed. Local answers are not billed.

### Usage sender (`SendChatbotAiUsage` job, every minute, unique)

Rows with an answer from a pooled provider are written `usage_status = pending` and `usage_gateway = Gateways::active(page)`
when that gateway has a URL and site key, else `no_gateway`. The job, per gateway, takes pending rows in id order, at most
20 batches of 100 per run (Forbeast allows 120 requests a minute over the three routes), POSTs
`<gateway url>/v1/site/ai-usage` and marks each row: `stored`/`duplicate` → `sent`, `stored` with `needs_price` → `needs_price`,
`unbilled`, `page_not_found`, `invalid`. A missing result stays pending. 5xx, 429 and timeouts stop that gateway for this run
and back it off (1, 2, 4 … 30 min, cache); 401/403 writes the `usage.<gateway>.paused` row and probes again every 15 minutes.
The prune never deletes a `pending` row.

### AI load (`ChatbotAiLoad`, new)

Before the AI step (sync and async, in `FlowEngine::ai`): the page's state from the cache (60 s). A miss fetches
`GET <gateway url>/v1/site/ai-load?pages=…` for this page plus up to 99 other known pages of that gateway; a minute command
(`chatbot:ai-load`) refreshes all known pages in batches of 100. `stopped` → no provider call, the step takes its failed port
with "No AI load: …", a record row with that error. Any other state, any error, `page_not_found`, no gateway → the AI runs
(an error is cached 60 s so a down Forbeast is not asked per message). The `/chatbot/data` rows carry the last note (low or
stopped, kept 15 min), shown on the row and in the page's AI dialog.

### Screens

- `/chatbot/ai-keys` (same gate as the site AI settings): fixed header with the add form; the key list (provider, label,
  status, last used, last 4, last test and time) with Test / Disable or Enable / Delete; the "all keys failed" alert; the
  usage sender per gateway (pending, paused, page_not_found, unbilled, needs_price, invalid counts); the switch events,
  paginated. JSON endpoints under `/chatbot/ai-keys/...`, throttled.
- `/chatbot/pages` AI dialog: providers OpenAI, Claude, DeepSeek, Local; a second "then" fallback (the fallback is a list);
  Claude and DeepSeek model fields; an "AI keys" button.

## Steps (each: red test first, then code, then commit)

1. Migration and config (columns, providers, prices, key settings).
2. `ChatbotAiKeys` storage and the key screen (S-210.1.*, S-210.7.4).
3. `ChatbotAiWire`: Claude and DeepSeek shapes, classification (S-210.4.3, S-210.2.5, S-210.3.6).
4. Keys in the chain, sync and pool: rotation, failover, marks, events, alert (S-210.2.*, S-210.3.*).
5. Cost on the record and the prune guard (S-210.4.*).
6. Usage sender (S-210.5.*).
7. AI load and the note (S-210.6.*).
8. Settings dialog changes (S-210.2.1, S-210.2.6).
9. Secret sweep test (S-210.7.1–3).
10. Stories, full suite, migrate/rollback, result.

## Risks

- The pool is the busiest code path: legacy OpenAI behaviour must stay byte-for-byte (existing tests are the guard).
- `page_settings` writes per mark: bounded by the cache lock (one per key per mark).
