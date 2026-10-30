# Plan 203: Mira RTS counts only the orders at the item's latest price

Spec: `docs/specs/203-mira-rts-latest-price.md`. Base 8506b25c. Threat model: no new input, no new route. The run is the
existing scheduled command over trusted tables; the only change is which orders it counts. Risk tier: medium.

## Design

- **Latest price** of a page+item = the COD of its newest order (newest `ts_date`, then highest `macro_output.id`)
  among the orders `RtsProjection::rows()` already reads (waybill set, ts_date in range), cleaned like the owner page
  does (₱, commas and spaces removed) and rounded to whole pesos. Only orders with a positive COD can be "the newest".
  Why the order's COD (ruling in the result): `macro_output` has no quantity column, so "COD per quantity" does not
  exist; the PRICE column of the owner page is the *mode* COD over the page's date range (the most common price, not
  the newest); the item setting (`page_item_settings.mode_cod_int` / promo) is written by staff when they save a Set
  RTS and can be days old. The order's COD is what the customer actually pays, and it is in the same row the
  projection already counts.
- **`RtsProjection::rows($from, $to, $asOf, $price = null)`**: counts per page+item *per price* in the one existing
  pass, then
  - `null` = all prices summed (today's behaviour; the owner page's Projected RTS column and the RTS-check feed keep
    it);
  - `'latest'` = only the bucket of the latest price;
  - `[itemKey => price]` = only that price for the listed keys, all prices for the others (accuracy of old day rows).
  Each row gets `price` (int or null) when filtered. With `$asOf` the newest order is the newest with ts_date ≤ that
  day, so a replay uses the price known then.
- **p(age)** stays per page+item (`learnTable` unchanged): it describes how J&T orders of that item resolve with age,
  not the price, and splitting it per price would push most items under `MIN_ITEM_SAMPLES` and onto the pooled rate.
- **Daily run** (`AnalystRtsDaily`): `rows(range, 'latest')`; the 21-day trend uses the same price per key; enough =
  `matched ≥ RtsProjection::MIN_MATCHED_FLAG` (30) at the latest price.
  - enough: as today, plus `price` in the day row; the reason reads `Auto 06:00: 40 order na may J&T status sa ₱199 (Sep 1–Oct 23) …`.
  - not enough: a day row `{d, from, to, price, m, est: null}`; the active auto (or pre-06:00) value and reason are
    deactivated, so the cell shows the existing "—" state; an analyst value typed today from 06:00 stays as today.
    No fallback to all prices.
  - accuracy: a day row with `price` is measured on that price's orders only; a day row without (written before this
    change) on all prices, as when it was written. Day rows with `est: null` and no analyst value that day are skipped.
- **Owner page** (`/owner/private`): from the newest day row per page+item (`AnalystRtsDaily::recent()`) the row gets
  `analyst_rts_price` and `analyst_rts_note` ("Latest price ₱X: only N orders, not enough yet"). The Mira RTS hover:
  `Mira RTS 67.0% (₱199 orders only): Auto 06:00 …` when the value is auto and its day row names a price; the "—" cell
  gets the note as its hover.
- **`--as-of`**: the replay table gains a Price column and prints "not enough (N)" for those items.
- **No migration, no new table, no index**: the extra work is in PHP inside the existing pass (one more column read,
  `mo.id`, `mo.COD`).

## Tasks

1. **backend, medium** — `RtsProjection::rows()` per-price buckets and latest price. Cases S-203.1.1, S-203.1.2,
   S-203.1.3, S-203.4.1, S-203.6.1 (projection part). Tests first in `tests/Feature/RtsProjection/LatestPriceTest.php`.
2. **backend, medium** — `AnalystRtsDaily` run, not-enough state, day-row price, accuracy by recorded price, replay
   table. Cases S-203.1.1 (run), S-203.2.1, S-203.5.1, S-203.6.1. Existing spec-197 tests updated where an item had
   fewer than 30 orders (listed under Story changes).
3. **frontend + controller, medium** — owner page fields and hovers. Case S-203.3.1, S-203.2.1 (cell part).
4. **docs** — stories, result, full `tests/Feature` run vs the base.

Tasks 1–3 touch shared files in sequence (not parallel-safe); one developer.
