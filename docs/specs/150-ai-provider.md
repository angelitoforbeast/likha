# Brief 150: choose which AI answers the chatbot (OpenAI / Local), per page, with automatic fallback and a per-request record

## Why
Every chatbot AI reply goes to OpenAI gpt-4o-mini today. The owner wants to test a local AI (an open model served by Ollama on an office PC, OpenAI-compatible API) on one page first, Tech Flask Ph, while every other page stays on OpenAI exactly as now. He wants to see and change in the chatbot settings which AI answers each page, and an automatic switch to the next AI when one fails, so a customer always gets one reply. He also needs a per-request record (which AI answered, how fast, tokens, why it fell back) to compare quality, speed and cost.

## Code facts (from a read of the current code; check them, they may have moved)
- AI node: `FlowEngine.php:364-385` (model from node config, default gpt-4o-mini, max_tokens 250; async via `ChatbotAiQueue::submit`, sync via `ChatbotAi::complete`; failure takes the `failed` port with note `AI failed: <error>`).
- `FlowGraph.php:128` replaces model names not matching `/^[a-z0-9][a-z0-9.\-:]{0,60}$/i` with gpt-4o-mini; `:133` caps max_tokens.
- `ChatbotAi.php`: hard-coded URL (:14), sync timeout 30 s (:15), key `services.openai.key` (:24), payload {model, messages, max_tokens} (:37-40), `parse()` returns fixed errors only (:46-55).
- `ChatbotAiPool.php`: async postAsync, deadline from `services.openai.timeout` (OPENAI_TIMEOUT 60 s, counted from submit), 429 requeue + halve the limit (:160-170), 5xx/connection error fails at once (:143), concurrency `CHATBOT_AI_CONCURRENCY` 50, `ChatbotMockAi` bypass (:125-128).
- `ChatbotAiQueue.php`: `finish()` is a conditional UPDATE on status 'sending' + claimed_by (:291-301); `sweep()` fails late rows with "OpenAI did not answer in time." (:329-338) and deletes finished rows after KEEP_DAYS = 2 (:204, :348) because they hold customer text.
- `ChatbotRunner::resumeAi` default error "OpenAI did not answer." (:152); `resume` flips the row to resumed once (:166-215).
- Settings: /chatbot/pages via `ChatbotController` (access `NavAccess::allows(role, '/chatblast')`); the "Replies via" switch (`ChatbotController::provider`, `POST /chatbot/provider`, spec 111) is the precedent for a per-page select written with `PageSettings::set` (history kept); `GET /chatbot/changes` lists `chatbot.*` keys with old/new, user, Manila time; `_all` is a reserved page name; `PageSettings::SYSTEM_KEYS`, `ChatbotFields::RESERVED` and `SECRET_KEYS` decide what the grid refuses or hides.

## Stories
Add these to `qa/stories.md` as `## S-150 Chatbot AI provider and fallback` on this branch. P1 = all six. Write one failing test per case first, named with its case ID, at the seam the case describes; expected values come from the case, not recomputed the way the code does it. Use fake HTTP handlers for OpenAI and for Local (spec 087's test style). Cases marked (owner) are checked by a person and are not tested by you.

S-150A Choose which AI answers, site default plus per-page override
- A.1 Site default OpenAI, no override: every page shows "OpenAI (default)"; set Tech Flask Ph to Local and reload: it shows Local, every other page still OpenAI.
- A.2 Clearing an override returns the page to the site default; the old value stays in history.
- A.3 Changing the site default changes pages without override only.
- A.4 No `/chatblast` access (or signed out): 403 / sign-in redirect, nothing changes. Anyone WITH `/chatblast` access may view and change it (owner's decision).
- A.6 Unknown provider name, a page not on the chatbot list, or more than 200 pages in one post: 422, nothing changes (all or none, like Replies via).
- A.7 Choosing Local when no Local base URL is configured: refused with a readable message ("Local AI is not set up yet"), old value stays.
- A.8 Page key case and space insensitive ("tech flask ph" = "Tech Flask Ph").
- A.9 Two saves for the same page at the same moment: exactly one active row, both in history.
- A.10 The Local model name allows Ollama names (`qwen3:14b`, `hf.co/org/model`, letters, digits, `.:-_/`, up to 100 chars); it is stored exactly, never silently replaced with gpt-4o-mini.
- A.11 At 390 x 844 the controls are readable; a failed save shows the old value and an error, not the new one.

S-150B Every change is logged
- B.1 Changes shows page, field "AI provider", old, new, user, Manila time.
- B.2 Model, timeout and chain changes each get their own entry.
- B.3 A site-default change is logged with user and time and shown in Changes too.
- B.4 Saving the same value: "unchanged", no new entry. B.5 A rejected save leaves no entry.
- B.6 Manila-date filtering includes the whole end day. B.7 A deleted user's entry still shows with a placeholder.

S-150C Ordered fallback, exactly one reply
- C.1 Chain Local → OpenAI, Local answers: Local's text sent once, OpenAI not called, no fallback recorded.
- C.2 Local HTTP 500 or connection refused: OpenAI answers, sent once, recorded "fell back from Local: error"; the failed port is not taken.
- C.3 Local over its timeout: abandoned, OpenAI answers within the overall deadline, one reply.
- C.4 Local 200 with empty/whitespace text, no `choices`, or only a `<think>…</think>` block: unusable, next provider answers. A `<think>` block before real text is stripped and the rest is used.
- C.5 Every provider fails: today's behaviour (failed port, `AI failed: <fixed message>`), each failure recorded.
- C.6 A late answer after the next provider already answered is ignored: no second send, no second resume.
- C.7 A chain with one provider only: fails as today, no hidden fallback.
- C.8 A provider in the chain that is not configured is skipped with reason "not set up".
- C.9 Local down (connection refused at once): fallback at once, not after the timeout.
- C.10 Local slow but within its timeout: Local's text is used, latency recorded.
- C.11 The chain fits the overall deadline: the next provider still gets its time, or the run fails once cleanly; sweep and chain never produce two resumes.
- C.12 Pool restart or a second pool while a request is on its second provider: no second reply.
- C.13 Local concurrency cap (default 4): requests beyond it go straight to the next provider; none lost, none answered twice.
- C.13b Circuit breaker: after 3 Local failures in a row, Local is skipped for 30 s (both configurable), then tried again.
- C.14 429 from OpenAI keeps today's rule (requeue, halve); 429 from Local falls back at once.
- C.15 The sync path (no pool, CHATBOT_AI_ASYNC=false, Test tab, sequences) uses the same chain and gives the same single reply.
- C.16 Filipino / non-ASCII text (₱, ñ) passes unchanged through Local and the fallback.
- C.17 Failures store only fixed reasons ("Local did not answer", "Local refused (HTTP 500)"), never the provider's error body, the key or customer text.
- C.18 Local gets the same messages and max_tokens as OpenAI would, with the provider's own model name (not the node's gpt-4o-mini).

S-150D Per-request record and comparison
- D.1 OpenAI answer: provider "openai", model, latency ms, prompt and completion tokens from `usage`, fell_back false.
- D.2 Local failed then OpenAI answered: answering provider OpenAI, the Local attempt with its reason and latency, fell_back true.
- D.3 A comparison view for one page and a date range: per provider requests, answered, failed, fallbacks, average and p95 latency, total input and output tokens.
- D.4 No access: 403. D.5 All failed: every attempt with its reason, no tokens.
- D.6 The record and the view hold no customer text or answer.
- D.7 No `usage` returned: tokens null (shown "–"), never 0; averages skip nulls.
- D.8 A 30-day range covers the whole range (records kept 90 days).
- D.9 Empty range or page: "no requests", no divide by zero. D.10 Manila day boundaries.
- D.11 Sync and Test-tab calls are recorded too, marked "sync".
- D.12 Before the migration runs, the AI step still works as today (guard with Schema::hasColumn).
- D.13 A redispatch never doubles a record or its tokens. D.14 Readable at 390 x 844.

S-150E Keys in config only; Local only on the server's localhost
- E.1 Each provider uses only its own key from config/env (OpenAI's existing key; `CHATBOT_AI_LOCAL_KEY` optional for Local).
- E.2 Local with no key sends no Authorization header and works.
- E.3 No key value appears in any DB row, page, response, Changes entry, event or log; no page has an input for a key.
- E.4 Settings named like a key (`ai_key`, `api_key`) are refused by the generic endpoints.
- E.5 The Local base URL must point at 127.0.0.1 or localhost (http only, any port); anything else (public IP, a name, 169.254.169.254, file://) is refused and never called.
- E.6 Redirects from Local are not followed.
- E.7 A missing key for OpenAI skips it with "not set up" (like NO_KEY today), the key never in the error.
- E.8 An error body echoing the key stores only the fixed message.
- E.9 Values come from config() so `config:cache` works.
- E.10 Base URL with or without a trailing slash or `/v1`: the request goes to `<base>/chat/completions` once, no double slash.

S-150F Pages left on OpenAI behave exactly as before
- F.1 No AI provider setting anywhere: one POST to https://api.openai.com/v1/chat/completions with the same key, model, max_tokens and messages as today; nothing to Local.
- F.2 The OpenAI request body is byte-for-byte today's `ChatbotAi::payload` output.
- F.3 Tech Flask on Local does not change another page's request, timing or record.
- F.4 An OpenAI-only page whose OpenAI fails takes the failed port as today; Local never tried unless in its chain.
- F.5 Local down: other pages never call Local (no health probes in the reply path).
- F.6 The spec 087 429 rule is unchanged.
- F.7 `ChatbotAsyncAiTest.php` and S-087.1 to S-087.10 pass with no change to those tests.
- F.8 Incepxion with no new env vars: OpenAI default, the feature invisible except the controls.
- F.9 `ChatbotMockAi` stress path still bypasses providers.
- F.10 OpenAI pages get no extra calls and no extra waits; added overhead stays within a few ms per request.

Owner checks (not tested by you): stop the Local tunnel and ask Tech Flask a question, one reply arrives from OpenAI; with Local on, judge answer quality against OpenAI; change the setting on a real phone.

## Decisions already made (do not reopen unless something is broken)
| Topic | Decision |
|---|---|
| Providers in this spec | OpenAI and Local only. Build the provider layer so a third OpenAI-compatible provider (hosted) is config only later; no Hosted UI now. |
| Where the choice lives | Per page in page_settings (chatbot keys, history kept, shown in Changes). Site default in app_settings with its own history line, also shown in Changes. No new settings table. |
| Who may change it | Everyone with `/chatblast` access (the chatbot section's permission). |
| What is shown and editable | Provider, the Local model name, the Local timeout and the chain order are all visible and editable on /chatbot/pages (a small "AI" control per page plus a site-default control); nothing hidden in env except URLs and keys. |
| Default chain for a Local page | Local → OpenAI. |
| Timeouts | Local 8 s per request (editable). Connection refused falls back at once. OpenAI keeps today's deadline. The chain must fit inside the overall deadline; if Local used its time, OpenAI gets the rest. |
| Local capacity | Concurrency cap 4 and a circuit breaker (3 failures in a row, skip 30 s), both editable site-wide. |
| Unusable answer | Non-2xx, no choices, empty after trim, or only a think block. Quality, language and length are not judged. A reply cut at max_tokens is usable. |
| Record | New columns on chatbot_ai_requests (provider, model_used, fell_back, fallback_reason, attempts detail as small JSON without text, latency_ms, prompt_tokens, completion_tokens, path sync/async). No new table. Keep these rows 90 days, but null the customer text columns (messages, answer) after 2 days as today. Sync and Test-tab calls write a row too. |
| Local endpoint | Config `services.chatbot_ai.local.url` (env CHATBOT_AI_LOCAL_URL, e.g. http://127.0.0.1:11435/v1), optional key CHATBOT_AI_LOCAL_KEY. Reached through a reverse SSH tunnel bound to the server's localhost; nothing public. |
| Error texts | Generic texts name the provider that failed ("Local did not answer in time", "OpenAI did not answer"), never a body. |

## Threat model and tier
Untrusted: customer messages (they reach the AI and come back as text), anything a provider returns (status, body, usage numbers), and form posts from staff with chatbot access (provider names, model names, URLs are not posted by staff: the URL is config only). Trusted: config, env, this repo, the owner's settings once validated. Risk tier: high (it is on the live reply path of every page, and it handles keys). Majors need a one-line realistic scenario; fix loops stop after two.

## Rules
- Downloads: none except `composer install` from the existing lock if vendor is missing and the copy fails (no update, no require).
- No real network calls in tests; no change to any page's behaviour while no setting exists.
- Code comments explain the reason; comments, commits, branch and PR never name people or decision ids (the spec number is fine).
- Stage plan suggestion: (1) provider layer + chain in sync path with tests, (2) async pool + finish/sweep/resume, (3) record columns + migration + retention, (4) settings UI + endpoints + Changes, (5) comparison view, (6) full chatbot suite and review.

## Done when
- [ ] Every case S-150A to S-150F (except owner checks) has a test named with its ID, each watched red before its code; the result lists the red line per slice.
- [ ] The whole tests/Feature/Chatblast suite and the AI pool tests pass, adding no new failures (state the count on the base before you start).
- [ ] Migration up and down run clean on the test database; the AI step works before the migration runs (D.12).
- [ ] `git grep -n -i -P "sk-[a-z0-9]{10,}"` on the branch finds nothing new; no key in fixtures.
- [ ] `qa/stories.md` has S-150 with "Last run" filled.
- [ ] `docs/specs/150-ai-provider.result.md` filled: case table with evidence, rulings, merge danger (how to switch everything back to OpenAI: clear the overrides, or unset CHATBOT_AI_LOCAL_URL), how to set Tech Flask to Local after release, and the env lines the server needs.

## Out of scope
Hosted provider UI, Ollama or tunnel setup on any machine, changing prompts or flows, the order encoder and AI Checker, deploying.
