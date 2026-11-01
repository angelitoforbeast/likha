# Result: spec 213 "Show hidden" on the read-only Ad groups panel shows only what that panel can reveal

**Status:** done
**Date:** 2026-11-01
**Branch / PR:** `feat/213-show-hidden-readonly`, cut from d05d1b63. Not pushed, no PR.
**Preview or run link:** n/a. Screenshots are in `docs/specs/213-evidence/`.

## Summary

- **What changed.** `hiddenBar(page, readOnly)` in `public/js/chatbot-adgroups.js` takes a read-only flag, and the read-only table rows (`subRows`) pass it.
- **Read-only view.**
  - The button counts and toggles only hidden groups and hidden ids in groups. That is all this view can show.
  - When that count is 0, there is no button.
  - Quiet ads not in a group are still named on the "Hidden:" line, with "see Manage in Edit".
- **Edit view.** Unchanged: its button counts everything, including the ads not in a group.
- **Shared state.** Both views share the same per-page `showHidden`.
- **Server.** No server or PHP change: the panel already had the three counts.
- **Small extra.** Each view's button now has its own `data-ag-hidden-toggle` value (`table:<page>` or `edit:<page>`), so focus returns to the button that was pressed.

## Before and after (read-only bar, data like Precious Lopez's)

| | Line | Button |
|---|---|---|
| Before | "Hidden: 209 ads not in a group (off or no report data for 7+ days, and no messages in 7 days)." | "Show hidden (209)", which changes nothing here |
| After | "209 ads not in a group are hidden (off or no report data for 7+ days, and no messages in 7 days): see Manage in Edit." | none |
| After, mixed case (2 groups, 3 ids, 50 ads) | "Hidden: 2 groups, 3 ids in groups (off or no report data for 7+ days, and no messages in 7 days). 50 ads not in a group are hidden: see Manage in Edit." | "Show hidden (5)" |

## Case table

| Case | Test | Result |
|---|---|---|
| S-213.1 | `AdGroupPagesTest::test_S_213_1_to_6…` (c1: read-only line, no button; Edit "Show hidden (209)" reveals the list) | pass |
| S-213.2 | same test (c2: "Show hidden (1)", group dimmed, "Hide again") | pass |
| S-213.3 | same test (c3: read-only 5 reveals 2 groups and 3 ids; the line names the 50; Edit counts 55) | pass |
| S-213.4 | same test (c4: no line and no button in either view) | pass |
| S-213.5 | same test (c5: Edit toggled on and closed; the table shows "Hide again" with the groups; with only ads hidden, the line and no button) | pass |
| S-213.6 | same test (c6: flex-wrap, break-words, `aria-pressed`) and the browser check below | pass |

- **Red check.** The new test fails on d05d1b63's script with the live bug: the read-only line reads "Hidden: 209 ads not in a group (…)." and has the button "Show hidden (209)".
- **Browser check** (`docs/specs/213-evidence/shots.mjs`, headless Chrome, the real panel script, local Tailwind, a synthetic page):
  - **At 390 x 844:** the read-only line is 366 px wide and wraps onto 2 lines (only ads hidden) or 3 lines (mixed). `scrollWidth` 390 = `innerWidth` 390, so there is no sideways scroll. The button keeps `aria-pressed="false"`.
  - **At desktop width:** one line, no sideways scroll.
  - **Files:** `checks.json` and `readonly-*.png`.

## Story changes

- **New section S-213**, with cases 1 to 6.
- **One existing assertion changed.** In `AdGroupPagesTest::test_S_212_4_5_4_6_and_7_4_to_7_9…`, the read-only button now reads "Show hidden (3)": 1 group plus 2 ids. It used to read "Show hidden (4)", because it also counted 1 ad not in a group, which that view can't show. This is the intended change.

## Done-when checklist

- [x] **The cases have Node DOM tests, and they pass.**
- [x] **The five ad-group test files pass:** 160 tests (`--filter "AdGroup|AdBased|CommentReplies|AdPreview|AdOpen"`).
- [x] **The full suite adds no new failures against d05d1b63.** See Tests.
- [x] **The result file is filled in.**

## How to run

```
php vendor/bin/phpunit tests/Feature/Chatblast/AdGroupPagesTest.php --filter S_213
node docs/specs/213-evidence/shots.mjs <worktree> <outdir>
```

## Rulings

- **Read-only wording when only ads are hidden.**
  - **Ruling:** the line reads "N ads not in a group are hidden (…why…): see Manage in Edit." When groups or ids are hidden too, the usual "Hidden: …" sentence comes first and the ad sentence follows it.
  - **Why:** the spec's example wording, without a doubled "Hidden".
  - **Cost if wrong:** copy only.
- **One "toggle" value per view.**
  - **Ruling:** the two views now use different `data-ag-hidden-toggle` values.
  - **Why:** the focus-after-click lookup found the first match on the page, which could be the other view's button. This fixes the earlier review minor at no extra cost.
  - **Cost if wrong:** none known.

## Deferred minors

None.

## Merge danger

- **Door:** two-way. One JS file changes; there is no server change.
- **Revert:** revert the commit.

## Conflicts with CLAUDE.md

None.

## Tests

- **New:** one Node DOM test in `AdGroupPagesTest`. It is a synthetic page per case, with 17 assertions.
- **Updated:** one existing assertion, as described under Story changes.
- **Full suite on this branch:** 3,976 tests, 4 failures, all also in the baseline: `BigBroadcastTest` S-167.1, `ExampleTest`, `ItemTableFitScriptTest` and the flaky `AstraAddressRulesTest` S-26.18. No new failures. The baseline is the run on d05d1b63's code: 3,975 tests, 5 failures. 3 of those also fail on `vps/main`, and 2 are flaky `AstraAddressRulesTest` cases.

## Open questions

None.

## Trial report

n/a

## Process suggestions

None.

## Proposed tasks

None.

## Suggested next steps

- Review, merge and deploy.
- Check the live read-only bar of Precious Lopez.
