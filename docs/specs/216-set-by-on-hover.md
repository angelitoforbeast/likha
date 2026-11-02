# Spec 216: who set a value and when, on hover; no AI usage sent for pages outside Forbeast

**Project:** Likha (likhaaitech.com) and Incepxion (incepxion.pro) — one codebase.
**Stack:** Laravel, Blade + Alpine, MySQL, queues on Redis/Horizon, PHPUnit.
**Shape:** change · **Weight:** bounded · **Risk tier:** medium (owner money screen, CEO-only data)

> Committed with the work as `docs/specs/216-set-by-on-hover.md`. Names no people and no decision ids:
> "the owner" decided, "the reviewer" checks and merges.

---

## Why

**A. /owner/private.** The Item Value (COGS) cell's hover says only "cogs", or "from <date>" and the comment
(`owTip()` in `resources/views/owner/private.blade.php`, and the inline lines near its `item_value_source`
templates). It never says who set the value or when, although `page_item_settings_log` records both (user_email,
created_at, scope `cogs` / `cogs_ceo`, source `owner_private` / `item_cogs` / `cogs_sync`; the Edit Audit Logs
screen reads it). The RTS cell says "Set by" (`rtsSetByMap()` in `OwnerPrivateController`) but not when. The
owner wants both on hover in the full view, not only when previewing as Marketing.

**B. AI usage.** Since spec 210, `ChatbotAiUsage::gatewayOf()` returns the page's gateway whenever the site is
linked to Forbeast, so AI replies on pages that are not served through Forbeast (Botcake pages, e.g. all of
Incepxion's AI pages today) are sent to Forbeast and answered `page_not_found` every time. Harmless, but a
wasted call per reply. Only pages whose messages actually go through a Forbeast gateway should be sent.

## Stories

Add to `qa/stories.md` under `## Slice 216 – Set by on hover; usage only for Forbeast pages`. Keep these case IDs.

### S-216.1 The owner sees who set COGS and RTS, and when (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-216.1.1 | happy | Given a manual Item Value set on /owner/private by user U at T, when the CEO hovers the Item Value cell (full CEO view), then the hover shows the value's source line as today plus "Set by U · T" (Manila time, e.g. "Nov 2, 2026 3:05 PM") | | Not run |
| S-216.1.2 | happy | Given the Item Value comes from the item's COGS (/item/cogs), then the hover shows "cogs" plus who set that COGS and when; from cogs sync it says "system (cogs sync)" and when | | Not run |
| S-216.1.3 | happy | Given the RTS cell, then "Set by U" also shows when ("Set by U · T") | | Not run |
| S-216.1.4 | happy | Given both compact and normal table modes, and the CEO and Marketing views, then the who/when shows on hover in each (compact: the existing cell hover; normal: a hover on the Item Value and RTS cells) | | Not run |
| S-216.1.5 | happy | Given a CEO value (scope `cogs_ceo`), then in the CEO view the hover adds "🔒 CEO value set by U · T" | | Not run |
| S-216.1.6 | negative | Given the Marketing view or a non-CEO user, then nothing about the CEO value (its setter, time or amount) appears in the hover, the page source or the JSON | | Not run |
| S-216.1.7 | edge | Given a value set before logging existed (no log line), then the hover shows what it shows today and no "Set by" line, without error | | Not run |
| S-216.1.8 | edge | Given several log lines for the same page, item and effective date, then the hover names the one that set the value shown (latest by id); aliased item names resolve as `rtsSetByMap` does | | Not run |
| S-216.1.9 | edge | Given a user who was renamed or removed, then the hover shows the name from employee profile or user, else the email, without error | | Not run |
| S-216.1.10 | edge | Given a 30-day range with all pages, then the who/when lookup adds at most two queries per data load (no per-row query), and the data endpoint stays within 10% of its current time on the test fixture | | Not run |

### S-216.2 AI usage is sent only for pages served through Forbeast (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-216.2.1 | happy | Given a page whose replies go through a Forbeast gateway (the same check the send path uses to choose gateway vs Botcake), then its AI rows get `usage_status` pending and are sent as today | | Not run |
| S-216.2.2 | negative | Given a Botcake page (not served through a gateway), then its AI rows keep cost_micros but get no usage_status (or `no_gateway`) and nothing is sent to Forbeast | | Not run |
| S-216.2.3 | negative | Given a Botcake page, then the AI-load check (`ChatbotAiLoad`) is not asked for it and its AI never stops for load | | Not run |
| S-216.2.4 | edge | Given a page moved from Botcake to the gateway (or back), then rows after the move follow the new path; older rows are unchanged | | Not run |

**Tests:** one failing test per case first, named with its case ID. Forbeast is faked with `Http::fake`.

## Constraints

| Topic | Decision |
|---|---|
| Who sees what | The CEO value's setter and time only in the CEO view (same rule as the existing CEO column). Everything else shows in both views. |
| Source of who/when | `page_item_settings_log` (and its user/employee-profile join, as the Edit Audit Logs screen does). No new table, no new column. |
| Time | Manila time, short date and time. |
| Usage | Pages not served through a Forbeast gateway are not sent and not load-checked; their cost is still recorded. |
| Base | `vps/main` at c6c704e1 (the live Likha; Incepxion runs the same tree). Branch `feat/216-set-by-on-hover`. |

**Threat model.** Untrusted: query parameters (`view_as`, dates). Trusted: the database, config. Medium: leaking CEO-only values to non-CEO users through the hover data.

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session. Talk only to the reviewer. Plan first, then build.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines. Conventional subjects ending "(spec 216)".
- Code comments state the reason itself, in the language and style of the surrounding code; no people or decision ids.
- No commands that start with an environment variable. Downloads: the project's own installs only. Never open `.env`.
- Another branch (spec 215, sequence sends) waits for release; stay out of the send path files it touches unless needed for S-216.2, and say so in a ruling if you touch one.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report.
- Size: small to medium job.

## Done when

- [ ] Cases S-216.1.1 – S-216.2.4 pass, each with a test named after it.
- [ ] The result has a red line for every feat/fix slice, watched before the code.
- [ ] The full suite adds no new failures (the known baseline failures: BigBroadcastTest S_167_1, ExampleTest, ItemTableFitScriptTest, AstraAddressRulesTest S_26_18).
- [ ] `qa/stories.md` has these stories with "Last run" filled.
- [ ] `docs/specs/216-set-by-on-hover.result.md` is filled in, with a screenshot or the rendered hover text of S-216.1.1, .2 and .5 from a local run.

## Out of scope

A usage or load screen for Botcake pages; changing the Edit Audit Logs screen; Forbeast; any deploy.

## Report back

Fill in `docs/specs/216-set-by-on-hover.result.md` and commit it with the work: every ruling
(`Ruling: <decision> — <why> — <cost if wrong>`) and deferred minors, then message the reviewer that it's ready,
with **Merge danger** (door, blast radius, revert).
