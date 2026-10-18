# Brief 058: the item row's RTS / DEL / INT cell on /owner/private finds its J&T stats within ₱1

## Bug

This was found on live data and verified read-only.

**What goes wrong.** On /owner/private (Daily Summary, 2026-09-18 to 2026-10-17), the row Page A / "1 x VINTAGE CURTAIN" shows an empty RTS / DEL / INT cell, with only grey "—".

**Cause, in `app/Http/Controllers/OwnerPrivateController.php` `itemSummary()`:**
- Lines ~2192-2248 build `$jntStatsMap` keyed `page || item || ROUND(J&T cod)`.
- The row's price comes from the end date's `daily_page_primary_item.primary_mode_cod` (~1932/1944). On Oct 17 that was ₱298: 53 orders at 298 and 23 at 299.
- The lookup at ~2367-2368 is an EXACT price match. All of the item's J&T shipments sit in the 299 bucket (768 shipments: RTS 405, DEL 237, INT 126), so it misses and every value stays null.

**Other lookups already tolerate ₱1:** `$pickSettingLenient` (~2088), the included-days filter (~1899-1901) and the partial-date path (~2569).

**Comparison:** Page B works because her end-date price (299) has its own bucket.

## Fix (decided, do not reopen)

At the lookup, sum every `$jntStatsMap` bucket of the same page and item whose price is within ₱1 of the row's price, then compute the percentages and counts from the sums.
- The exact bucket alone keeps working as before.
- Apply the same ±1 rule anywhere else that cell or its tooltip reads `$jntStatsMap` with an exact price.
- Don't change the 60-day window or the campaign/expand numbers, which come from AdDeliveryStatus by waybill.

## Tests

1. An end-date price drifted by ₱1 (298 vs a 299 bucket) gets the 299 stats.
2. Two buckets (298 and 299) are summed.
3. A bucket ₱2 away is NOT included.
4. The existing behaviour with an exact match is unchanged.

The whole suite passes except the known `ExampleTest` and `ImportStartTest`.

## Final message

Report:
- the commits;
- the lines changed;
- the test lines and suite result;
- the review findings.

For Page A / VINTAGE CURTAIN over that range, the cell is expected to show RTS 52.7% (405), DEL 30.9% (237), INT 16.4% (126).
