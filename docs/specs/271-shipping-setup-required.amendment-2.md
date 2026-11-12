Amendment 271-2 (save verbatim as docs/specs/271-shipping-setup-required.amendment-2.md; part of the spec; list in the result).

| Change | Who / why |
|---|---|
| "+ Page" must NOT ask the user to pick or type a flow, a custom field or a template. The system finds them by the names in the Setup box (ChatblastSetupCheck) and decides by itself. | Owner, 2026-11-13: "di sila pipili ng flow at custom field a. System natin magrereject pag wala, or pag doble." |
| Reject (page not added, reasons listed) when any of these is **missing OR duplicated**: no flow / two or more flows named "UPDATE SHIPPING STATUS 2026" (not removed); no / two or more custom fields named "message"; no APPROVED "post_order" template with {{1}}. Use ChatblastSetupCheck's existing "exactly one" rule and its reasons; make sure the duplicate reasons read clearly, e.g. "2 flows named UPDATE SHIPPING STATUS 2026 in Botcake — keep only one" and "2 custom fields named message in Botcake — keep only one". | same |
| Add tests for the duplicate cases (two flows; two fields) being refused with those messages. | Mira's decision |
