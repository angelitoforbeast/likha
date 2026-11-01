# 213: "Show hidden" on the read-only Ad groups panel shows only what that panel can reveal

## Why

On the live /chatbot/pages read-only "Ad groups" panel of a page (Precious Lopez), the bar reads "Hidden: 209 ads not in a group (off or no report data for 7+ days, and no messages in 7 days)." with a "Show hidden (209)" button. Pressing it changes nothing visible. All 209 hidden items are unassigned ads, and the "Ads not in a group yet" list is only rendered in the Edit view (`unassignedBlock`, used at `public/js/chatbot-adgroups.js` ~L522). The read-only panel renders `hiddenBar(page)` (~L454) without that list. Staff read this as broken.

## Decisions

| # | Decision | Who and why |
|---|---|---|
| D1 | In the read-only panel, the "Show hidden (N)" button counts and toggles only what that panel shows: hidden groups and hidden ids in groups. If that count is 0, there is no button there. | The owner asked for the fix, 2026-11-01. The design is the reviewer's decision: a button must always change what you see. |
| D2 | In the read-only panel, quiet unassigned ads are still mentioned on the "Hidden:" line, and the line points to where they are, for example "…209 ads not in a group are hidden: see Manage in Edit." There is no button for them there. | Reviewer's decision. Keep the information without a dead control. |
| D3 | The Edit view is unchanged. Its "Show hidden (N)" still counts everything there, including the unassigned list, and still toggles it. The shared `showHidden` state per page stays as it is. | Reviewer's decision. The Edit view works. |
| D4 | No server or PHP change, unless the read-only count truly needs data the panel lacks. If so, stop and report first. | Reviewer's decision. This is a display-only fix. |

## Cases (add as S-213.x in qa/stories.md; extend the Node panel test)

1. **Only unassigned ads hidden.** The read-only panel shows the "Hidden:" line with the count of unassigned ads and the "Manage in Edit" pointer, and no button. The Edit view shows "Show hidden (209)", which reveals them in the list.
2. **Hidden groups only.** The read-only button counts the groups, plus their ids if counted that way today. Pressing it shows them dimmed and reads "Hide again".
3. **Mixed** (2 hidden groups, 3 hidden ids, 50 hidden unassigned). The read-only button counts 5 and reveals those 5. The line still mentions the 50 with the pointer. The Edit button counts 55.
4. **Nothing hidden.** No line and no button, in either view.
5. **Toggling in Edit and then closing it.** The read-only panel shows the same state, and nothing breaks.
6. **390 x 844.** The line wraps, there is no sideways scroll, and `aria-pressed` is kept on the button.

## Done when

- The cases have tests (the Node DOM test) and they pass.
- The five ad-group test files pass.
- The full suite adds no new failures against d05d1b63.
- The result file is filled in.

**Risk tier:** low. Display only, no reply path.

**Out of scope:** anything else in the panel.

**Report:** the branch, the head sha, the tests, and before/after text of the read-only bar for the Precious Lopez-like data.

Budget: small job, 2 attempts per failing step.
