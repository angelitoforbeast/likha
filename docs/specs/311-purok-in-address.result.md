# 311 · Result

## Plan
1. Base: full suite on a466e1bd before any code change (count below under Evidence).
2. One placeholder rule for addresses: `AddressLandmark::isPlaceholderAddress()`, next to the landmark rule it extends. It
   covers the landmark placeholders (N/A, none, wala pong nabanggit, "-"...), every text listed in the spec (n/a, "no
   address line 1 or landmark provided.", "no address line provided.", "no address line found.", "---", "not provided",
   "[not provided]", "[missing]", "(Wala pong namention)") and their close variants. A value in ( ) or [ ] is a placeholder
   only when its inside is one or says no/none/wala/missing/not, so "(Purok 7)" and "(P7) Pido St." stay real.
   `clean()` (the landmark rule) is not changed.
3. New `App\Services\OrderEncode\FormAddress` (one file, easy to cherry-pick): reads the form's House Number, Purok/Sitio,
   Address, Brgy, City and Province lines (last line of each label, optional "- ", like `Parse`), names the shape, and
   composes ADDRESS with `AddressLandmark::compose`: placeholder parts dropped, the form's own barangay, city and province
   taken off the ends of each part, the Purok/Sitio part dropped when another part already names the same purok (Purok 5 =
   Prk. 5 = Pk 5 = P-5 = Purok V), cap 250. `keepPurok()` adds a real Purok/Sitio to an AI line that lacks it.
4. `Encoder`:
   - STEP 3: a parts form (it has a House Number or Purok/Sitio line) with at least one real part → ADDRESS composed in code,
     no AI call (`address_line1` = `FORM_PARTS`). Otherwise the AI step as today, and a placeholder answer becomes empty.
   - STEP 5 (fill_missing): a placeholder answer for Address Line 1 becomes empty.
   - After the checker: a placeholder in D becomes empty; on the AI path a real Purok/Sitio missing from D is added.
   - Landmark: form case uses `AddressLandmark::compose(parts, landmark)` (Astra's way); AI case as today; "no_address" uses
     the placeholder rule instead of only "unknown".
5. `Checker::finalizeNameAddr`: a placeholder ADDRESS is not filled (so it rebuilds from the chat), and a placeholder answer
   is never written.
6. Tests first for every H/N/E case (FormAddress unit, placeholder unit, encoder runs with a fake AI, checker with a fake AI),
   then the code, then the full suite against the base, then this file, then push.

## What shipped

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
