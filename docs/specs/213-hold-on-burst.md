# Spec 213: a short outage between the sites does not switch every page to Botcake

**Project:** Likha (likhaaitech.com) and Incepxion (incepxion.pro) — one codebase; chatbot pages send through the Forbeast gateway and switch to Botcake automatically when Forbeast fails (`ForbeastWatch`).
**Stack:** Laravel, MySQL, Redis cache, PHPUnit/Pest.
**Shape:** change · **Weight:** bounded · **Risk tier:** medium (live message routing)

> Committed with the work as `docs/specs/213-hold-on-burst.md`. Names no people and no decision ids.

---

## Why

On 2026-11-01 from 11:12 to 11:17 Manila the network path between Likha and Forbeast broke in both
directions (Likha's sends got no answer, Forbeast's posts to Likha failed; both servers were healthy and Meta
and Botcake traffic kept flowing). `ForbeastWatch::afterSend` switches a page after 3 "no answer" sends in a
row, judged per page with no cross-check, so 36 pages switched to Botcake within 4 minutes (11:13–11:16) and
stayed there until 11:52 (the 30-minute healthy rule), although the outage lasted 5 minutes.

The owner wants a short, many-page outage to be treated as a path problem: hold and re-check first, and
switch only if it lasts.

## Stories

Add to `qa/stories.md` under `## Slice 213 – Hold on a burst of no-answer`. Keep these case IDs.

| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-213.1 | happy | Given one page alone reaches 3 no-answers in a row while other pages of the same gateway send fine, then it switches as today | | Not run |
| S-213.2 | happy | Given no-answers on 5 or more pages of the same gateway within 60 seconds (a burst), when a page reaches its 3rd no-answer, then it does not switch yet: the gateway goes into a hold, one event row is written (result `held`, the gateway, how many pages, time), and pages keep sending through Forbeast | | Not run |
| S-213.3 | happy | Given a hold, when sends to that gateway succeed again (or a health probe `GET /v1/pages/{id}` of an affected page answers) before the hold ends, then the hold ends, the no-answer counters of the gateway's pages reset, nothing switched, and one event row says `recovered` with the duration | | Not run |
| S-213.4 | happy | Given a hold, when the gateway still gets no answer when the hold ends (default 2 minutes), then every page that reached the threshold switches to Botcake as today (same event rows, same switch-back rule) | | Not run |
| S-213.5 | negative | Given failed sends with an HTTP error status (4xx/5xx with an answer), then the existing `failed_sends` rule is unchanged (this spec only changes the no-answer path) | | Not run |
| S-213.6 | edge | Given a hold on the forbeast gateway, then pages on the incepxionbot gateway are judged on their own (holds are per gateway) | | Not run |
| S-213.7 | edge | Given the cache is cleared or a worker restarts during a hold, then the next no-answer either resumes the hold or starts a fresh one; it never switches every page at once without the hold window having passed | | Not run |
| S-213.8 | edge | Given the hold window, burst size and burst seconds, then they are config values (`services.gateway.auto_switch.*`, env), with defaults 2 minutes, 5 pages, 60 seconds, and setting the burst size to 0 turns the hold off (old behaviour) | | Not run |
| S-213.9 | edge | Given a hold, then the bot-switch feed (`/api/chatbot/provider-events` and the `chatbot.auto_switch` rows) shows the `held` and `recovered` events so the alarm that reads them can report them | | Not run |

**Tests:** one failing test per case first, named with its case ID, at `ForbeastWatch` / the send path; Forbeast
faked with `Http::fake`; time moved with the test clock, not sleeps.

## Constraints

| Topic | Decision |
|---|---|
| Scope | Only the no-answer path of `ForbeastWatch` (`afterSend`, its counters and `switch`). Health probes, failed-sends, inbound and lag rules stay as they are. |
| Storage | Cache keys like the existing counters, plus the existing event rows; no new table. |
| Base | `vps/main` (d05d1b63, the live Likha code). Branch `feat/213-hold-on-burst`. Specs 210 and 211 are on other branches; do not touch their files (`ChatbotAi*`, `OrderEncode`). |

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session. Talk only to the reviewer. Plan first, then build.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines; subjects end "(spec 213)".
- Code comments explain the reason itself, in the language and style of the surrounding code; no people or decision ids.
- No commands that start with an environment variable. Never open `.env`. No real Forbeast calls in tests. No push, no deploy.

## Budget

- Attempts: at most 2 tries at the same step. Size: small to medium job.

## Done when

- [ ] Cases S-213.1 – S-213.9 pass, each with a test named after it; the existing ForbeastWatch tests still pass.
- [ ] The result has a red line for every feat/fix slice, watched before the code.
- [ ] The full suite adds no new failures.
- [ ] `qa/stories.md` has these stories with "Last run" filled; `docs/specs/213-hold-on-burst.result.md` is filled in (Status, Summary, Checks, Red lines, Rulings, Deploy notes, Open questions).

## Out of scope

Finding the cause of the 11:12 outage; changing Forbeast; the switch-back rule; any deploy.

## Report back

Fill in the result and commit it, then message the reviewer that it's ready, with **Merge danger** (door,
blast radius, revert).
