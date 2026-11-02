# Spec 217: a fast pages screen, and ad groups edited like a page

**Project:** Likha (likhaaitech.com) and Incepxion (incepxion.pro) — one codebase.
**Stack:** Laravel, Blade + vanilla JS / Alpine, MySQL, Redis/Horizon, PHPUnit.
**Shape:** change · **Weight:** bounded-to-medium · **Risk tier:** medium (the screen every staff member uses to set the bot's replies)

> Committed with the work as `docs/specs/217-pages-speed-and-ad-group-edit.md`. Names no people and no decision ids:
> "the owner" decided, "the reviewer" checks and merges.

---

## Why

**A. Speed.** /chatbot/pages (`resources/views/chatbot/index.blade.php`, ChatbotController and the JS it loads) is so slow that the browser tab stops answering: 71 pages, several Ad-based pages with expanded ad groups (one page has 223 ads not in a group). The owner uses this screen all day; it is the first thing to fix.

**B. Ad groups (specs 172, 174, 181, 192, 212).** An ad group can already set every page value, and its cells have ✎ in the list. But the page row has an **Edit** window with a paste box (a row copied from Google Sheets; the AI maps each pasted value to its field through `/chatbot/paste-map` and `PasteFieldAi`, preview, Apply, Save) and a group has none. The owner wants the same Edit for every ad group, the groups with running ads first, a clearer "Manage in Edit", and to test a reply as a customer who came from a given ad.

## Stories

Add to `qa/stories.md` under `## Slice 217 – Pages speed and ad group edit`. Keep these case IDs.

### S-217.1 The pages screen is fast (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-217.1.1 | happy | Given a seeded set like live (80 pages, 30 values each, 3 Ad-based pages with 6 groups and 250 ads each), when /chatbot/pages loads, then the server answers in under 1 s and the page is usable (rows visible, scrolling smooth, no long task over 200 ms after load) in under 2 s on a normal PC; record before/after numbers (server ms, HTML/JSON KB, DOM nodes, longest main-thread task) | | Not run |
| S-217.1.2 | happy | Given an Ad-based page, then its groups and ad lists are built only when expanded (lazy), and collapsing frees them | | Not run |
| S-217.1.3 | negative | Given the speed work, then every existing feature of the screen still works: inline ✎ edit, multi-cell paste, Edit window, History/Restore, Check column, filters, Replies switch, ad groups expand (S-172/174/181/192/212 suites stay green) | | Not run |
| S-217.1.4 | edge | Given a 390 px phone, then the screen loads and scrolls without freezing | | Not run |

### S-217.2 Every ad group has an Edit like the page (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-217.2.1 | happy | Given an Ad-based page, then each group row (in the list and in Manage in Edit) has an **Edit** button that opens the same Edit window as the page, titled with the page and the group name, showing every group value field (the same fields the page Edit shows; empty = "uses default" with the page value shown muted) | | Not run |
| S-217.2.2 | happy | Given that window, when a row copied from Google Sheets is pasted, then the AI maps each value to its field exactly as for the page (same endpoint and rules), the preview is shown, Apply fills the fields, and Save writes the group values with history and Restore | | Not run |
| S-217.2.3 | negative | Given identity or switch keys (token, provider, On/Off, Botcake ID, the adgroup mode), then the group Edit neither shows nor saves them (192's exclusion list) | | Not run |
| S-217.2.4 | negative | Given a user who cannot edit the page's values, then the group Edit is not shown and its saves are refused server-side (403) | | Not run |
| S-217.2.5 | edge | Given a paste with blanks and "Paste blanks as empty" off/on, then the group behaves exactly like the page | | Not run |

### S-217.3 Running ads first (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-217.3.1 | happy | Given an Ad-based page expanded, then groups with at least one On ad come first, then by customers in 30 days, then name; inside a group, On ads first, then New, then by customers | | Not run |
| S-217.3.2 | happy | Given "Ads not in a group yet" in Manage in Edit, then On and New ads come first, then by customers | | Not run |
| S-217.3.3 | edge | Given a group just created or edited by hand that has no On ad, then it stays visible (212's rule) and sorts after the running groups | | Not run |

### S-217.4 Test as a customer from an ad (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-217.4.1 | happy | Given /chatbot/test with an Ad-based page chosen, then an **Ad** dropdown lists the page's ads (grouped ads by group, then ads not in a group), each with ad name, status and id, On ads first; choosing one runs the test as a customer whose latest ad is that id | | Not run |
| S-217.4.2 | happy | Given an ad in a group (by ad, ad set or campaign id, 212's matching), then the test uses that group's values and flow, and the log line says "Ad <id> → group <name>"; an ad in no group says "→ Default" and uses the page values | | Not run |
| S-217.4.3 | negative | Given a Page-based page, then the Ad dropdown is hidden and tests run as today | | Not run |
| S-217.4.4 | edge | Given the existing "Ad group" picker, then it keeps working; when both are set, the Ad wins and the log says so | | Not run |

### S-217.5 Clear way to manage groups (P2)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-217.5.1 | happy | Given an Ad-based page expanded, then "Manage in Edit" is a normal-size primary button next to the "N ads not in a group yet" count, and opens the Edit window at the Ad groups section | | Not run |
| S-217.5.2 | happy | Given the Default row, then it is labelled "Default (page values)" and shows the page's values exactly as the page row does | | Not run |

**Tests:** one failing test per case first (browser-only behaviour: a JS/DOM test or a recorded measurement in the result), named with its case ID.

## Constraints

| Topic | Decision |
|---|---|
| Order of work | Speed first (S-217.1), measured before any change. Then the group Edit, sort, test, button. |
| Group Edit | Reuse the page Edit window, the paste-map endpoint and its AI as they are; no second AI path and no prompt change. Group writes go through the existing group value endpoint (history, Restore, throttles). |
| Storage | No new table, no migration (page_settings rows as 172/174/192 decided). |
| Queued jobs | Do not change the properties of any queued job class (a deploy with new typed properties failed thousands of queued jobs); if unavoidable, give every new property a default and say so in Deploy notes. |
| Base | `vps/main` at f5d6d450 (live Likha after spec 215; Incepxion runs the same tree). Branch `feat/217-pages-speed-ad-group-edit`. |

**Threat model.** Untrusted: pasted text, query parameters, the AI's mapping (it only proposes; Save writes). Trusted: database, config. Medium: authorization of group edits (same check as page values, server-side).

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session. Talk only to the reviewer. Plan first, then build.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines. Conventional subjects ending "(spec 217)".
- Code comments state the reason itself, in the language and style of the surrounding code; no people or decision ids.
- No commands that start with an environment variable. Downloads: the project's own installs only. Never open `.env`. No real AI or Meta calls in tests (fake them).

## Budget

- Attempts: at most 2 tries at the same step; then stop and report.
- Size: medium job.

## Done when

- [ ] Cases S-217.1.1 – S-217.5.2 pass, each with a test or a recorded measurement named after it; S-217.1.1 shows before/after numbers.
- [ ] The result has a red line for every feat/fix slice, watched before the code.
- [ ] The full suite adds no new failures (known baseline: BigBroadcastTest S_167_1, ExampleTest, ItemTableFitScriptTest, AstraAddressRulesTest S_26_18; AstraAddressRulesTest S_26_23 is timing-sensitive under the full run).
- [ ] `qa/stories.md` has these stories with "Last run" filled.
- [ ] `docs/specs/217-pages-speed-and-ad-group-edit.result.md` is filled in, with Deploy notes (expected: no migration; view/route cache clear).

## Out of scope

AI auto-assigning ads to groups (a later spec); comment reply rules; Forbeast; any deploy.

## Report back

Fill in the result and commit it with the work: every ruling (`Ruling: <decision> — <why> — <cost if wrong>`), story changes and deferred minors, then message the reviewer that it's ready, with **Merge danger** (door, blast radius, revert).
