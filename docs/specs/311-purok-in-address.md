# 311 — The customer's purok stays in the address

## Why
On 2026-11-21 staff reported orders in PROCEED whose address line reads "(Wala pong namention)" or has no purok, although the customer's details have one. Encoders do not re-check PROCEED rows, and in the provinces a rider finds a customer by purok when the call does not go through.

A read-only check of orders created 2026-10-01 to 2026-11-21 found:
- 20,843 of the 45,478 orders whose input mentions a purok lost it in ADDRESS; 99% are PROCEED.
- The J&T label (from_jnts.address) has no purok either.
- In 94.5% of these rows the order form's "Purok/Sitio:" line was filled, and only 11.6% kept it.
- Separately, 3,427 rows have a placeholder ADDRESS: "n/a" 1,575, "no address line 1 or landmark provided." 256, "no address line provided." 248, "no address line found." 197, "---" 89, "not provided" 70, "[not provided]" 53, "[missing]" 47 and similar.

Cause:
- The encoder's address_line1 step asks the AI for "Address Line 1 including landmark" from the form text, and the AI drops the Purok/Sitio line.
- The checker's finalizeNameAddr is the only step that reads the whole chat (all_user_input). It skips whenever ADDRESS is non-empty and not "unknown". Placeholders therefore block it: 1,499 of 1,500 live rows checked were SKIP:ALREADY_FILLED.

## Stories and cases
**PA-1 The form's address parts are kept, built in code (P1).** When the order form has real values in House Number, Purok/Sitio or Address:
- ADDRESS is composed in code from those parts, the way Astra composes it (AddressLandmark::compose), with the landmark added as today;
- there are no duplicates, no barangay, city or province, and the 250-character cap still applies.

The AI address extraction is used only when the form has none of these parts, or only an old one-line "Address:" field. In that AI case, a real Purok/Sitio value on the form that the AI's line lacks is added in code. Record which form shapes exist and how each is read.
- H: House Number "12", Purok/Sitio "Purok 5", Address "Rizal St", Landmark "malapit sa chapel" → "12, Purok 5, Rizal St, near chapel" (or what compose gives), and no AI call for the address.
- H: the AI line already has "Prk. 5" and the form's Purok/Sitio is "Purok 5" → the purok appears once (record how you match).
- N: a placeholder Purok/Sitio ("N/A", "-", "(Wala pong na-mention)", "none") → nothing added.
- E: a form without a Purok/Sitio line → the same output as today (regression test with today's expected value).

**PA-2 A placeholder is never an address (P1).** One placeholder rule for addresses, in one place, covering every text listed under Why plus AddressLandmark's existing placeholders.
- The encoder never writes a placeholder into ADDRESS; it writes empty.
- finalizeNameAddr treats a placeholder ADDRESS as not filled, so it rebuilds the address from the chat.
- H: ADDRESS "n/a" with a purok and a street in the chat → the checker writes the real address line (fake AI).
- N: a real address that starts with "(", e.g. "(P7) Pido St.", is not a placeholder.
- N: a real ADDRESS and a filled name → still SKIP:ALREADY_FILLED, with no extra AI call.

**PA-3 The same rule wherever ADDRESS is written by the encode (P1).** Apply PA-1 and PA-2 in every place the encode writes ADDRESS (fill_missing and any other); list each place in the result. Astra's own composition stays as it is.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| ADDRESS is built in code from the form's parts; the AI only fills what the form lacks | The AI drops the purok; Astra's code composition already keeps it |
| A placeholder counts as an empty address | It blocks the checker and reaches the J&T label |
| Existing rows are not changed by this spec | Re-encoding live, mostly shipped orders is a separate decision |
| The same fix goes to Incepxion | Both sites run the same encode code |

## Constraints
- Same stack and style. No migration.
- Keep comments in the file's language and style (Filipino where the file uses it).
- Keep the change easy to cherry-pick to Incepxion's line: no unrelated refactors; say in Merge danger which files a cherry-pick touches.

## Threat model and risk
- Untrusted: customer chat and form text, the AI's answer.
- Trusted: staff, the import pipeline.
- Tier: medium. It changes the address of every new order and its J&T label, so test that the real-address paths without a purok stay the same.

## Allowed commands
composer install; vendor/bin/phpunit with Herd's PHP (targeted files, then the full suite with -d memory_limit=4G); vendor/bin/pint --test on changed files if the repo uses pint; php artisan list; git add, git commit, git push -u origin feat/311-purok-in-address.

## Done when
1. A test for every H, N and E case passes, and the full suite adds no new failures against a466e1bd (state the base count; known failures include ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest); list any red.
2. The result lists every changed place (PA-3), the placeholder list, and a before/after table of at least 15 invented forms of the real shapes (Astra block, old one-line form, with and without purok, placeholders).
3. The branch is pushed, with a Merge danger section covering both sites, the cherry-pick file list, and how to revert.

## Out of scope
Changing existing rows; Astra's prompt and composition; the J&T label layout; the landmark rule; the chat import.

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and report.
