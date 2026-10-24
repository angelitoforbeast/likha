# Brief 142: the flow builder shows only one "Encode order" button

## Why
In the flow editor's "Add:" bar (`resources/views/chatbot/editor.blade.php` ~line 89) the legacy button `data-add="encode_order"` has the `hidden` attribute, but it still shows (the button's CSS class sets a display value that overrides `hidden`), so the owner sees two "Encode order" buttons and can add the legacy step by mistake.

## Decisions
| Decision | Why |
|---|---|
| The legacy `encode_order` button is not rendered in the palette at all (remove it from the Add bar, or make sure it is truly not displayed, e.g. inline `style="display:none"` if any code still needs the element). Existing legacy nodes keep loading, showing as "Encode order (legacy)", running and editing as now. | One "Encode order" button: the new block. |

## Done when
- A test (Blade render) shows exactly one visible "Encode order" add button (`data-add="encode_macro"`) and no displayed `data-add="encode_order"` button; legacy node rendering/editing tests still pass.
- `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/OrderEncode` adds no new failures.
- Result + commits; end with "142: <done|blocked> — <one line>".
