# Brief 192: an ad group can set every value a page can

## Why
Staff report that on an ad group row the Fill-Up Form, Loop 3 Form and Prompt 1/2/3 show "page value" and cannot be set. By spec 172's design only item name, price, quantity, picture, Main Flow, Main Flow 2, the flow and the sequence values were group values. The owner (2026-10-29): "dapat same na same yung ad group vs page" — a group must be able to set everything the page row can.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **Every value column of the page row is a group value**: all botfields shown on /chatbot/pages for a page (forms, loop forms, prompts, shop details, after-order fields, comment replies, item/price/qty/picture, flows, sequence values, any other value column), and the page's AI settings that are per page values (e.g. the AI prompt/model choice if they are page values). Replace the fixed VALUE_KEYS list with "every page value key except" a short exclusion list of **identity and switches**: Botcake ID/page id, tokens and connection keys, provider (Forbeast/Botcake), bot On/Off/active, the adgroup mode itself, and keys that are not values (history/meta). List the final included and excluded keys in the result. | Owner: same as the page. |
| 2 | Empty in a group = the page value (unchanged rule). A group value is used for a customer whose latest ad is in the group, exactly where the page value would be read — in flows, AI prompts, forms, order building, sequences and anything else that reads page values. Find every reader and make sure it goes through the same resolver; add tests for forms and prompts at least. | It must actually take effect. |
| 3 | The UI: every value cell on the group row gets the same edit (✎) as the page row, and the Edit dialog's group section can set them. No layout redesign in this spec. | Staff can set them. |
| 4 | No change to provider switching, sending, Page-based pages, or existing group data. | Safe. |

## Cases (S-192)
P1: .1 a group sets Fill-Up Form, Loop 3 Form and Prompt 1 → a customer whose latest ad is in the group gets the group's form text and the AI uses the group's prompt; a customer without a group gets the page's; .2 an empty group value falls back to the page; .3 every value column on the group row is editable (✎) and saves; .4 identity/switch keys (token, provider, On/Off, Botcake ID, mode) are refused for a group with a clear message; .5 Page-based page: group values ignored as today; .6 existing suites pass.

## Done when
P1 pass; `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` add no new failures vs eb34bc82 (record the baseline). Result with the included/excluded key lists, every reader changed or confirmed, deploy notes, and that it applies to Incepxion. End with "192: <done|blocked> — <one line>".
