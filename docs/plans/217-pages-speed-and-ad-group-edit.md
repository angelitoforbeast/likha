# Plan: spec 217 a fast pages screen, and ad groups edited like a page

Base `vps/main` f5d6d450, branch `feat/217-pages-speed-ad-group-edit`. No migration, no new table, no queued job touched.

## How it is measured (before any change)

- `tests/perf/set217.php` builds a throwaway sqlite set with no env file (the environment is set in the script, local
  test values only): 80 pages with 30 values each, 3 Ad-based pages (007, 014, 021) with 6 groups and 250 ads each
  (about 223 not in a group, customers in the last 30 days, 5 days of Ads Manager rows, 4 in 10 On).
- `tests/perf/pages217.mjs` drives headless Chrome over the DevTools protocol (the same client as spec 209, nothing
  outside 127.0.0.1 resolves): server time and size of `/chatbot/pages`, `/chatbot/data` and `/chatbot/ad-groups`, DOM
  elements, the longest main-thread task and frame of load, scrolling, showing and hiding each Ad-based page's ad
  groups and Manage in Edit; at 1600 px and at 390 px. `--cpu=4` slows the page 4 times, as a normal office PC.
- The numbers before the change go in the result first; the same run after each speed step.

## Tasks

| # | Task | Cases | Side | Tier |
|---|------|-------|------|------|
| 1 | Timing set and run (`tests/perf/set217.php`, `tests/perf/pages217.mjs`), the "before" numbers | S-217.1.1 (before), 1.4 (before) | test | low |
| 2 | Speed: the grid's layout and the work per frame (from the profile: whole-table layout on every reveal frame, the 3-line fade's reads, the Edit window's textarea sizing one by one); the Edit window and closed sub-rows free their nodes | S-217.1.1, 1.2, 1.3, 1.4 | frontend | medium |
| 3 | Group values saved several at once through the existing group value endpoint (`/chatbot/ad-groups/value` with `values`: one lock, one transaction, history and Restore as one value, same throttle and access) | S-217.2.2 (server), 2.3, 2.4 | backend | medium |
| 4 | Group Edit: the page Edit window opened for a group (title page · group, the page's fields, own value or empty = "uses default" with the page value muted, paste box, AI mapping through `/chatbot/paste-map` unchanged, Apply, Save, History with Restore); an Edit button on each group row in the list and in Manage in Edit | S-217.2.1, 2.2, 2.5 | frontend | medium |
| 5 | Running ads first: groups with an On ad first, then customers in 30 days, then name; ads in a group On, New, customers; "Ads not in a group yet" On, New, customers (quiet ones still last, 212) | S-217.3.1, 3.2, 3.3 | backend | medium |
| 6 | Test as a customer from an ad: the Ad dropdown on /chatbot/test (Ad-based pages only), the server resolves the group as the live run does (ad, ad set, campaign; no Meta call) and returns the log line; the Ad wins over the Ad group picker | S-217.4.1–4.4 | both | medium |
| 7 | Manage in Edit as a normal primary button beside the count; "Default (page values)" | S-217.5.1, 5.2 | frontend | low |
| 8 | Stories, full suite, result | all | docs | low |

Tasks 3 and 5 share `AdGroups.php`; 4 and 7 share `index.blade.php` and `chatbot-adgroups.js`, so they run one after
the other in this order. Speed comes first, as the spec asks.

## Tests

- Server: PHPUnit feature tests named with the case IDs (`tests/Feature/Chatblast/PagesSpeedAdGroupEditTest.php`).
- Browser-only behaviour (the group Edit window, paste, Apply, the lazy sub-rows, the order shown, the Ad dropdown, the
  button): the plain scripts in Node with the small DOM of `tests/js/dom-stub.cjs` where they live in
  `public/js/chatbot-adgroups.js`, and the timing run's case checks in real Chrome for what lives in the Blade page, each
  named with its case ID and recorded in the result.

## Design notes

- Group Edit reuses the page Edit (`#pe`): `openPe(r, group)`; the fields are the page Edit's own (`editCols()`), kept to
  the keys the group can set (the server's key list, which already leaves out the identity and switch keys); the paste
  plan and the AI mapping run on the group's own values as the "before"; Save sends the changed fields in one request.
- The group's History link opens the group's history (all keys, with Restore), as the list's History does.
- No AI prompt or second AI path; `/chatbot/paste-map` is called with the page name exactly as today.
