# Result 139: the "Needs setup" box on /chatblast/page-settings can be hidden

## Files and lines changed
- `resources/views/chatblast/partials/needs-setup.blade.php` (+25): with `collapsible` set, the header `<h2>` holds a
  `<button id="needs-setup-toggle" type="button" aria-expanded="false" aria-controls="needs-setup-body" data-pages="N">`
  with a chevron (▸, turns 90° when open) and the text "Needs setup (N pages)", so the count is visible either way.
  The explanation line and the list sit in `<div id="needs-setup-body" hidden>`: still rendered, hidden by default.
  A small inline script (no Alpine on this layout) wires the toggle. Without `collapsible` the box renders as before
  (only an extra plain `<div>` wrapper, no visual change), so /chatblast is unchanged.
- `resources/views/page_settings/index.blade.php` (1 line): the include passes `'collapsible' => true`.
- `tests/Feature/Chatblast/ChatblastNeedsSetupTest.php` (+14): `test_S_139_1_…`.

## How the default and the "count went up" rule work
- Stored in `localStorage` key `chatblast.needsSetup` as `{open, seen}`; every read and write is in try/catch, so with
  storage blocked the box simply starts collapsed each time and the toggle still works.
- First visit (nothing stored): collapsed; `seen` = current count is saved.
- Later visits: the box opens if the viewer's last choice was open, **or** if the current count is greater than
  `seen`. In the second case it opens this time only: the saved choice stays as it was, and `seen` is updated to the
  current count, so the next load goes back to the viewer's choice. A count that drops also updates `seen`, so a
  later rise is caught again.
- Clicking the button flips the list, `aria-expanded` and the chevron, and saves `{open, seen}`. It is a native
  button, so Enter and Space work.

## Tests
| Case | Test | Result |
|---|---|---|
| S-139.1 | `ChatblastNeedsSetupTest::test_S_139_1_on_page_settings_the_header_is_a_collapsed_toggle_and_the_list_stays_rendered` | pass (red first: toggle markup missing) |

`php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast`: `OK, but there were issues! Tests: 741, Assertions: 5233,
PHPUnit Deprecations: 37, Skipped: 1` — no failures. The JS behaviour (storage, reopen once) is not covered by a
browser test; no browser check was run in this session.

## Story changes
None (the brief brought no stories file; the one case is the brief's "Done when").

## Commits
- `30c6e7c1` docs: spec 139, hideable needs-setup box
- `4b125391` fix: the Needs setup box on page settings folds behind its header, hidden by default and reopened once when the count goes up (spec 139)
- this result commit

Not pushed, not deployed.

139: done — on /chatblast/page-settings the Needs setup list folds behind its header button (count always shown), collapsed by default, remembered per browser, reopened once when the count rises.
