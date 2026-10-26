# Brief 161: the chatbot lanes scale for 50–100 chats per second

## Why
Forbeast is being tuned for 50–100 incoming chats/s (its spec 018). Every chat reaches Likha at `/api/gateway/events` → `HandleGatewayEvent` (queue `chatbot-high`) → `RunChatbotFlow` (`chatbot-high`) → AI step via the AI pool (`chatbot:ai-pool`, `services.chatbot.ai_concurrency`, default 50) → sends. `config/horizon.php` supervisor `chatbot` serves `['chatbot-high','chatbot']`, balance auto, minProcesses 2 (per queue), maxProcesses `HORIZON_CHATBOT_MAX` default 16. Server: 4 vCPU. A 2026-10-27 stress run at 5/s was fine; 20–50/s backed up in Forbeast before reaching Likha, so Likha's limits are next.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | `chatbot` supervisor: min 4 per queue, max default 40 (env `HORIZON_CHATBOT_MAX` still overrides), `balanceMaxShift` ~5 and `balanceCooldown` 1 so it grows from min to max within ~30 s; a `memory` limit per worker; keep chatblast and others unchanged. | Burst handling. |
| 2 | AI pool concurrency default 100 (env `CHATBOT_AI_CONCURRENCY` overrides); confirm the pool's 429 backoff still halves concurrency on rate limits (existing behaviour) — add a test if missing. | ~50 OpenAI calls/s at 1–2 s each. |
| 3 | Check the per-chat path for anything that serializes under load (global locks, a single-row counter updated per chat, cache stampede on botfields/flow load, N+1 on page_settings). Fix only what's clearly a bottleneck; list the rest in the result with numbers from a local benchmark if you can make one (e.g. 1,000 synthetic gateway events through the job with a faked AI). | No hidden choke point. |
| 4 | No behaviour change for customers; same order per customer (the per-customer lock stays). | Correctness. |

## Cases (S-161)
P1: .1 horizon config asserts the new chatbot min/max/shift/cooldown/memory and env override; .2 AI pool default concurrency 100 and 429 halving; .3 any bottleneck fix has a test; .4 existing Chatblast/Botcake suites pass. P2: .5 the local benchmark numbers in the result.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode tests/Feature/MetaAds` adds no new failures vs c42a2cf9. Result with deploy notes (`horizon:terminate`, env); end with "161: <done|blocked> — <one line>".

## Out of scope
Forbeast; server size; deploying.
