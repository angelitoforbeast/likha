# 287: one site account can keep several Facebook (Meta Ads) connections

## Why
Staff connect Facebook ad accounts on /ads_manager/report (the Facebook / Meta Ads API card). Each staff member works several Facebook accounts in separate browser profiles (SunBrowser).

Today a site user holds **one** Meta connection. `App\Services\MetaAds\Connections` stores it under the key `meta_ads.connection.<user id>`. Connecting a second Facebook account with the same site login replaces the first, so staff need a separate site account per Facebook account.

The owner wants one site account to keep **many** Facebook connections.

## Stories
**FB-1 (P1).** As staff, I connect Facebook account A, then (in another browser profile, same site login) Facebook account B. Both stay connected and both show in the card: Facebook name, token expiry and status, each with its own Disconnect.
- Connecting the same Facebook account again refreshes that one connection; it does not create a duplicate.
- Disconnect removes only that Facebook connection.

**FB-2 (P1).** The scheduled sync, Sync now, the ad-account list and selection, Ad Accounts health (`AccountHealth`) and Data health all use **every** connection of every user, as they use every user's connection today.
- The same ad account reached through two connections is still one account (keep today's de-duplication).

**FB-3 (P1).** Existing data keeps working with no reconnect: today's `meta_ads.connection.<user id>` rows are read as that user's connection. Migrate them in code or read both forms; say which in the plan.
- No schema change. Lean: app_settings rows, for example `meta_ads.connection.<user id>.<fb_user_id>`.

**FB-4 (P2).** An expired or needs-reconnect connection is flagged on its own row; the others are unaffected.
- Any per-user logic that assumes one connection (who may press Sync now, the "connected as" line, reconnect prompts, the connect callback) works per connection.

**FB-5 (P2).** If the data-deletion callback exists on this base (it may not), it removes connections by fb_user_id across users.
- If it does not exist here, note it; do not add it.

## Constraints
- Reuse `Connections`, `Graph`, `MetaAdsController` and the views. Do not add a second Graph client.
- Tokens stay encrypted and are never logged or printed.
- Same middleware and permissions as today.
- Tests with the existing MetaAds test case and fake Graph.
- The whole `tests/Feature/MetaAds` folder must pass. Full suite: no new failures compared with base 3647457f. The known base failures are ExampleTest, ItemTableFitScriptTest and SharedPagesBasePinTest.

## Done when
- FB-1..FB-4 tests pass.
- The MetaAds folder is green.
- The full suite has no new failures.
- The branch is pushed.
- The result has evidence and a merge-danger section that covers how existing connections behave after deploy and how to revert.

## Budget
Medium. At most 2 attempts at a failing step, then report.
