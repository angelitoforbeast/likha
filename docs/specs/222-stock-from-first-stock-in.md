# Spec 222: stock from each item's first Nexus stock-in

New follow-up spec: Likha 222. The owner approved it on 2026-11-03 ("sige ikaw na bahala"). Plan phase, then stop and report.

## Setup
- Fetch vps. Live main should be 943a2d6c (spec 220); confirm it.
- Create a new worktree `.claude/worktrees/stock-222` on a new branch `feat/222-stock-from-first-stock-in` from vps/main. Copy vendor from stock-220.
- First commit: this brief as docs/specs/222-stock-from-first-stock-in.md, plus a Slice 222 section in qa/stories.md.
- Same rules as before: no push, no network, Http::fake only, no .env.

## Why
A read-only comparison on live data over 15 items found:
- The formula stock = Nexus stock-in + returned − shipped is right, but counting from the global 10-02 start is wrong for items that arrived earlier. SEAT COVER got 10,000 on 2026-09-29, so it shows 0 instead of about 6,508.
- The Nexus Restock page (ShopeeOrder::stock(), lines 373-452 in C:\mira\mira\projects\likha-nexus-rts-returns\app\Services\ShopeeOrder.php) starts each item at its first valid Stock In day. From that day it counts all stock-in, plus RTS since that day (any ship date), minus shipped since that day, with max(0).
- Several items have no Nexus stock-in at all: high quality ballpen, rubber coating spray, bed sheet strap, flexible clip phone holder, minimalist wallet. Any number for those is meaningless.
- Nexus `remaining` counts lots not yet released to the floor (a barcode is a whole box), not pieces on hand.

## Decisions (owner, 2026-11-03)
| Decision | Note |
|---|---|
| Nexus mode only, so Likha. **Per-item start = the item's first Nexus stock-in day.** Stock = all Nexus stock-in pieces from that day + J&T Returned signed on/after that day (any ship date, so late returns count) − shipped with first submission on/after that day. | "Stock in, shipped out, returned", counted from each item's first stock-in. |
| Show max(0, raw) as stock, and keep the "Count the stock first" flag when raw < 0. | Like Nexus. |
| An item with no Nexus stock-in ever shows "Not in Nexus": stock null, no negative number, no bilangin. Its order qty uses stock 0 and is marked. | Data missing, not stock zero. |
| Relabel the Nexus `remaining` cross-check "Nexus boxes not yet released". Add a tooltip saying it counts whole lots still on the shelf, not all pieces on hand, and drop the "difference" framing. | It's a different measure. |
| PO mode (no token, i.e. Incepxion) is unchanged. | |
| The returned source switch (jnt/nexus) is kept. The Nexus RTS source, when it comes, uses the same per-item start. | |

## Data and sync
- Nexus stock-in history must reach back before 10-02. Add a config `services.nexus.history_from` (default 2026-01-01).
- Do a one-time backfill through a command option, e.g. `nexus:sync-stock --history`, from history_from to the day before the first stored day.
  - Use range calls of 31 days or less. The range response's `stock_in` is a per-day list of {date, lots, pieces}, so split it into per-day rows. It takes about 10 calls; respect the 60/h limit and the 429 handling.
  - Write per-day rows with the same upsert. `remaining` is only meaningful "as of now", so don't write remaining for history rows.
- Hourly and daily runs are unchanged.
- First stock-in day per item = MIN(stock_date) where stock_in_pieces > 0, per bridged Likha key. Take the minimum across all Nexus names that bridge to that key.

## Stories (draft them as S-222.x with happy/negative/edge cases, then confirm)
- Per-item start:
  - SEAT COVER with 10,000 on 09-29 shows about 6,508 with the live-like fixture: 8,105 shipped since 09-29, 4,613 returned since 09-29.
  - An item first stocked in on 10-24 ignores shipments before 10-24.
- Late returns: a waybill shipped before the start and Returned after it counts.
- Not in Nexus: an item with no stock-in shows "Not in Nexus", no negative, and no bilangin.
- Clamp: raw −10 shows 0 with bilangin.
- The history backfill: range split into days, idempotent, 429-safe, never before history_from.
- The label and tooltip change.
- PO mode untouched.
- Performance: the shipped and returned queries now take a per-item start. Keep it to one grouped query, for example by joining a derived per-key start table, and measure it against the 0.8 s returned query from 220. Use the docker mysql:8.4 throwaway as before.
- Reproduce the 7 consistent items from the comparison (glow tape 211, christmas lights 4,126, reflective sticker 293, rgb strobe 371, vintage curtain 1,023, invisible belt 696, waterproof case 978) as a sanity fixture where feasible.

## Done when
- A plan was accepted. Every story passes. No new failures in the full suite against 943a2d6c.
- The result file includes deploy steps (on Likha, migrate by hand if there are migrations), the backfill command, and owner checks: SEAT COVER about 6,500, plus the "Not in Nexus" list.

Phase 1 now: plan in docs/plans/222-stock-from-first-stock-in.md, then STOP and report the design, files, risks and base counts.
