# Spec 238: "Page restricted" is red only when it is big, and the limits are editable data

**Project:** Likha (the same code runs incepxion.pro). **Shape:** change · **Weight:** bounded · **Risk tier:** medium

## Why
app/Services/Chatbot/BotHealth.php PAGE_LIGHTS has `'page_blocked' => ['Page: restricted or permission failures', '', 1, 3]`: amber at 1 failure and red at 3, counted over the last 60 minutes. So "Page restricted by Facebook: 3 of 303 sends failed" (1%) is red and urgent, although 99% of the page's sends go out. The owner wants it red only when it is big, with the limits kept as editable data. These lights already use thresholds saved over defaults (BotHealth::thresholds(), edited on the Health screen).

## Decisions already made
| # | Decision |
|---|---|
| 1 | Add a page light `page_blocked_pct` ("Page: restricted or permission failures, % of sends", unit %) with defaults amber 0 (any) and red 5. |
| 2 | Change the `page_blocked` count defaults to amber 1 and red 10. |
| 3 | The page's send light is red when blocked ≥ red count, OR (the page has page_min_sends sends AND blocked % ≥ red %). Otherwise it is amber when blocked ≥ amber count. Restricted plus permission failures, as today. |
| 4 | Both limits show and are editable where the other thresholds are edited, saved the same way. Existing saved thresholds keep working, and a missing new key takes its default. |
| 5 | The banner text stays ("Page restricted by Facebook: N of M sends failed since HH:MM"). Only the level changes. |

## Stories
Add these under `## Slice 238 – Page restricted level` in qa/stories.md, with one test per case:
- S-238.1.1: 3 of 303 restricted (1%) → orange (amber), not red.
- S-238.1.2: 10 of 303 restricted → red (count).
- S-238.1.3: 6 of 100 restricted (6%) → red (percent).
- S-238.1.4: 4 of 8 sends with page_min_sends 10 → the percent rule doesn't apply, so the count decides (amber).
- S-238.1.5: saved thresholds without the new key → the default 5% applies, and the Health thresholds form shows and saves it.
- S-238.1.6: the urgent count in the banner drops when a page goes from red to amber (it still counts as urgent if orange is urgent today; keep today's rule for what "urgent" means).

## Done when
- [ ] The cases pass, each with a test named after it.
- [ ] The Health and ProblemsPanel tests pass.
- [ ] The full suite adds no new failures (known: ExampleTest, ItemTableFitScriptTest; BigBroadcastTest and AstraAddressRulesTest S-26.18 are flaky).
- [ ] The result is filled in.
