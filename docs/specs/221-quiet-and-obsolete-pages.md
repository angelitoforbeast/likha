# Spec 221: urgent only for pages that sell, and an "obsolete" mark that cannot hide a live page

**Project:** Likha (likhaaitech.com) and Incepxion (incepxion.pro) — one codebase.
**Stack:** Laravel, Blade, MySQL, PHPUnit.
**Shape:** change · **Weight:** bounded · **Risk tier:** medium (what staff are warned about)

> Committed with the work as `docs/specs/221-quiet-and-obsolete-pages.md`. Names no people and no decision ids:
> "the owner" decided, "the reviewer" checks and merges.

---

## Why

The problems banner at the top of every Chatbot and Chatblast screen (`App\Services\Chatbot\ProblemsPanel`, rendered in `resources/views/layouts/chatblast.blade.php`) showed "34 pages with problems (12 urgent)". Ten of the urgent ones were the Botcake setup check (`ProblemsPanel::setup()`, `ChatblastSetupCheck::pages()`): no flow "UPDATE SHIPPING STATUS 2026" and no custom field "message". A page counts as "sending" when it has the required Botcake settings and is in an active chatblast campaign; both active campaigns ("In transit", "Delivering") have no page list, so every such page counts. In fact 9 of those 10 pages had no order in 14 days and the tenth had 2 (last 2026-10-26); they got one status message in 3 days. They are old pages, and the urgent mark on them is noise.

The owner wants (1) urgent only where it matters, automatically, and (2) a way to mark a page obsolete so it leaves the banner, but (3) a page marked obsolete that turns out to be alive must come back, so nothing is missed.

## Stories

Add to `qa/stories.md` under `## Slice 221 – Quiet and obsolete pages`. Keep these case IDs.

### S-221.1 The setup warning is urgent only for a page that sells (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-221.1.1 | happy | Given a page with a setup X that is "sending" and has at least one order (`macro_output`, by its order date) in the last 14 days, then its setup line is orange (urgent) as today | | Not run |
| S-221.1.2 | happy | Given the same page with no order in the last 14 days, then its setup line is white and reads "Quiet (no order in 14 days), setup incomplete: <reason>", and it does not count as urgent | | Not run |
| S-221.1.3 | happy | Given a quiet page that gets an order, then within the banner's cache time (≤ 60 s) its setup line is orange again | | Not run |
| S-221.1.4 | edge | Given page names that differ only in case or spacing between the settings and the orders, then they are matched the way `PageSettings::pageKey` matches pages | | Not run |
| S-221.1.5 | edge | Given 80 pages, then the order counts come from one grouped query per banner build (no per-page query), within the existing cache | | Not run |

### S-221.2 A page can be marked obsolete (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-221.2.1 | happy | Given a page in the banner, when a user who may edit that page's settings clicks "Mark obsolete" on it, then one page_settings row records it (who, when) with history, and the page leaves the banner and its counts | | Not run |
| S-221.2.2 | happy | Given obsolete pages, then the banner shows a small "Obsolete pages (N)" link that lists them (name, marked by, when) with "Unmark" each; Unmark restores the page to the banner with history | | Not run |
| S-221.2.3 | negative | Given a user who cannot edit that page's settings, then neither control is shown and the endpoints refuse (403) | | Not run |
| S-221.2.4 | negative | Given an obsolete page, then nothing else changes: its bot, flows, chatblast sending, orders, Forbeast and every other screen behave exactly as before (the mark only hides it from the banner) | | Not run |

### S-221.3 An obsolete page that is alive comes back (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-221.3.1 | happy | Given a page marked obsolete at T, when it gets an order after T, then it is back in the banner as orange (urgent): "Marked obsolete on <date> by <name>, but has N orders since", with "Unmark", and its other problems show again | | Not run |
| S-221.3.2 | happy | Given a page marked obsolete at T, when a customer chat arrives after T (the chats the banner already reads for "no chat"), then the same comeback line shows, naming chats instead of orders | | Not run |
| S-221.3.3 | edge | Given the comeback line, when the user marks it obsolete again, then the new mark time applies and the line goes until the next order or chat | | Not run |

**Tests:** one failing test per case first, named with its case ID.

## Constraints

| Topic | Decision |
|---|---|
| Quiet rule | Orange only when sending and at least one order in the last 14 days; otherwise white. 14 days is a named constant. |
| Obsolete mark | One page_settings row per page (provider/key per the existing precedent; check `PageSettings` and the 174 `adgroup` rows), with history and restore like other values. No new table, no migration. |
| Scope of the mark | The banner only (`ProblemsPanel` and its counts). It never stops sending, the bot, chatblast or anything else. Hiding obsolete pages in the /chatbot/pages list is a later spec (another branch is changing that screen). |
| Comeback | Any order or customer chat after the mark brings the page back as urgent, as in S-221.3. |
| Files | Stay out of `resources/views/chatbot/index.blade.php`, `resources/views/chatbot/test.blade.php` and their JS (spec 217 is changing them). |
| Queued jobs | Do not change queued job classes; QueuedJobCompatTest must stay green. |
| Base | `vps/main` at 86db0060 (live Likha after specs 215, 218, 219; Incepxion runs the same tree). Branch `feat/221-quiet-and-obsolete-pages`. |

**Threat model.** Untrusted: request ids and page names. Trusted: database, config. Low-medium: authorization of the mark (same check as page settings), and not hiding a live page (S-221.3).

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session. Talk only to the reviewer. Plan first, then build.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines. Conventional subjects ending "(spec 221)".
- Code comments state the reason itself, in the language and style of the surrounding code; no people or decision ids.
- No commands that start with an environment variable. Downloads: the project's own installs only. Never open `.env`.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report.
- Size: small job.

## Done when

- [ ] Cases S-221.1.1 – S-221.3.3 pass, each with a test named after it.
- [ ] The result has a red line for every feat/fix slice, watched before the code.
- [ ] The full suite adds no new failures (known baseline: BigBroadcastTest S_167_1, ExampleTest, ItemTableFitScriptTest, AstraAddressRulesTest S_26_18; S_26_23 is timing-sensitive).
- [ ] `qa/stories.md` has these stories with "Last run" filled.
- [ ] `docs/specs/221-quiet-and-obsolete-pages.result.md` is filled in, with Deploy notes.

## Out of scope

Hiding pages in /chatbot/pages; changing chatblast campaigns or sending; the Botcake setup itself; Forbeast; any deploy.

## Report back

Fill in the result and commit it with the work: every ruling (`Ruling: <decision> — <why> — <cost if wrong>`) and deferred minors, then message the reviewer that it's ready, with **Merge danger** (door, blast radius, revert).
