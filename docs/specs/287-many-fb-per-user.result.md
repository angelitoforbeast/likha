# 287 result: one site account keeps several Facebook connections

Base: live 3647457f. Branch `feat/287-many-fb-per-user`.

## Plan

**Storage (FB-3: read both forms, no bulk migration).** A connection is one app_settings row. New rows use the key `meta_ads.connection.<user id>.<fb_user_id>` (fb_user_id limited to `[A-Za-z0-9_-]`). Today's `meta_ads.connection.<user id>` rows are read as they are, as that user's connection, with no rewrite on deploy. The part of the key after the prefix is the connection's `ref` (`12` for an old row, `12.10001` for a new one); every write (update, disconnect) goes through the ref, so old and new rows behave the same.
- When a user connects a Facebook account, the new-form row for that fb_user_id is written (updateOrInsert, so connecting the same account again refreshes it: no duplicate). The user's old-form row is deleted in the same step when its fb_user_id is the same account or is empty, so an old row turns into a new row the first time its owner reconnects that account, and never stays as a stale twin.
- A connect where Facebook returns no fb_user_id at all keeps today's behavior: it is saved under the old-form key `meta_ads.connection.<user id>`.

**`Connections` (reused, no second Graph client).**
- `all()`: every connection of every user, keyed by ref, each with `user_id` and `ref`; ordered by user id, then row id, so the first connection that reads an ad account is the same one as today (lowest user id first).
- `of(int $userId)`: that user's connections. `get(int $userId)` stays (the user's newest connection) for the callers that only ask "is there one". `find(string $ref)`: one connection by ref.
- `save()`, `update()`, `disconnect()` take the ref; `save()` derives the key from fb_user_id as above.
- `accounts()` keeps its de-duplication by ad account id (FB-2), naming every Facebook that reads it.

**Consumers (FB-2, FB-4).** Sync, Compare, AccountHealth, DataHealth, AdGroups, FacebookName and the `meta-ads:accounts` command loop over `all()` by value and write back through `$c['ref']`, so an expired token marks only that row. Today's `$seen` / `isset($accounts[$id])` de-duplication is unchanged.

**Controller and card (FB-1, FB-4).**
- One row per connection: Likha name, Facebook name, ad accounts, last sync, expiry, status (OK / Reconnect: Token expired / Expires within 7 days), flagged per row.
- Your own rows get their own Disconnect (POST `ref`); the controller refuses a ref that is not the signed-in user's (404), so permissions stay as today (you can only disconnect your own). A POST without `ref` still works when you have exactly one connection (an old open page), and is refused with a message when you have several.
- "Connect Facebook" always shows (it reads "Connect another Facebook" once you have one), since the second account is added from another browser profile. The Reconnect button on your own row goes to the same connect route; the hint says which Facebook to be signed in as.
- Callback: saves per fb_user_id; the status line names the Facebook account; the connect / disconnect event keeps `user_id` and adds the fb_user_id and name to its details (no token).
- Sync now: unchanged rule (write mode on and at least one selected account); it never depended on who is connected.

**FB-5.** There is no Meta data-deletion (or deauthorize) callback on this base (`grep -i "deletion|deauthor"` over app and routes finds only Gsheet deletion runs). Noted; not added.

**Tests (new `MetaAdsManyConnectionsTest`, existing MetaAdsTestCase + fakeGraph).** FB-1: connect A then B as the same user, both rows and both cards' rows; reconnect A refreshes one row; Disconnect A leaves B; another user's ref is refused. FB-2: Sync and AccountHealth read through both connections, a shared ad account is synced once. FB-3: an old-form row is listed, synced and disconnected with no reconnect, and is replaced (not duplicated) when its owner reconnects the same account. FB-4: an expired token marks only its own row. Existing tests that read the exact old key after a callback connect are pointed at the new key.

**Verify.** `tests/Feature/MetaAds` green; full suite compared with base 3647457f (known failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest).

## What shipped

Commit d2cbf777 (code and tests), on top of the plan commit 30399488. No route, middleware, migration or config change.

- `app/Services/MetaAds/Connections.php`
  - `key(userId, fbUserId)` builds the key.
  - `save()` writes the per-account row, and in the same transaction deletes the user's old-form row when it is the same account or has no fb_user_id. It returns `user_id` and `ref`.
  - `update()`, `disconnect()` and the new `find()` take a ref.
  - `of(userId)` is new. `get(userId)` now returns the user's newest connection.
  - `all()` parses both key forms (`<uid>` and `<uid>.<fb>`; anything else is ignored), is keyed by ref, and is ordered by user id, then row.
  - `accounts()` no longer relies on the array key.
- `Sync.php`, `Compare.php` and `AccountHealth.php` loop by value, mark expiry and last sync through `$c['ref']`, and log `$c['user_id']`. `DataHealth`, `AdGroups`, `FacebookName` and the `meta-ads:accounts` command already read `all()`/`accounts()` by value, so they are unchanged and now see every connection.
- `MetaAdsController`
  - The panel has one row per connection, and user names are looked up by `user_id`.
  - `disconnect` takes `ref` and only accepts your own: another user's ref or an unknown ref gives a 404. With no ref it removes your only connection; it is refused with a message when you have several, and says "No Facebook connection to disconnect" when you have none.
  - The connect and disconnect events add `fb_user_id` and `fb_user_name` to protected_details. No token is stored there.
- `_meta_ads.blade.php`
  - "Connect Facebook" always shows, and becomes "Connect another Facebook" once you have one.
  - Each row has `data-connection="<ref>"`, and your own rows have their own Disconnect (hidden `ref`).
  - A Reconnect row says which Facebook account to be signed in as. Rows show status per row as before (OK / Token expired / Expires within 7 days).
- Tests:
  - New `tests/Feature/MetaAds/MetaAdsManyConnectionsTest.php` (9 tests).
  - `MetaAdsConnectTest` and `MetaAdsLoginConfigTest` read the row at the new key `meta_ads.connection.<id>.fb-ana` instead of the old key. Without that, one test errored and the other's "token not in the stored row" check would pass on a null row.
- FB-5: there is no Meta data-deletion or deauthorize callback on this base, so nothing was added.

## Evidence

New tests (`vendor/bin/phpunit tests/Feature/MetaAds/MetaAdsManyConnectionsTest.php`): `OK (9 tests)`.
- FB-1:
  - Two Facebook accounts connected through the callback with one login keep two rows, `<id>.fb-a` and `<id>.fb-b`, with both tokens decrypting. The card shows both, each with its name, expiry, OK and its own Disconnect, and no token appears on the page.
  - Connecting fb-a again leaves two rows, and refreshes fb-a's token, name, accounts and status.
  - Disconnecting fb-a leaves fb-b and logs `disconnect` with fb-a's id and name.
  - The CEO gets a 404 on another user's ref and sees no Disconnect on others' rows. A bare disconnect with several connections is refused and nothing is deleted.
- FB-2:
  - Sync with one user holding two connections (111+333 and 222+333) imports a1, a2 and a3. It calls `act_333/insights` once, reads 222 with the second token, and stamps last_sync_at on both rows.
  - `accounts()` lists 111, 222 and 333 once, with 333 naming both connections, and `Sync::current()` gives `['111','222','333']`.
  - The Ad Accounts page shows one row for 333 naming "Ana A, Ana B", and calls me/adaccounts once per token.
- FB-3:
  - A hand-written old-form row `meta_ads.connection.<id>` is listed with ref `<id>`, shows on the card with its Disconnect, syncs with its token, gets last_sync_at, and disconnects.
  - Reconnecting that same Facebook account replaces it with `<id>.fb-old`, leaving one row.
  - Another user connecting a different account keeps that user's old row next to the new one.
- FB-4: with the second token answering 190 on insights, only that row turns `expired` and the first stays `ok`. The card shows OK on row A, and "Token expired" with the Reconnect hint and Disconnect on row B.
- Against the base code (app/ and resources/ stashed): all 9 fail (6 failures, 3 errors).

MetaAds folder (`vendor/bin/phpunit tests/Feature/MetaAds`): `OK (263 tests, 1287 assertions)`.

Full suite (`vendor/bin/phpunit`): `Tests: 5356, Assertions: 83507, Failures: 3, Skipped: 6`. The three failures are the known base failures and nothing else:
1. `ExampleTest::test_the_application_returns_a_successful_response`
2. `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
3. `SharedPagesBasePinTest::test_S_42_4_...` with data set "/owner/private"

## Rulings

1. Read both forms instead of a migration: no data job on deploy, and revert stays a plain code revert while no one has reconnected.
2. Two site users who connect the same Facebook account keep two rows, one per user, the same as today. Account de-duplication stays as is.
3. Users can disconnect only their own connections, the same as today. A CEO cannot remove other users' connections in this spec.
4. An old-form row is replaced when its owner connects the same fb_user_id. It is also replaced by any account the owner connects when the old row has no fb_user_id, because it cannot be matched and today any connect replaced it.

## Merge danger

**After deploy.**
- Every existing `meta_ads.connection.<user id>` row keeps working with no reconnect: it is listed, synced, marked expired and disconnected through its ref (`<user id>`). Nothing is rewritten on deploy.
- An old row changes only when its owner connects again. If it is the same Facebook account, it becomes `meta_ads.connection.<user id>.<fb_user_id>` (the old row is deleted in the same transaction). If it is a different account, the old row stays and a second row is added.
- The ad-account selection (`meta_ads.accounts`) is keyed by ad account id, not by user, so it is unchanged.
- Users with an old open report page whose Disconnect has no ref still disconnect, as long as they have only one connection.

**Revert.** `git revert d2cbf777`. The spec and plan docs can stay. While nobody has connected after the deploy, there are only old-form rows and the revert is clean.
- If new-form rows exist, the old code reads `meta_ads.connection.12.fb-a` as user 12, because it casts the key suffix to int. With several rows per user, the last row wins in the list, and sync still reads through it.
- But the old `update(12)` and `disconnect(12)` only touch `meta_ads.connection.12`. So for new-form rows, expiry and last sync would not be saved and Disconnect would not remove them.
- Before or right after reverting, check with a plain SELECT: `SELECT id, `key` FROM app_settings WHERE `key` LIKE 'meta_ads.connection.%.%'`.
- For each user, rename the one row to keep to `meta_ads.connection.<user id>`, after deleting any old-form row that user still has, because the key is unique. Then delete the user's other new-form rows. Those Facebook accounts would have to be reconnected as separate site users, as before 287.
