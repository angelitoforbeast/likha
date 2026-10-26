# Plan 154: Meta Ads connect, compare and sync

## Storage (no new table, no migration)
- One `app_settings` row per connected person: key `meta_ads.connection.<user id>`, value JSON
  `{token (Crypt::encryptString), fb_user_id, fb_user_name, expires_at, ad_accounts:[{id,name,status}], status ok|expired,
  connected_at, last_sync_at, last_error}`. Disconnect deletes the row, so the token is gone (no history copy).
- `app_settings` `meta_ads.write_mode` (on/off, default off) and `meta_ads.schedule` (`10:00,15:00,18:45`).
- Runs: `upload_logs` type `ads_manager_api` (inserted/updated/skipped, user, started/finished, error_message for partial).
- Events (connect, disconnect, compare, write mode): `upload_logs` type `ads_manager_api_event`, `original_name` = the
  event, `user_id`, `created_at`. Nothing secret in either.
- Why not `page_settings`: its /page-settings grid lists every provider (only `analyst` is excluded), so token rows would
  show as a page with the last 4 characters of the token and could be edited or cleared there. `app_settings` has no
  listing UI, needs no schema change and holds one row per person.

## Tasks
1. **Graph client + OAuth + connections store** (backend, high: new OAuth callback route). `config/services.php`
   `meta_ads`; `App\Services\MetaAds\Graph` (GET, paging, 190 → TokenExpired, 4/17/613/80004 → RateLimited with
   backoff via `Sleep`), `Connections` (app_settings). Routes connect / callback / disconnect. Cases S-154.1, .2, .8, .10.
2. **Insights mapper** (backend, medium): `Insights` fetches insights + ad/adset/campaign/creative/page fields per
   account and maps to `ads_manager_reports` columns. Case S-154.4.
3. **Compare** (backend, medium): `Compare` service + `meta-ads:compare` command + POST compare route. Case S-154.3.
4. **Sync + write mode + schedule** (backend, high: writes money data). `Sync` service upserts through the upload
   job's own upsert (`ProcessAdsManagerReportsUpload::importRows`, same monotonic protection), `meta-ads:sync`
   command, schedule every minute that runs only on a configured slot with write mode on, Sync now route, CEO-only
   write mode route. Cases S-154.5, .6, .7, .9.
5. **Page** (frontend, medium): a "Facebook (Meta Ads API)" card on /ads_manager/report: Connect / not set up,
   connections with accounts, expiry, last sync, red Reconnect, Disconnect, Compare form + report, Sync now, write
   mode toggle (CEO), recent API runs and events. Case S-154.5/.6 view parts, .8, .11.

Access: every new route sits in the same `web, auth, allowed_ip` middleware as the upload and checks
`NavAccess::allows(role, '/ads_manager/report')` (the "Ads" link: Marketing, Marketing - OIC, CEO by seed), 404 otherwise;
write mode also requires CEO (403).
