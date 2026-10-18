# Brief 055: the chatblast pages in English

## Why

The owner's standing rule, 2026-10-18: "laging english, sa usap lang natin pwede tagalog, pero mga website, table etc, english dapat". All text on his websites and apps is English. Tagalog is only for chatting with him.

The chatblast pages are partly in Tagalog today:
- `/chatblast`
- `/chatblast/campaigns` (Messages)
- `/chatblast/sequence` (Sequences)

Examples: "Mano-mano", "Bawat oras", "Araw-araw", "Mga mensahe", "Mga sequence", "Kunin ang mga bago", "naipadala (huling 30 araw)", "Lun–Lin", "i-reload", plus validation and error messages.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **Every UI string on the three chatblast pages is English**: titles, headers, labels, buttons, chips, statuses, confirmations, summaries, notices, validation and error messages returned by the chatblast controllers, empty states, tooltips, and the plain-language schedule summary. Examples: "Every day, every hour, 9 AM–9 PM"; "Manual (times are not followed)"; days "Mon–Sun"; "sent (last 30 days)"; "Fetch new"; "Reload the page: this changed in another tab". Use the same terms throughout: Messages, Sequences, Step, Version, Trigger, Schedule, Manual/Auto, On/Off, Cap. | Owner's rule |
| **Customer-facing texts stay exactly as they are**: the message versions in `chatblast_messages`, the campaign days, `botcake_messages.text`, and the page template line. Never touch stored texts or names in data. A display name built in code, such as "Sequence for In transit", is UI and becomes English. Stored message names such as "In transit · Day 3" are data and stay unchanged. | Only the UI changes |
| **Change-log entries already stored stay as they are.** New log labels shown in the UI may be English. | No data rewrite |
| Code comments stay as they are. Update the tests that assert the strings. No migrations. Behaviour is unchanged. | Text only |

## Tests

- Update the existing string assertions.
- Add one test per page that the main headings and buttons are English.
- Add one test that a validation error from the sequence update endpoint is English.
- The whole suite passes except the known `ExampleTest` and `ImportStartTest`.

## Out of scope

- Other pages.
- Customer texts.
- `/page-settings`, which spec 054 is changing on another branch. Don't touch it, or any 052/053 strings.

## Final message

Include:
- the commits;
- the pages and strings changed (counts per file);
- anything left in Tagalog and why;
- the test lines and suite result;
- the review findings;
- anything the brief got wrong.
