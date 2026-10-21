# Result 084: a "Needs setup" list of pages whose chatblast is blocked

Branch `feat/084-needs-setup`, cut from `main` at `69d7c40c` (082 merged). Nothing was pushed.

## Commits

| Commit | What |
|---|---|
| `58b87aa1` | docs: the brief (verbatim) |
| `0594ac45` | feat: the service, the panel on both pages, `needs-setup.json`, the Fix link, tests |
| (this commit) | docs: result, screenshots |

## What changed

| File | Change |
|---|---|
| `app/Services/ChatblastNeedsSetup.php` (new) | Builds the list. One grouped query (`GROUP BY page_name, status, reason` with `COUNT` and `MAX(created_at)`) over non-test `botcake_messages` with status `no_config`, `failed` or `field_mismatch` and `created_at >= now − 3 days`, using the `created_at` index. Cached 60 s. Spellings of the same page are merged, and the page shows under the spelling with the most rows. Sorted by count, biggest first. |
| `resources/views/chatblast/partials/needs-setup.blade.php` (new) | The red panel, titled "Needs setup (N pages)". Each line: page · reason · "N unsent, latest YYYY-MM-DD HH:MM" · Fix. When the list is empty, nothing is rendered. |
| `app/Http/Controllers/ChatblastController.php` | Passes the list to To send (`/chatblast`). New `needsSetup()` serves `GET /chatblast/needs-setup.json` behind the same access check as the page (404 otherwise). |
| `app/Http/Controllers/PageSettingController.php` | Passes the list to `/chatblast/page-settings`. |
| `resources/views/chatblast/index.blade.php`, `resources/views/page_settings/index.blade.php` | The panel at the top. On page-settings, `?q=<page>` (the Fix link) fills the filter and opens that page's row when it has one. Otherwise the filter shows no row, and "+ Page" adds it. |
| `routes/web.php` | The JSON route. |

**The reason groups:**

| Group | Rows |
|---|---|
| Not set up in page-settings | `missing …` / `walang …` that includes `page_id` or `token` |
| Missing flow or message field | `missing …` / `walang …` that has only `flow.shipping` / `field.message` |
| Needs a test send | "Page not ready: needs a test send" |
| *the 082 reason itself* | "Page not ready: …", for example "No flow named … in Botcake" or "Sent N, none delivered (Botcake)", shown without the prefix |
| Send failed | status `failed` or `field_mismatch` |
| Message details missing (order data) | `missing {…}` / `walang {…}`, incomplete text, rider info, in English and the old Tagalog |

`no_psid` and test rows are excluded. Any other reason is shown as written.

**JSON shape:** `{"window_days": 3, "pages": N, "rows": [{"page", "group", "count", "latest"}]}`.

**Window:** "the last 3 days" is `created_at >= now − 72 hours` (Manila). A row that a later build changed to ready, sent or skipped stops counting at the next cache refresh, within 60 s.

## Tests

`vendor/bin/phpunit tests/Feature/Chatblast/ChatblastNeedsSetupTest.php`: 4 tests, all pass.

| Case | Test | Result |
|---|---|---|
| Grouping: English and Tagalog reasons merged; spellings of a page merged; no_psid, test and sent rows left out; the 082 reasons; Send failed; message data; biggest first; latest time | `test_S_084_1…` | ✔ pass |
| The 3-day window (3 days + 1 min is out, 3 days − 1 min is in); a row turned `sent` drops off after the 60 s cache | `test_S_084_2…` | ✔ pass |
| The panel is hidden on both pages when nothing is blocked, and shows with the page, the reason and the Fix link when something is | `test_S_084_3…` | ✔ pass |
| The JSON shape; 404 for a role without Chatblast access | `test_S_084_4…` | ✔ pass |

Red runs, before the code:
- S-084.1 and .2: `Target class [App\Services\ChatblastNeedsSetup] does not exist.`
- S-084.3: the page didn't contain the panel.
- S-084.4: `Expected response status code [200] but received 404.`

Then two fixes of my own:
- the shown spelling of a page is now the one with the most rows;
- a JS comment on page-settings contained the panel's title text, which tripped the "hidden" assertion, so I reworded it.

**Suites:**
- `tests/Feature/Chatblast tests/Feature/PageSettings`: 570 tests, all pass.
- Full suite: 2014 tests, only the two known failures (`ExampleTest`, `NightRun\ImportStartTest`).

## Browser check

Headless Chrome over CDP, against a scratch app (queue `null`, so nothing can call Botcake). The script is outside the repo: `%TEMP%\likha075\check084.mjs`. The seed has 6 blocked pages: missing flow, needs a test send, the Tagalog "walang", an 082 X, a failed send, and a long page name with no setup.

| Check | 1366 px | 390 px |
|---|---|---|
| Panel at the top of `/chatblast`: "Needs setup (6 pages)" and 6 lines, biggest first | ✔ | ✔ |
| Panel at the top of `/chatblast/page-settings` | ✔ | ✔ |
| No page-level horizontal scroll | 1366 = 1366 ✔ | 390 = 390 ✔ |
| Long page name and long reason wrap inside the panel | ✔ | ✔ |
| Fix (Beta Shop) | page-settings `?q=Beta%20Shop`: filter set, one row, opened ✔ | — |
| Console errors | none ✔ | none ✔ |

Screenshots in `docs/specs/084-needs-setup/`: `desktop-chatblast.png`, `desktop-page-settings.png`, `phone-chatblast.png`, `phone-page-settings.png`, `desktop-fix-link.png`.

## Notes

- The panel is on To send only, not on History (the same view). The brief names "/chatblast (the main page)".
- The Fix link leads to page-settings. A Chatblast user without page-settings access gets that page's 404, as for any page-settings link.
- No review agent was run for this small task.
