# Spec 210: the chatbot's AI key pool, failover, cost and billing through Forbeast

**Project:** Likha (likhaaitech.com) and Incepxion (incepxion.pro) — one codebase; chatbots answer Messenger customers on pages served by the Forbeast gateway (`chatbot.gateway`: forbeast or incepxionbot).
**Stack:** Laravel, Blade + vanilla JS screens, MySQL, queues on Redis/Horizon, PHPUnit/Pest.
**Shape:** build · **Weight:** architectural · **Risk tier:** high (secrets, money, a partner API)

> Committed with the work as `docs/specs/210-ai-key-pool-and-billing.md`. Names no people and no decision ids:
> "the owner" decided, "the reviewer" checks and merges.

---

## Why

The chatbot's AI step runs on one global OpenAI key (`services.openai.key`). When that key runs out of
credit or is rate-limited, every page's AI stops until someone fixes the config. The owner wants a pool of
the company's own keys — several OpenAI keys plus Claude (Anthropic) and DeepSeek — with an automatic
switch to the next key when one fails, the same way pages already switch between Forbeast and Botcake.
Clients never bring keys.

The owner also resells the AI. Each chatbot AI call must record which key, provider and model answered, the
tokens and the cost, and send that usage to the Forbeast gateway the page uses. Forbeast charges it to the
Facebook account that handles the page, from a prepaid load. Before an AI step, the chatbot asks Forbeast
whether that account still has load; at zero the AI step is skipped (the rest of the bot keeps running) and
the chatbot page settings show Forbeast's note. Forbeast's side is built (Forbeast spec 042); its contract
is below.

What exists (read it on this base; confirm before building):
- `ChatbotAiChain::forPage` builds a per-page chain of providers (page `chatbot.ai_provider` /
  `chatbot.ai_fallback`, site defaults `app_settings chatbot.ai.*`); providers come from
  `config services.chatbot_ai.providers`: only `openai` (one key) and `local`. `ChatbotAiProviders::parse`
  understands only OpenAI-shaped answers. A breaker exists in cache only, for providers with `breaker_failures`.
- `chatbot_ai_requests` records every call (sync and async: provider, model_used, fell_back,
  fallback_reason, ai_attempts JSON, latency_ms, prompt_tokens, completion_tokens, path), no cost, no key;
  rows are pruned at about 90 days.
- `page_settings` is Page | Provider | Key | Value with `is_secret` (encrypted) and history; `PageSettings`
  service; site-wide rows use the reserved page `_all`.
- `app/Boardroom/Providers/*` has OpenAI (Responses API), Anthropic and DeepSeek adapters with error
  classification (billing/402, 401, 429 retryable, 529, timeout) and token fields; `App\Boardroom\Support\Secrets`
  encrypts keys; `br_model_capabilities` has prices.
- `ForbeastWatch` is the persistent auto-switch pattern: counters, thresholds, event rows
  (`chatbot.auto_switch`), switch back after a healthy stretch.
- `Gateways` / `ForbeastSite` hold each gateway's URL and site key (`services.forbeast_site`, `services.incepxionbot`).

## Stories

Add to `qa/stories.md` under `## Slice 210 – AI key pool, failover, cost and billing`. Keep these case IDs.

### S-210.1 The owner manages the company's AI keys (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-210.1.1 | happy | Given no keys, when the owner adds an OpenAI key with a label, then it is saved encrypted and the list shows provider, label, status, last used and only the last 4 characters | | Not run |
| S-210.1.2 | happy | Given a saved key, when the owner presses Test, then one cheap real call runs and the result (ok / invalid / no credit / rate limited) shows with the time; a key whose test fails is saved but marked down, never used until a later test passes | | Not run |
| S-210.1.3 | happy | Given a key in use, when the owner disables it, then the next call skips it; enabling puts it back | | Not run |
| S-210.1.4 | happy | Given Claude and DeepSeek keys, when added and tested, then they work the same way | | Not run |
| S-210.1.5 | negative | Given any user who may not change the site-wide chatbot AI settings, when they open or POST the key screen, then 403 and nothing changes | | Not run |
| S-210.1.6 | negative | Given the same key pasted twice, then the second is refused as a duplicate (compared by a hash, never by showing the key) | | Not run |
| S-210.1.7 | negative | Given empty, whitespace-only, multi-line or over-long input, then a clear message and nothing saved | | Not run |
| S-210.1.8 | edge | Given a deleted key, then its encrypted value is gone (not just hidden) and old usage rows keep its label and last 4 | | Not run |
| S-210.1.9 | edge | Given a key that cannot be decrypted (APP_KEY changed), then it shows "cannot decrypt", no 500, and the chain skips it | | Not run |
| S-210.1.10 | edge | Given the existing `services.openai.key`, when no OpenAI key is in the pool yet, then it is used as before (nothing breaks on deploy) | | Not run |

### S-210.2 Each page uses its chosen provider order; keys rotate inside a provider (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-210.2.1 | happy | Given a page set to Claude then OpenAI, when its AI step runs, then Claude is tried first; the page settings and the site default offer OpenAI, Claude, DeepSeek and Local | | Not run |
| S-210.2.2 | happy | Given 3 OpenAI keys, when many calls run, then they are spread across the healthy keys of that provider | | Not run |
| S-210.2.3 | happy | Given a page with no own setting, then the site default applies; an ad group's own setting still wins (existing behaviour) | | Not run |
| S-210.2.4 | negative | Given a provider with no usable key, then it is skipped and the next provider in the chain is used | | Not run |
| S-210.2.5 | edge | Given a model that does not exist for the provider, then it is a model error, not a key failure: no key is marked down | | Not run |
| S-210.2.6 | edge | Given each provider's model, then the page settings choose it as today for Local; OpenAI keeps the node's model; Claude and DeepSeek use a model set on the site default (or page), with a sensible default named in the plan | | Not run |

### S-210.3 A failing key is skipped and retried later (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-210.3.1 | happy | Given key 1 answers 402 / no credit, when a call runs, then key 2 answers in the same call (one answer to the customer) and key 1 is marked down "no credit" | | Not run |
| S-210.3.2 | happy | Given key 1 answers 429, then the next key answers and key 1 rests a few minutes (shorter than for 402 or 401) | | Not run |
| S-210.3.3 | happy | Given key 1 answers 401 (invalid), then it is marked down "invalid" and not retried until the owner tests it again | | Not run |
| S-210.3.4 | happy | Given key 1 rested and its time passed, when the next call runs, then key 1 is tried; on success the mark clears | | Not run |
| S-210.3.5 | happy | Given a switch, then an event row is written (page, provider, from key label, to key label, reason, time; never key text) and shows on the key screen | | Not run |
| S-210.3.6 | negative | Given a 400 caused by the request content, then no key is marked down | | Not run |
| S-210.3.7 | negative | Given one 5xx / 529 / timeout, then the call moves on, but the key is marked down only after the threshold (config) | | Not run |
| S-210.3.8 | edge | Given 20 calls at once hit a key that just died, then all move on, the key is marked down once, and the next key's concurrency limit holds | | Not run |
| S-210.3.9 | edge | Given a cache clear or a worker restart, then a down mark is kept (persisted like ForbeastWatch) | | Not run |
| S-210.3.10 | edge | Given a recovering key fails again, then its rest grows (capped backoff) | | Not run |
| S-210.3.11 | happy | Given every key of every provider in the chain is down, then the AI step takes its failed path (no exception), the row says "all keys failed", nothing is billed, and the owner sees one alert on the key screen (not one per message) | | Not run |
| S-210.3.12 | edge | Given an async ticket queued while keys die, then the worker follows the same chain and outcome as a sync call | | Not run |

### S-210.4 Every chatbot AI call records key, provider, model, tokens and cost (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-210.4.1 | happy | Given a successful call, then its `chatbot_ai_requests` row has provider, model_used, key label and last 4, prompt/completion tokens and cost in integer micro-USD from the price list | | Not run |
| S-210.4.2 | happy | Given failover (key 1 402, key 2 ok), then ai_attempts lists both with key label and reason; the row names key 2; only key 2's tokens are costed | | Not run |
| S-210.4.3 | happy | Given Claude and DeepSeek answers, then text, tokens and errors reach the same internal shape as OpenAI's | | Not run |
| S-210.4.4 | negative | Given an answer with no usage block, or a model not in the price list, then cost is null and the row is flagged; never 0 | | Not run |
| S-210.4.5 | edge | Given tiny calls, then cost keeps micro precision (never rounded to 0) | | Not run |
| S-210.4.6 | edge | Given a queue retry of the same call, then one cost row, not two | | Not run |
| S-210.4.7 | edge | Given the prune of old rows, then a row whose usage has not reached Forbeast is never pruned | | Not run |

### S-210.5 Usage reaches the page's Forbeast exactly once (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-210.5.1 | happy | Given costed rows on pages whose `chatbot.gateway` is forbeast, then a queued sender POSTs them in batches of at most 100 to that gateway's `/api/v1/site/ai-usage` with its site key, `client_ref` = the row's request id, `at` with a time-zone offset; each row is marked with the result | | Not run |
| S-210.5.2 | happy | Given pages on the incepxionbot gateway, then their usage goes to the incepxionbot gateway, not to forbeast | | Not run |
| S-210.5.3 | negative | Given an item answers `duplicate`, then the row is marked sent (the first try had landed) | | Not run |
| S-210.5.4 | negative | Given `page_not_found` or `unbilled`, then the row is marked so, not retried forever, and the key screen shows the count | | Not run |
| S-210.5.5 | edge | Given Forbeast is down, times out, answers 5xx or 429, then rows stay pending and are retried with backoff, in order, without blocking chat | | Not run |
| S-210.5.6 | edge | Given 401 from Forbeast (site key changed), then sending pauses, the key screen shows it, and nothing is dropped | | Not run |
| S-210.5.7 | edge | Given a page with no gateway set up, or a row with null cost, then it is not sent as a charge (null cost is sent as `cost_micros: null` so Forbeast flags it, per the contract) | | Not run |
| S-210.5.8 | edge | Given a 10,000-row backlog, then it drains in batches within Forbeast's rate limit | | Not run |

### S-210.6 At zero load the AI step is skipped with Forbeast's note (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-210.6.1 | happy | Given Forbeast answers `stopped` for the page, when its AI step runs, then no provider call is made, the step takes its failed path with reason "no load", and the rest of the flow runs | | Not run |
| S-210.6.2 | happy | Given `ok`, `low` or `unbilled`, then the AI runs | | Not run |
| S-210.6.3 | happy | Given `low` or `stopped`, then the chatbot pages screen shows Forbeast's English note on that page's row (and in its settings) | | Not run |
| S-210.6.4 | edge | Given the status is cached per page for 60 seconds, then a top-up in Forbeast resumes the AI within a minute | | Not run |
| S-210.6.5 | edge | Given Forbeast unreachable, `page_not_found`, or no gateway set up, then the AI runs (never stopped by an error) | | Not run |
| S-210.6.6 | edge | Given many pages, then status is fetched in batches of up to 100, not one request per message | | Not run |

### S-210.7 Secrets never leak (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-210.7.1 | negative | Given a full run (add, test, failover, error), when logs, failed_jobs, exception output, HTTP responses, page source, page_settings history and ai_attempts are searched for the test key, then only the last 4 appear | | Not run |
| S-210.7.2 | negative | Given a provider error that echoes headers or the key, then it is scrubbed before it is stored or logged | | Not run |
| S-210.7.3 | negative | Given a customer message asking for the key, then no secret is reachable (keys never enter a prompt) | | Not run |
| S-210.7.4 | edge | Given every add, edit, test, disable and delete of a key, then it is in the page_settings history with who and when, no key text | | Not run |

**Tests:** one failing test per case first, named with its case ID, at the seam the case describes. Provider
and Forbeast calls are faked with `Http::fake` (no real network in tests); expected values come from the
case, never recomputed the way the code does it.

## Constraints

**Decisions already made** (settled with the owner; don't reopen them unless something is actually broken):

| Topic | Decision |
|---|---|
| Whose keys | The company's own keys only. Clients never add keys. Only users who may change the site-wide chatbot AI settings manage keys. |
| Order | Per page (and site default) the order is a list of providers; keys inside a provider rotate and fail over automatically. |
| Storage | No new table unless the plan proves it is needed: keys as encrypted `page_settings` rows (`is_secret`) on the reserved site page, down marks and switch events as rows too (the ForbeastWatch pattern); usage and cost as new columns on `chatbot_ai_requests`. |
| Providers | Reuse the Boardroom adapters' error classification and request/response handling for Anthropic and DeepSeek (a chat-message wrapper is fine); OpenAI keeps the chat-completions path the chatbot uses today. |
| Which calls are billed | Only the chatbot flow's AI step (sync and async). Page content check, paste mapping and order encode keep their own keys and are not billed. |
| Cost | Integer micro-USD from one chatbot price list in config (USD per 1M tokens per model, input and output), with a default list for the models in use; a model missing from the list gives a null cost. |
| Billing | Usage goes to the gateway the page uses (`chatbot.gateway`); the charge, rate (₱58/USD) and markup (×2) are Forbeast's job, not Likha's. |
| Zero load | Ask Forbeast `GET /api/v1/site/ai-load` (cached 60 s per page). `stopped` skips the AI step; anything else, or any error, runs it. Show Forbeast's note in English as given. |
| Base | `vps2/main` (the live code of both sites). Branch `feat/210-ai-key-pool`. |

**Forbeast contract** (built in Forbeast spec 042; examples in the Forbeast repo `docs/ai-load.md`, readable at C:\mira\mira\projects\forbeast\.claude\worktrees\ai-load-042\docs\ai-load.md):
- Auth: `Authorization: Bearer <site key>` (the gateway's site key, `ForbeastSite` config). Errors are `{error, message}`.
- `POST /api/v1/site/ai-usage` body `{records: [{client_ref, page_id, at, provider, model, key_label, tokens_in, tokens_out, cost_micros}]}` — `page_id` is the Meta page id, `at` is ISO 8601 with offset, `cost_micros` integer micro-USD or null, at most 100 records → `{results: [{client_ref, result, charged_php?}]}` where result is `stored`, `duplicate`, `unbilled`, `needs_price`, `page_not_found` or `invalid`.
- `GET /api/v1/site/ai-load?pages=<id,id,…>` (at most 100) → `{pages: [{page_id, state, balance_php, note}]}`, state `ok`, `low`, `stopped`, `unbilled` or `page_not_found`.
- Read the contract file for the exact field rules (lengths, limits, offsets) before building; record any difference as a ruling.

**Threat model.** Untrusted: provider responses and error texts, Forbeast responses, customer messages,
form input on the key screen. Trusted: config, env, this repo, the owner's own inputs after validation. High:
key storage and display, anything that could put a key in a log, prompt or response.

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session.
- Talk only to the reviewer. Plan first, then build.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines in commits or the PR body.
- Code comments explain the reason itself, in the language and style of the surrounding code; comments, commits, branch names and the PR never name people or decision ids; the spec number may appear in branch names and commits.
- No commands that start with an environment variable. Downloads: none beyond the project's own composer/npm installs.
- Never open `.env`; config names only. No real provider or Forbeast calls in tests.
- Screens: header and filters stay fixed while rows scroll; no horizontal scrolling; long lists paginate.
- This repository also serves Incepxion: nothing site-specific is hard-coded.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report what you tried and what you need.
- Size: large job. If it is turning out much bigger, stop and report before going on.

## Done when

- [ ] Cases S-210.1.1 – S-210.7.4 pass, each with a test named after it.
- [ ] The result has a red line (test name, first failure line) for every feat/fix slice, watched before the code.
- [ ] The key screen, the page settings changes and the note are reachable for the reviewer to try locally (with fake keys and `Http::fake`-style local stubs, or a documented way).
- [ ] The full suite adds no new failures (name any pre-existing red test); migrations run and roll back.
- [ ] `qa/stories.md` has this spec's stories with "Last run" filled.
- [ ] `docs/specs/210-ai-key-pool-and-billing.result.md` is filled in.

## Out of scope

Mirroring orders to Forbeast (spec 211); online payment; per-client keys; billing the content check, paste
mapping or order encode; changing Forbeast; any deploy.

## Report back

Fill in `docs/specs/210-ai-key-pool-and-billing.result.md` from its template and commit it with the work —
including every ruling (`Ruling: <decision> — <why> — <cost if wrong>`) and the deferred minors — then message
the reviewer that it's ready. Include **Merge danger**: one-way or two-way door, blast radius, how to revert.
