# Brief 057: a new page sets itself up in Page settings

## Why

The owner, 2026-10-18: "paano pag nadagdagan ng page? tapos wala akong contact sayo? ganung setup dapat diba, iplan mo mabuti" and "diba pwedeng rekta test na yun? pagkaedit nung token at page?". Approved plan, in his words: "auto-test at auto-detect ng flow, field at template pagka-save ng Botcake ID o token, dropdown kapag marami, gabay kapag wala, Send test bago Ready, at hindi na babalik sa taas".

Today his team must type IDs by hand, and Mira had to look the IDs up. A check of live Likha (read-only) found these cases among its 26 incomplete pages:

- **No CHATBLAST flow in Botcake at all:** 19 pages.
- **A flow but no "GPT Input" custom field:** 4 pages.
- **Two candidate flows:** one is "CHATBLAST - SHIPPING STATUS" and the other "CHATBLAST - SHIPPING STATUS (post_order)". The post_order one is the one used.
- **Two candidate fields:** e.g. "GPT Input" and "GPT INPUT" / "GPT-INPUT", with different ids.

The Botcake public API gives three things:

- **Flows:** `GET /pages/{id}/flows` returns `data.flows[{id,name,is_removed}]` (one call, no paging).
- **Custom fields:** `GET /pages/{id}/custom_fields` returns `data[{id,name,...}]`.
- **Templates:** `GET /pages/{id}/message_templates` (spec 052 already reads them).

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **Auto-test and auto-detect on save.** When `botcake.page_id` or `botcake.token` is saved and the page has both, the system runs the token test at once (shared rate key, no retry, short timeout), with no button press. If the token is ok, it reads the flows, the custom fields and the templates. All of this happens in the save response, or in a queued job with the cell updating via the existing poll, within the existing time budget. A Botcake failure never blocks the save. | The owner: "rekta test na yun pagkaedit nung token at page" |
| **Auto-fill only when unambiguous and empty.** <br>• **Flow:** candidates are non-removed flows whose name contains "CHATBLAST" (case-insensitive). If exactly one name contains "(post_order)", pick it. Otherwise, if there is exactly one candidate, pick it. <br>• **Field:** candidates are custom fields whose name, normalised (lower case, spaces and hyphens removed), is "gptinput" or "message". If there is exactly one, pick it. <br>• **Template:** use spec 052's auto-pick. <br>• Write only into an EMPTY key, through `PageSettings::set`, logged as the system. Never overwrite a value a person saved. If a saved value is not among the current candidates, show a warning ("This flow is not a CHATBLAST flow in Botcake") but keep the value. | Safe automation |
| **Dropdown when there are several candidates.** The cell shows a select listing each candidate's name with its id (e.g. "CHATBLAST - SHIPPING STATUS (post_order) · 438700262"). Choosing one saves it like a typed value. Keep the select available next to a filled value too, so it can be changed, and keep typing an ID possible. Store the candidates per page so the select works without calling Botcake on every render. Refresh them on each test or detect. | The owner: "dropdown kapag marami" |
| **A guide when something is missing.** Short red lines with the fix, in the cell: <br>• no flow: "No CHATBLAST flow in Botcake. In Botcake, copy 'CHATBLAST - SHIPPING STATUS (post_order)' from another page to this page, then press Test." <br>• no field: "No 'GPT Input' custom field in Botcake. Create it, then press Test." <br>• no approved template: "No approved post_order template in Botcake." <br>A "How to set up a new page" link opens a short checklist (pop-up, not sticky) with these steps: 1) the Botcake ID and the token, 2) the automatic check, 3) copy the flow and field in Botcake if missing, 4) Send test to the Test PSID, 5) Ready. | The owner: "gabay kapag wala" |
| **Send test before Ready.** <br>• A page is **Ready**, and included in To send and auto, only when its setup is complete AND it has a confirmed send: either a successful Send test (write, read-back and flow all HTTP 200) to its Test PSID, or a successful real chatblast send. Pages that already have successful real sends count as tested; derive this from botcake_messages and store nothing extra for them. <br>• Record the last successful test as a system key, e.g. `botcake.test_ok` with a timestamp. It is reset when the flow, the field, the token or the page id changes. <br>• A complete but untested page shows amber "Needs a test send", with a **Send test** button in the row that sends a sample row's text (or a fixed sample text) to the Test PSID. If no Test PSID is set, the button says "Set a Test PSID first" and links to the existing Test PSID picker. <br>• **To send and auto exclude pages that are not Ready.** Their rows stay in To send greyed with the reason "Page not ready: needs a test send", or move to History as no_config with that reason. Choose the clearest option and say which. <br>• The summary chips gain "Needs test N". | The owner: "Send test bago Ready" |
| **No jump to the top.** Every row action on Page settings (Test, Sync, Cust., Orders, Send test, saves) works without a full page reload. Keep the scroll position and the open ▸ rows. Where a full navigation is unavoidable (e.g. Orders opens another page), keep it as a link and don't reload the grid. | The owner: "bakit bumabalik sa top, panget yun" |
| Works on Likha and Incepxion (same code). No migration unless unavoidable; prefer `page_settings` rows. All text is English. | Shared code, lean schema |

## Tests

- **Auto-test on save:** an ID or token save triggers the test, with ok and invalid tokens, and a Botcake failure doesn't block the save.
- **Auto-fill:** the pick rules (post_order preferred; a single candidate; ambiguous goes to the dropdown), never overwriting a value a person saved, and the warning for a saved value that isn't a candidate.
- **Dropdown:** saving a choice.
- **Guides:** each missing case.
- **Ready:** the gate in To send and auto; a successful test or a real send makes a page Ready; changing the flow resets it.
- **No reload:** the view uses fetch for all row actions.
- **Browser check:** add a page with a fake Botcake, watch it fill itself, pick from a dropdown, send a test, see it turn Ready, and check the scroll stays put.
- **Suite:** the full suite passes except the known ExampleTest and ImportStartTest.

## Out of scope

- Creating flows or fields in Botcake (the API can't).
- Incepxion-specific flow names. The CHATBLAST rule applies on both sites, and a page whose flow is named differently gets the dropdown or guide.

## Final message

- The commits.
- How auto-detect works.
- The Ready rule and what changed for existing pages on Likha. **Important:** list how many live pages would become "Needs test" (estimate from the code's rule; you have no live data, so describe the rule).
- The test lines and the suite result.
- The review findings.
- Anything the brief got wrong.
