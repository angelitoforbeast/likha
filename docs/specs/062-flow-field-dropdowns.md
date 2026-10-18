# Brief 062: Flow ID and Message field are picked from a dropdown

The owner, looking at the live /chatblast/page-settings, said: "dropdown na din dapat to diba? even yung message field, tama ba?" He means the Flow ID and Message field cells. Today they are plain ID text inputs, and a dropdown appears only when there are several candidates.

| Decision | Why |
|---|---|
| **Editing the Chatblast Flow ID opens a dropdown.** It lists the page's non-removed Botcake flows, each shown by name and ID. The order is: CHATBLAST flows first (the "(post_order)" one marked "recommended"), then all other flows alphabetically. Picking one saves its ID through the existing save path, with the same marks, history and Ready reset. The last option is "Type an ID…", which switches to the current text input. | The owner |
| **Editing the Message field opens the same kind of dropdown.** It lists the page's Botcake custom fields by name and ID. "GPT Input" and "message" matches come first and are marked "recommended". The last option is "Type an ID…". | The owner |
| **The lists come from stored per-page system keys**, so rendering never calls Botcake. Extend 057's candidate keys to hold the FULL lists (all flows, all custom fields), not only the candidates. Refresh them on Test, Test all, and a Botcake ID or token save. If no list is stored yet, the dropdown shows "No list yet: press Test" plus "Type an ID…". A saved value that is not in the list shows "(not in Botcake list)". | No Botcake calls on render |
| **Display when not editing:** the ID with the name under it, as today. All text in English. Keep 060's sticky header, the grid scroll and the no-reload behaviour. | Keep what works |

Tests:
- The list order and the recommended marks.
- Picking an item saves its ID.
- "Type an ID…" still works.
- No stored list falls back to typing.
- No Botcake call on render.

Run the Chatblast and PageSettings tests. Do a browser check at 1366 px. Commit, and report the sha.
