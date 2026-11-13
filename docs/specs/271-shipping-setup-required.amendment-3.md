Amendment 271-3 (save verbatim as docs/specs/271-shipping-setup-required.amendment-3.md; part of the spec; list in the result).

| Change | Who / why |
|---|---|
| When "+ Page" is refused, the reasons must be clearly visible and stay: a red box inside the "+ Page" dialog listing every reason on its own line (missing or duplicated flow / custom field / template, or the Botcake error), which stays until the user closes the dialog or presses "Check again" — never only a toast or a message that disappears by itself, and never hidden behind a generic "failed". The dialog keeps the typed name/ID/token so "Check again" works. | Owner, 2026-11-13: "pero kita dapat ang error a." |
| The red "No shipping setup" badge on /chatbot/pages shows the same reasons (title tooltip AND visible on tap/click, e.g. a small expandable line under the page name), so it is readable on a phone too. The Problems panel line lists the reasons too (already in S3). | same |
| Test: the refused response renders all reasons; the badge renders the reasons text. | Mira's decision |
