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

(after the build)

## Evidence

(after the build)

## Rulings

1. Read both forms instead of a migration: no data job on deploy, and revert stays a plain code revert while no one has reconnected.
2. The same Facebook account connected by two different site users stays two rows (one per site user), as today.
3. An old-form row is replaced when its owner connects the same fb_user_id, or any account when the old row has no fb_user_id (it cannot be matched, and today a connect replaced it).

## Merge danger

(after the build: how existing connections behave after deploy, and how to revert)
