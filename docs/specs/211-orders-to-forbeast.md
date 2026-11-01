# Spec 211: encoded orders also go to Forbeast

**Project:** Likha (likhaaitech.com) and Incepxion (incepxion.pro) — one codebase; chatbots on pages served by the Forbeast gateway (`chatbot.gateway`: forbeast or incepxionbot).
**Stack:** Laravel, MySQL, queues on Redis/Horizon, PHPUnit/Pest.
**Shape:** build · **Weight:** bounded · **Risk tier:** medium (partner API, customer data)

> Committed with the work as `docs/specs/211-orders-to-forbeast.md`. Names no people and no decision ids:
> "the owner" decided, "the reviewer" checks and merges.

---

## Why

When the chatbot encodes an order and uploads it to the macro, the order lives only in Likha. The owner wants
the same order to go to Forbeast at the same time, so the page's client sees it in Forbeast's Orders screen.
Forbeast's side is built (Forbeast spec 042: `PUT /api/v1/site/orders/{ref}`, upsert by ref and version).

What exists (read it on this base; confirm before building): the flow nodes `upload_macro`
(`OrderEncode\Upload::run`, insert or update into `macro_output`, an `order_encodes` row with status
`uploaded`) and `encode_order` (an `order_encodes` row, the `EncodeOrder` job, `LiveOrder` writes
`macro_output`); the A..H fields date, page, name, phone, input, shop, cxd, price (+ status). `Gateways` /
`ForbeastSite` hold each gateway's URL and site key. Nothing goes to Forbeast today.

## Stories

Add to `qa/stories.md` under `## Slice 211 – Orders to Forbeast`. Keep these case IDs.

### S-211.1 An encoded order reaches Forbeast once and follows its updates (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-211.1.1 | happy | Given an `upload_macro` insert on a page whose `chatbot.gateway` is set up, when the row is written to `macro_output`, then a queued job PUTs the order to that gateway's `/api/v1/site/orders/{ref}` with the A..H values mapped per the contract and `fields` holding the raw values | | Not run |
| S-211.1.2 | happy | Given an `encode_order` run, then the order is sent when `LiveOrder` writes `macro_output` (not when queued), so a failed encode is never sent | | Not run |
| S-211.1.3 | happy | Given an `upload_macro` update of the same `macro_output` row, then the same `ref` is sent with a higher `version`, so Forbeast updates one order | | Not run |
| S-211.1.4 | happy | Given pages on the incepxionbot gateway, then their orders go to the incepxionbot gateway | | Not run |
| S-211.1.5 | negative | Given a test run (Test mode), then nothing is sent | | Not run |
| S-211.1.6 | negative | Given a page with no gateway set up, then nothing is sent and nothing fails | | Not run |
| S-211.1.7 | negative | Given `page_not_found` or a 422 from Forbeast, then the send is marked failed with the reason, not retried forever, and the encode and the sheet are unaffected | | Not run |
| S-211.1.8 | edge | Given the same send retried (queue redelivery, lost reply), then Forbeast answers `duplicate` and it is marked sent | | Not run |
| S-211.1.9 | edge | Given Forbeast down, timing out, 5xx or 429, then the send retries with backoff and lands later; encoding never waits for it | | Not run |
| S-211.1.10 | edge | Given 401 (site key changed), then sending pauses and the failure is visible; nothing is dropped | | Not run |
| S-211.1.11 | edge | Given times, then `ordered_at` carries the Manila offset (+08:00) | | Not run |
| S-211.1.12 | edge | Given Filipino text and emoji in name, shop or input, then they are sent unchanged | | Not run |
| S-211.1.13 | happy | Given sends that failed for good, then the owner sees them in a short list (order, page, reason, time) with "send again" | | Not run |
| S-211.1.14 | edge | Given orders from before go-live, then none are sent (no backfill) | | Not run |

**Tests:** one failing test per case first, named with its case ID. Forbeast is faked with `Http::fake`; no
real network in tests.

## Constraints

**Decisions already made** (settled with the owner; don't reopen them unless something is actually broken):

| Topic | Decision |
|---|---|
| When | At the same time as the macro write, through a queued job (the encode never waits). |
| Identity | `ref` is stable per order row: the site key plus the `macro_output` id (e.g. `likha-mo-<id>`; the site name comes from config, nothing hard-coded); `version` grows with every send of that row (e.g. the write time in milliseconds). |
| Mapping | Per the contract: input → details, shop → item; send name, phone, price, cxd, status; `fields` keeps the raw A..H. |
| Ownership of edits | Forbeast never lets the partner overwrite its own status or note (its side); Likha just sends. |
| No backfill | Only orders written after go-live. |
| Storage | No new table unless the plan proves it is needed: keep the send state on the `order_encodes` row (or a column there). |
| Base | `vps2/main`. Branch `feat/211-orders-to-forbeast`. |

**Forbeast contract** (Forbeast spec 042; examples in C:\mira\mira\projects\forbeast\.claude\worktrees\ai-load-042\docs\ai-load.md — read it for the exact field rules):
- Auth `Authorization: Bearer <site key>`; errors `{error, message}`.
- `PUT /api/v1/site/orders/{ref}` body `{page_id, version, ordered_at, name, phone, details, item, shop, cxd, price, status, fields}` (`page_id` = Meta page id; `ordered_at` ISO 8601 with offset) → `{order_id, duplicate?, stale?}`; 404 `page_not_found`; 422 per field.

**Threat model.** Untrusted: Forbeast responses, customer-entered order fields. Trusted: config, env, this repo.

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session. Talk only to the reviewer. Plan first, then build.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines.
- Code comments explain the reason itself, in the language and style of the surrounding code; no people or decision ids anywhere.
- No commands that start with an environment variable. Downloads: none beyond the project's own installs. Never open `.env`.
- Another worker is building spec 210 (AI key pool) in parallel on its own branch. Do not touch `app/Services/Chatbot/ChatbotAi*`, `ForbeastWatch` or the AI parts of `FlowEngine`; hook the send in `OrderEncode` (Upload / LiveOrder) or a listener, so the two branches merge cleanly. Read the Forbeast site config through `Gateways` / `ForbeastSite` as they are.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report.
- Size: medium job.

## Done when

- [ ] Cases S-211.1.1 – S-211.1.14 pass, each with a test named after it.
- [ ] The result has a red line for every feat/fix slice, watched before the code.
- [ ] The full suite adds no new failures; migrations (if any) run and roll back.
- [ ] `qa/stories.md` has these stories with "Last run" filled.
- [ ] `docs/specs/211-orders-to-forbeast.result.md` is filled in.

## Out of scope

The AI key pool and billing (spec 210); backfill; changing Forbeast; any deploy.

## Report back

Fill in `docs/specs/211-orders-to-forbeast.result.md` and commit it with the work — every ruling
(`Ruling: <decision> — <why> — <cost if wrong>`) and the deferred minors — then message the reviewer that it's
ready, with **Merge danger** (door, blast radius, revert).
