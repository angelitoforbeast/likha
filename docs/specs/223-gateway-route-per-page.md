# Spec 223: choose the Forbeast gateway address per page (route), and log why a send got no answer

**Project:** Likha (Laravel; the same tree is also deployed as the Incepxion site).
**Shape:** change · **Weight:** bounded-to-medium · **Risk tier:** high (every gateway send)

> Committed with the work as `docs/specs/223-gateway-route-per-page.md`. Names no people and no decision ids:
> "the owner" decided, "the reviewer" checks and merges.

---

## Why

The Forbeast gateway now serves more than one Meta app. The same API (same paths, same API key, same per-page token) answers on two addresses, and the Meta app used for a send is the one of the address called:

- `https://app.forbeast.app` → the Forbeast Meta app (today's `GATEWAY_URL`);
- `https://incepxionbot.com` → the Incepxion V2 Meta app.

A page connected through both apps can reply through either. If the page has no token for the called address's app, the gateway answers **409 `app_not_connected`**. The owner wants to choose, per page, which address a page's replies go through ("route"), to test the Incepxion app on a few pages while the rest stay on Forbeast. Automatic switching between Forbeast and Incepxion is a later spec; the existing Botcake fallback stays as it is.

Separately: since the gateway moved, a few sends per hour end as "Gateway did not answer (HTTP 0)" (a `ConnectionException`) while the gateway's own logs show nothing failing. The cURL error is not recorded, so the cause is unknown.

## Stories

**S1 (P1) Gateway addresses are config.** `services.gateway` keeps `url` (the default, today's `GATEWAY_URL`) and gains a list of named addresses, e.g. `GATEWAY_URLS="forbeast=https://app.forbeast.app/api incepxion=https://incepxionbot.com/api"` (your plan picks the format; keep the path prefix the same way `url` carries one today). The default key is `forbeast` = `url`. With no list set, behaviour is exactly today's.

**S2 (P1) A page's route.** A page setting `chatbot.gateway_app` (a row in the existing page settings, like `chatbot.provider`; no new table) holds the address key; missing or unknown = the default. Every gateway call made **for that page** uses its address: sends, typing, seen, comment reply and private reply, the page info read, and anything else under `/v1/pages/{id}`. Calls that are not for a page (site orders, AI usage/load, health) keep the default address.
- Cases: page without the setting → default URL. Page set to `incepxion` → incepxionbot.com URL for its sends and typing. Unknown key → default, with a warning logged once. Changing the setting takes effect on the next call.

**S3 (P1) 409 `app_not_connected`.** That answer is a clear failure reason on the send ("This page is not connected to the <key> Meta app on the gateway"), recorded like other refusals. It does not trip the Forbeast watcher's "no answer" condition (it is an answer) and is never retried against another address.

**S4 (P1) Choose it on the page.** Where the page's chatbot settings are edited (where `chatbot.provider` is chosen), an admin picks the gateway address from the configured keys, shown by name. Saving records who/when as page settings already do. Shown only when more than one address is configured.

**S5 (P1) The Forbeast watcher and the move back.** The watcher keeps watching per page; when it moves a page to Botcake and later back, the page returns to the gateway with its `chatbot.gateway_app` unchanged.

**S6 (P1) Why a send got no answer.** When the gateway call throws a `ConnectionException` (HTTP 0), record the cURL error number and message (e.g. `cURL error 28: Operation timed out after 10001 ms`, `cURL error 6: Could not resolve host`) and the connect/total time, in the send's failure detail and the log, never the token or the message text. Keep today's "Gateway did not answer" wording as the first part. Add a config switch `GATEWAY_FORCE_IPV4` (default off) that, when on, makes the gateway client use IPv4 only (`CURLOPT_IPRESOLVE`), so the owner can test whether the drops are IPv6-only.

## Decisions already made

| Topic | Decision |
|---|---|
| Who picks the Meta app | The client (Likha / the Incepxion site) by the address it calls; the gateway only uses that address's app. |
| Storage | A page setting row (`chatbot` / `gateway_app`), not a column or table. |
| Failover order (later spec) | Forbeast, then Incepxion, then Botcake last. Not in this spec. |

## Constraints

- Base: Likha live commit `ece5a6a3` (main on the server). Branch `feat/223-gateway-route-per-page`.
- Backward compatible: with no `GATEWAY_URLS`, every request goes exactly where it goes today (tests prove it). Jobs queued by `ece5a6a3` still run (QueuedJobCompatTest pattern; add cases for any changed job payload).
- No secrets in logs; the API key and page tokens never logged.
- Tests for every case; the full suite adds no new failures.
- Commit messages and comments name no people, sessions or decision ids; no Co-Authored-By, Claude-Session or "Generated with" lines.
- Don't push, deploy, or change any server or env. Commit on the work branch.

## Done when

- The suite passes (no new failures vs `ece5a6a3`); lint clean on changed files.
- S1–S6 cases have tests; a test shows a page set to `incepxion` sends to the incepxion URL and a page without the setting to the default URL; a 409 `app_not_connected` is recorded as an answer, not "did not answer".
- `docs/specs/223-gateway-route-per-page.md` (this text, first commit) and `.result.md` (filled, with the exact env lines to add for Likha and the Incepxion site) are committed.

## Out of scope

Automatic Forbeast→Incepxion failover; any change to Botcake; any server or env change.

Budget: medium job; at most 2 attempts on any one step, then stop and report.
