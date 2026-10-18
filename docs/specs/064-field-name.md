# Brief 064: show the custom field name next to the Message field ID

The owner, looking at /chatblast/page-settings, said: "pashow din dito yung Custom Field Name". This is the Message field line in the Botcake cell, which today shows only the ID, e.g. "204121".

| Decision | Why |
|---|---|
| **Message field line.** Show the ID followed by the field's Botcake name, taken from the stored `botcake.field.message.options` list (062), for example "204121 · GPT-INPUT". | The owner |
| **ID not in the stored list.** Show "188764 · (not in Botcake list)" in amber. | Clear state |
| **No list stored yet.** Show the ID only, plus a grey "press Test for the name". | Clear state |
| **Keep the rest.** Editing (the dropdown from 062), saves, history and Ready all stay unchanged. All text is English, and there is still no horizontal scroll. | Keep what works |

**Tests**
- The name is shown.
- Not-in-list is shown.
- No-list is shown.

Run the PageSettings tests, commit, and report the sha.
