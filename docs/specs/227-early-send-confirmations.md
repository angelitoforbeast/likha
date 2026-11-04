# Spec 227: a send confirmation that arrives before its reply row is kept and matched later

**Project:** Likha. **Shape:** bug · **Weight:** bounded · **Risk tier:** medium (send confirmations feed Bot Health, the slow-send alarm and the "ask again" of unsent wait questions)

> Committed with the work as `docs/specs/227-early-send-confirmations.md`. Names no people and no decision ids.

## Bug (reproduced 2026-11-03/04)

Since the Forbeast gateway moved to faster servers, Likha has many gateway replies with `confirmed_status IS NULL` although the gateway sent them in ~3 s and delivered the `send_status` event to Likha within 1 s (e.g. `client_ref likha-01M6GMG1Q2BNSXFAY2ANR4E5RW`: gateway `sent` 12:00:18 UTC, event delivered 12:00:19, Likha row still unconfirmed). From 17:00 to 22:00 Manila: ~670 unconfirmed, 640 of them sequence sends (`sequence_id` not null), against 72,565 confirmed. `ForbeastSendHealth::pendingCheck` then raises "Forbeast is slow to send replies … oldest pending 58625s" although nothing is waiting at the gateway (its `/v1/health/sends` shows 0 queued).

Likely cause (verify first, report it): the confirmation reaches `HandleGatewayEvent` → `SendStatus::receive()` before the sending path has written the `chatbot_replies` row (or its `client_ref`), so `match()` finds nothing and returns `send_status_unknown`; nothing retries.

## Stories

**S1 (P1) Investigate.** Confirm the order of writes in the sequence pool and flow send paths vs the event; count `send_status_unknown` outcomes (existing counters) for recent hours. If the cause is different, stop and report it before fixing.

**S2 (P1) Keep and retry.** A `send_status` with a `client_ref` that matches no reply yet is retried later (e.g. the job re-dispatched with a delay, up to a few times over ~2 minutes) and applied when the row appears. After the last try it is counted as unknown as today. A repeat still does nothing.

**S3 (P1) Heal the last 24 h.** A one-off artisan command (dry-run by default) fills `confirmed_status`/`confirmed_at` for unconfirmed gateway replies of the last 24 h by asking the gateway for each send's final state by `client_ref` (use the existing API if it has a lookup — check the gateway's `/v1/pages/{id}/messages` duplicate/lookup behaviour or another documented read; if none exists, say so and skip S3). Prints counts only.

**S4 (P2)** The slow-send alarm ignores unconfirmed replies whose gateway send is already final, if S3 shows that state is cheaply known; otherwise leave the alarm as is (S2 removes the cause).

## Constraints

Base: Likha live `b8fc9d63` (or the current live main when you start — check `git --git-dir` is not available locally; use b8fc9d63). Branch `fix/227-early-send-confirmations`. Other workers: spec 225 (pages screen, CPP) and spec 226 (echo labels in ChatEchoes/Inbox). Don't touch their files. Queued jobs from the live release must still run (QueuedJobCompatTest; a new job property needs a default). Full suite: no new failures (2 known). No migration unless unavoidable. Commit messages and comments name no people, sessions or decision ids; no attribution lines. Don't push, deploy or touch servers.

Budget: small-to-medium; at most 2 attempts per step.
