# Spec 224: show the Meta app a reply really went through ("via Incepxion")

**Project:** Likha. **Shape:** change · **Weight:** bounded · **Risk tier:** medium (labels; one nullable column on a busy table)

> Committed with the work as `docs/specs/224-show-the-real-route.md`. Names no people and no decision ids.

## Why

Since spec 223 a page on the Forbeast gateway can reply through another address (`chatbot.gateway_app`, e.g. `incepxion` → incepxionbot.com, the Incepxion V2 Meta app). Everything that says which bot carried a reply still says "Forbeast": the flow variable `{{replies_via}}` (used in the exit message "Bot used: {{replies_via}}"), the Inbox ("Bot · via Forbeast"), and the Pages screen. The owner wants these to show the real one: "Incepxion" when the reply went through the incepxion address.

## Stories

**S1 (P1) The flow variable.** `{{replies_via}}` gives the page's address label when the page is on the Forbeast gateway and its address is not the default (e.g. "Incepxion"); otherwise as today ("Forbeast", "Botcake", the incepxionbot gateway's label). Update the variable's description in `ChatbotFlowController::RUN_VARS` help.
- Cases: page on forbeast with no address → "Forbeast". Page on forbeast with `gateway_app=incepxion` → "Incepxion". Page on Botcake → "Botcake".

**S2 (P1) Each reply records its address.** When a gateway send is stored in `chatbot_replies`, record the address it went through (the `route` the gateway client already returns). Add a nullable column (e.g. `route`, varchar(30)) **only if** the migration is an instant metadata change on MySQL 8 (`ALGORITHM=INSTANT`; state it in the migration and fail loudly rather than copy the table). Old rows stay null = default. Every existing query on `via` keeps working (do not change `via` values).

**S3 (P1) The Inbox and other "via" labels.** The Inbox shows "via Incepxion" for a reply whose recorded address is incepxion, "via Forbeast" for the default, unchanged for Botcake. Any other screen that labels a reply's bot from `via` does the same.

## Constraints

- Base: Likha live `78d48eed`. Branch `feat/224-show-the-real-route`.
- With no `GATEWAY_URLS`, every label is exactly today's (tests prove it). Jobs queued by `78d48eed` still run (QueuedJobCompatTest).
- Commit messages and comments name no people, sessions or decision ids; no attribution lines. Don't push or deploy.

## Done when

The suite adds no new failures vs `78d48eed`; S1–S3 have tests; the migration (if any) is verified instant on MySQL 8 syntax and has a down; `docs/specs/224-show-the-real-route.md` and `.result.md` are committed.

Budget: small job; at most 2 attempts per step.
