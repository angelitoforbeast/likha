# Result 110: Needs setup lists only real setup problems

## What changed
- `app/Services/ChatblastNeedsSetup.php`: `list()` now goes through `compute()` (still cached 60 s). Failed sends whose flow/write step answer carries Facebook `(#551)` or `(#100)` are `customer_unreachable` (`isUnreachable()`, computed from `steps` at read time, nothing stored). They are left out of the red list and returned by the new `unreachable()` (page and count, biggest first). "Send failed" rows are listed only when the page's setup-side failures are at least 10% of its attempts in 3 days (`FAIL_RATE`), or every attempt in its last hour failed; invalid-token rows and `no_config` rows are always listed as before.
- `resources/views/chatblast/partials/needs-setup.blade.php`: grey info line "Hindi na maabot ang customer ..." (top 5, rest folded, total); the red box and its count are unchanged and only count pages needing setup.
- `app/Http/Controllers/ChatblastController.php` (`needsSetup` JSON gains `unreachable`; `page()` passes it), `app/Http/Controllers/PageSettingController.php` (`index()` passes it), and the two views that include the partial (`chatblast/index.blade.php`, `page_settings/index.blade.php`).

## Migration
None.

## Tests
`tests/Feature/Chatblast/ChatblastNeedsSetupTest.php`: S-110.1 only #551 is info not setup, S-110.2 #100 counts, S-110.3 missing flow stays in, S-110.4 10% rule (in at 1 of 10, out at 1 of 11), S-110.5 last hour all failed, S-110.6 title count and box hidden when none. The S-084.4 JSON test now expects `unreachable`.
Full suite: 842 tests, OK (1 skipped, 50 deprecations, none new).

## Notes
- Tests were added together with the code in one commit; the red run was not captured separately.
- Attempts for the 10% rule = sent, failed, field_mismatch and unknown rows (non-test).
