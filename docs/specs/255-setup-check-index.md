# 255: The chatblast setup delivery check uses the page index

## Why
ChatblastSetupCheck::sentVia() (app/Services/ChatblastSetupCheck.php around line 366, run by botcake:setup-delivered) reads up to 50 sent, non-test botcake_messages of a page since a time, filtering with `LOWER(TRIM(page_name)) = ?`. The function on the column prevents MySQL from using the existing index botcake_messages_page_name_status_sent_at_index (page_name, status, sent_at), so it scans the table: one run took 20.8 s on the second site that runs this code. It is a background check, not a page, but it adds database load.

## Stories
**S1 (P1)** sentVia returns the same count as today for a page, but its query can use the (page_name, status, sent_at) index.
- Find how botcake_messages.page_name is written (which code sets it, from which name source) and whether stored names can differ from the settings key only by case or surrounding spaces. Choose the narrowest correct approach, for example: look up the exact stored name variants for the page once (from a small, indexed source such as page settings or a cached distinct list, not a scan of botcake_messages) and filter with `page_name IN (...)` plus status and sent_at; or another approach that keeps exact behaviour and is index-friendly. Explain the choice with file:line evidence.
- Pages whose stored name differs only in case/spaces still match.
**S2 (P2)** Look for other queries on botcake_messages with LOWER(TRIM(page_name)) on hot paths and list them in the result (do not change them unless trivially the same fix and covered by tests).

## Decisions already made
| Decision | Why |
|---|---|
| No new index unless the existing one cannot serve the query | One fitting index already exists. |
| Behaviour unchanged (same rows counted) | It is a correctness-neutral speed fix. |

## Constraints
- Works on MySQL (production) and the test driver; pgsql branch (BTRIM) keeps working.
- Risk tier: low.

## Done when
1. Tests: same result as before for exact, different-case and padded page names; a test asserting the SQL no longer wraps page_name in a function (query capture).
2. Full suite: no new failures vs 078a87e0.
3. Result file docs/specs/255-setup-check-index.result.md with the approach, file:line, S2 list, test counts, final sha.
4. Branch pushed.

## Out of scope
Other slow queries, any deploy.

Budget: small; at most 2 attempts per failing step, then stop and report.
