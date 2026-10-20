# Brief 099: /chatbot/pages checks each page's values, and shows the history of every edit

## Why

On `/chatbot/pages` (`resources/views/chatbot/index.blade.php`, `ChatbotController`, `ChatbotFields`, `PageSettings`) the owner can't tell whether a page's botfields are complete and correct, and can't see who changed what. Every write through `PageSettings::set` already keeps the old row (`active=false`, `replaced_at`) with `created_by`, but `/chatbot/pages` has no view of that history. `/chatblast/page-settings` has one (`PageSettingController::history`).

The owner, 2026-10-21: "gusto ko rin magkaroon ng way dito to check kung tama nailagay na value … may logs/change logs ba yung edit dito?".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **A Check column** after FLOW, with the normal check and X icons used on `/chatblast/page-settings` (spec 082). It shows X with short reasons, a check when all pass, and "—" for a page with no flow. It is computed on the server by `ChatbotPageCheck`, cached per page for 10 minutes, and forgotten when the page's settings or flow change. A "Check again" action recomputes it. | The owner: see if values are right. |
| *Missing values* | The botfields the page's FLOW uses: every `{{Name}}` in the flow's published graph (send texts, AI prompts, conditions, sheet columns, set-field values), plus botfields referenced inside other botfields of the page. Each must be non-empty for that page. Reason: "Missing: Prompt 2, Seq 4". Customer fields and System variables don't count as missing. Reuse the 097 grouping helper if it is merged; otherwise do your own split, consistent with `ChatbotVars::merge`. |
| *Broken images* | Image fields (PICTURE, Seq Img N, and any field whose kind is image) must be http(s) URLs that answer 200 with an `image/*` content type to a HEAD request (GET with a Range header if HEAD isn't allowed), with a 5 s timeout. The result is cached 24 h per URL, and there are at most 20 URL checks per page per run. Reason: "Seq Img 3: link broken (404)" or "PICTURE: not an image". There is SSRF care: only http/https, no private, loopback or link-local IPs (resolve and check), and redirects at most 3, re-checked. |
| *Pairs* | Seq N set but Seq Img N empty, or the reverse, when the flow or its sequences use both. Reason: "Seq 5 has no Seq Img 5". |
| *Unknown variables* | A `{{X}}` inside a botfield value where X is no known variable, botfield or customer field. Reason: "Prompt 1 uses {{PRICEE}} (unknown)". |
| **Preview.** A "Preview" button in the 096 Edit modal and on the row shows the page's main texts (Main Flow, Main Flow 2, Fill-Up Form, Loop 3 Form, Seq 1–20) as the customer would see them, with variables filled for a sample customer (name "Juan Dela Cruz") and images shown. It is read-only. | See what goes out. |
| **History per field.** In the 096 Edit modal, each field gets a "History" link that lists its versions: value (folded to 3 lines, expandable), who (user name), when (Manila time), and current/old. Each old version has "Restore". Restore writes it back through `PageSettings::set`, so it is itself a new history row. | Who changed what. |
| **Changes log.** A "Changes" button above the grid opens a list of recent edits across all chatbot pages, newest first, paginated 50 at a time: when, who, page, field, old value → new value (each folded). Filters: page, user, field, date range. Data comes from `page_settings` rows for provider `chatbot` (and the `botcake` keys shown on this grid), pairing each row with its predecessor. Bulk-save and paste edits show as one entry each per field. | Change log. |
| **Permissions** are the same as viewing `/chatbot/pages`. Restore needs the same right as editing a cell. Secret values (`is_secret`) are never shown in history or the Changes log; show "(hidden)". | Safety. |

## Done when

- **Tests:**
  - missing values derived from a flow graph (send, AI, condition, sheet, set) and from nested botfields;
  - image check: 200 image passes; 404, a non-image and a private IP fail (Http fake and a resolver stub); the cache;
  - pairs;
  - unknown variable;
  - the Check cache is forgotten on a write;
  - field history order and who and when;
  - Restore creates a new row;
  - the Changes log pairs old and new, filters, paginates, hides secrets;
  - permissions.
- **Browser check:** the Check column with a check and an X with reasons, Preview, field History with Restore, and the Changes list with filters, at 1366 and 390 px. No page-level horizontal scroll, with the table-fill layout from 092 kept.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- Report the commits.

## Rules

- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/099-pages-check-and-history.md` and the result as `docs/specs/099-pages-check-and-history.result.md`.
- Headless browser check at 1366 and 390 px, with screenshots in `docs/specs/099-pages-check-and-history/`.
- Budget: 2 attempts per failing step.
