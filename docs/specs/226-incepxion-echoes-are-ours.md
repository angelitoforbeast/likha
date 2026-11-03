# Spec 226: the Incepxion app's echoes are the bot's own replies (no "Other app" double in the Inbox)

**Project:** Likha. **Shape:** bug · **Weight:** bounded · **Risk tier:** low (Inbox display and echo labels only)

> Committed with the work as `docs/specs/226-incepxion-echoes-are-ours.md`. Names no people and no decision ids.

## Why (reproduced)

Since spec 223 a page can reply through the gateway's Incepxion address (Meta app Incepxion V2, app id `2067128400607287`). Meta sends an echo of every message the page sends, carrying the sending app's `app_id`. `ChatEchoes::sender()` knows `1115162827653315` (Forbeast) and others, not `2067128400607287`, so the echo is `other`. `Inbox::echoes()` only folds an echo into the bot's own reply when the echo's sender equals the reply's `via` (`forbeast`), so the same reply shows twice: once as "Bot · via Forbeast/Incepxion" and once as a grey "Other app" bubble (seen on Tech Flask Ph, 2026-11-04 00:2x).

## Stories

**S1 (P1)** The app ids of the Forbeast gateway's addresses are config, e.g. `GATEWAY_APP_IDS="incepxion=2067128400607287"` (same key style as `GATEWAY_URLS`). An echo whose `app_id` is one of them is the Forbeast gateway's own (`sender` = `forbeast`), so it folds into the matching reply exactly like a Forbeast-app echo; its label names the address ("Bot (Incepxion)").
- Cases: echo with app id 1115162827653315 → unchanged. Echo with 2067128400607287 and GATEWAY_APP_IDS set → folded into the reply, no "Other app" bubble. Same app id with GATEWAY_APP_IDS unset → today's behaviour. An unmatched echo from that app (e.g. typed in a tool) shows as "Bot (Incepxion)", not "Other app".

**S2 (P2)** Anything else that classifies echoes by sender (Bot Health "who answered", CBB double checks) counts these echoes as the bot's, not another app's. List each place in the result.

## Constraints

Base: Likha live `b8fc9d63`. Branch `fix/226-incepxion-echoes`. Another worker builds spec 225 (pages screen, CPP) on `feat/225-pages-and-cpp-per-ad-group`; don't touch those files beyond what this needs. Full suite: no new failures vs `b8fc9d63` (2 known: ExampleTest, ItemTableFitScriptTest). No migration. Commit messages and comments name no people, sessions or decision ids; no attribution lines. Don't push or deploy. Report the env line to add.

Budget: small; at most 2 attempts per step.
