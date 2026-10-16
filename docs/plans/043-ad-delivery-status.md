# Plan 043: J&T delivery status and RTS% per campaign, ad set and ad

Threat model: untrusted = the query string of the new GET endpoint (page names, dates) and stored order
text (`macro_output.PAGE`, `botcake_ad_id`); trusted = repo, config, the column settings the CEO saves.

## Findings from the code

- The page row (`OwnerPrivateController::data`, range mode) counts **shipped = distinct waybills that have
  a `from_jnts` row** (inner join to `_jnt_agg`). A waybill not yet in J&T is not shipped and counts in
  no column (the breakdown's "Hold" column counts those). The brief says such an order "counts as shipped
  only, as on the page row"; the page row does not count it at all. "Same rules as the page row" wins:
  it counts nowhere here either. Reported in the result.
- The expand lists campaigns of the page's anchor window (`anchor_first_date` .. end date, spend > 0),
  while the page row counts orders of the filter range (`start_date` .. `end_date`). The delivery
  columns use the filter range so they add up to the page row; the expand header says so.
- `data()` cannot run on sqlite (`::int` casts, `ALTER TABLE … ADD PRIMARY KEY`), so no test can call it.
- Indexes: `macro_output.botcake_ad_id` (`macro_output_botcake_ad_id_idx`), `macro_output.waybill`
  (column `->index()`), `macro_output (ts_date, PAGE)`, `from_jnts.waybill_number`
  (`from_jnts_waybill_number_index`), `ads_manager_reports.ad_id` (`ads_report_adid_idx`): all present.
  No migration.
- The `campaigns` column config is shared with `/ads_manager/campaigns` and `/item`, but each screen
  renders only the ids in its own JS column list; the new ids go only into `/owner/private`'s list.
  Non-CEO roles see only ids in their saved `visible_by_role`, so a new column would start hidden for
  Marketing and Marketing - OIC.

## Design

- `App\Services\AdDeliveryStatus`
  - `jntFlagsSql(string $driver)`: the four per-waybill flag expressions, moved out of `data()`
    (both its mysql and pgsql temp-table statements call it; text unchanged). Reuse, not a copy.
  - `forPages(array $pageNames, string $start, string $end)`: **one grouped query**: orders of those
    pages (`LOWER(TRIM(PAGE))`, as `data()`) with `ts_date` in range and a waybill, joined to
    `from_jnts`, grouped per page + waybill (flags = MAX, ad id = MAX non-empty `botcake_ad_id`),
    then per page + ad id: shipped = waybills, delivered / returned / for return / in transit = sums.
    One waybill counts once per page, so the buckets add up exactly to the page row.
    **One mapping query**: `ads_manager_reports` grouped by ad_id, ad_set_id, campaign_id for the ad
    ids found; the combination with the latest `day` wins. PHP rolls ads up to ad sets and campaigns;
    a null ad id or an id not in `ads_manager_reports` goes to `no_ad`. RTS% per bucket =
    (returned + for return) / shipped × 100.
- `GET /owner/private/ad-delivery?start_date&end_date&pages[]=` → `OwnerPrivateController::adDelivery`,
  same `checkAccess()` as `/owner/private` (CEO, Marketing - OIC, Marketing; 404 others), validated
  (dates `Y-m-d`, pages = 1..500 strings). Read-only. JSON keyed by the page name as sent.
- Columns `ad_delivered`, `ad_in_transit`, `ad_for_return`, `ad_returned`, `ad_rts_pct`: in the
  `campaigns` catalog (labels say `/owner/private`), in `DEFAULT_VISIBLE`, and in `/owner/private`'s
  `defaultCampaignsCols()`. `loadConfig` / `loadConfigMatrix`: an id the saved config never saw (absent
  from its saved `order`) follows `DEFAULT_VISIBLE` for the non-CEO roles too, so the new columns start
  visible for every role of `/owner/private` and can be hidden per role afterwards.
- Front end (`private.blade.php`, `_private_expand_inline.blade.php`): page expand and expand-all fetch
  the delivery map once (one request for all pages of an expand-all) next to the campaigns request;
  values are merged into campaign, ad set and ad rows by id (ad sets and ads use the stored map, no
  new request). After the campaign rows: campaigns that have orders but are not in the list (no spend
  in the anchor window, or listed under another page name) as delivery-only rows, then the
  "Walang ad id" line. A failed delivery request leaves the columns at "—" and the expand working.

## Tasks

| # | Task | Side | Risk | Cases |
|---|---|---|---|---|
| 1 | Service: flags reuse in `data()`, grouped query, mapping, rollup | backend | medium | S-043.1–S-043.6 |
| 2 | Endpoint + access + validation | backend | high (new route) | S-043.7 |
| 3 | Column catalog, defaults, role visibility of new ids | backend | medium | S-043.8 |
| 4 | Expand columns, merge, delivery-only rows, "Walang ad id" line | frontend | medium | S-043.9 |

Tasks 1–3 share the controller only through the route method; done in order, one commit each.
