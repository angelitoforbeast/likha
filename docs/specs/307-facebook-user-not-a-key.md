# 307 — "Facebook User" is not a customer

## Why
On 2026-11-18 on the Incepxion page "Elyse Abad", four orders from four different customers got another customer's name, phone and sometimes address (orders 1997748, 1998721, 1999317 and 1999592 all got "Mercedita Cabarubias", 09853015726; one more on ShadeShift Eyewear). All five customers show on Facebook as "Facebook User", the placeholder Facebook gives when a profile's name is hidden. Each order had its own PSID, but its Pancake conversation had not been imported yet when Astra Fix ran (it arrived about 22:23 that night).

`PancakeChat::forOrder` then fell back to the page + `full_name` = fb_name lookup (and then to the name alone), and took the newest "Facebook User" conversation on the page: Mercedita's. There are 428 "Facebook User" conversations on that page. Astra read her order form as this customer's earlier conversation ("the latest message overrides") and wrote her name and phone into the wrong rows. Checker 1 later showed the right chat ("Matched by PSID") beside the wrong name.

The owner wants this fixed on Likha and Incepxion. Fixing the five rows themselves is a separate, later step.

## Stories and cases
**FU-1 A placeholder name never finds a conversation (P1).** In `PancakeChat::forOrder`, a placeholder fb_name never reaches the page + name or name-only fallbacks. Placeholders are:
- empty;
- "Facebook User" in any case and spacing;
- any other placeholder you find in the data or code (e.g. "Facebook user", "Messenger User"), listed in one constant.

The PSID lookup is unchanged.
- H: an order with fb_name "Facebook User", a PSID whose conversation is not imported yet, and another customer's "Facebook User" conversation on the same page → no chat is returned (no hit), and nothing of the other customer reaches Astra or Checker 1.
- H: the same order once its PSID conversation exists → that conversation, "Matched by PSID".

**FU-2 An order with a PSID never borrows a conversation by name (P1).** When the order has a PSID and the PSID lookup finds nothing, `forOrder` returns no hit even for a real, non-placeholder name. An order without a PSID keeps the name fallbacks for real names, as today.
- H: PSID present, conversation missing, a real name "Ana Cruz" with another Ana Cruz conversation on the page → no hit.
- H: no PSID, real name → the page + name fallback still works (the label "Matched by page and name" as today).

**FU-3 The same rule wherever a placeholder name is used as a customer key (P1).** Treat placeholder fb_names as no name in:
- `OrderIdentity::find`: fall through to the phone rule, as an empty name does today;
- `LiveOrder::find`: no "empty PSID + same fb_name" OR branch for a placeholder;
- `BotcakePsidMatcher` (exact, sheet and reorder copy steps): never attach or copy a PSID by a placeholder name;
- any other lookup you find that keys a customer by fb_name, such as `MacroChecker::fetchPancakeChat` and the Astra `get_chat_history` tool if they bypass `forOrder`.

List each place changed.
- H: two "Facebook User" import rows on the same page and timestamp with different phones → two separate orders, not one.
- N: a real name keeps today's behaviour in every place.

**FU-4 Astra without history (P1).** When there is no conversation for an order, Astra works from the order's own input only, as it already does for an order with no chat. It never fills name, phone or address from text that isn't this customer's.
- H: the 18/11 case end to end (fake AI) → the row keeps its own phone 639913485181, and no "Mercedita" appears in the prompt given to the fake AI.

**FU-5 A read-only finder for rows already hit (P2).** Add an artisan command, `orders:placeholder-mixups --from= --to= [--page=]`. It reports, with no writes, the macro_output rows with a placeholder fb_name where:
- the FULL NAME, or the PHONE NUMBER, matches another row's customer on the same page and day;
- and that value does not appear in the row's own all_user_input / CXD form.

Each reported row shows its id, page, the suspect field and the row it likely came from. It prints counts and ids only, never full addresses. It is used to list what to fix; it repairs nothing.
- H: the 18/11 fixture → it lists the four wrong rows and not Mercedita's own row 1997706.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| A customer is found by PSID; a placeholder name is never a key | Facebook hides many names behind "Facebook User"; matching on it mixes customers |
| No history is better than someone else's history | A wrong name or phone on an order costs a failed delivery |
| Existing wrong rows are not changed by this spec | The owner approves a data fix separately, from the finder's list |

## Constraints
- Same stack and style. No migration.
- Keep comments in the file's language and style (Filipino where the file uses it).
- Keep the change easy to cherry-pick to Incepxion's line: no unrelated refactors, and say in Merge danger which files a cherry-pick touches.

## Threat model and risk
- Untrusted: customer names, chat text and form text.
- Trusted: staff, the import pipeline.
- Tier: medium. It changes which conversation feeds Astra and how imports match orders, so test the real-name paths stay the same.

## Allowed commands
composer install; vendor/bin/phpunit with Herd's PHP (targeted files, then the full suite with -d memory_limit=4G); php artisan list; git add, git commit, git push -u origin feat/307-facebook-user-not-a-key.

## Done when
1. A test for every H and N case passes, and the full suite adds no new failures against origin/main f964a6eb (state the base count); list any red.
2. The result lists every changed place (FU-3) and the placeholder constant.
3. The branch is pushed, with a Merge danger section covering both sites, the cherry-pick file list, and how to revert.

## Out of scope
Changing existing rows; Pancake import timing; Checker 1 UI; the TeleSMS app.

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and report.
