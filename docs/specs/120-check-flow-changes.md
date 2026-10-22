# Brief 120: the Check column checks the FLOW, and Changes shows a cleared value and only real auto-switches

A live page had its FLOW set by mistake to a flow that is a step of a sequence, and nothing warned. On /chatbot/pages → Changes: (a) setting a value to "— none —" leaves no row (the setting row is only deactivated with `replaced_at`), so the history ends at the old value; (b) a provider row written by the system long before the auto-switch feature is labelled "auto-switch" although no auto-switch happened.

## Decisions
- Check column (`ChatbotPageCheck`) gets FLOW rules for a page whose bot is On: (1) FLOW empty → warning "No flow: the bot only receives chats"; (2) FLOW names a flow that does not exist or is not published → error "Flow not found / not published"; (3) FLOW names a flow used as a step of any sequence (`chatbot_sequence_steps.flow_id`) → error "Flow X is a sequence step, not a main flow".
- Changes: a cleared value shows as a row "<old> → (none)" with who and when. Read it from the existing rows (a deactivated row with `replaced_at` and no newer row for that page/provider/key); who must be stored (nullable column allowed, migration nullable only).
- "auto-switch" label only for real switches: a provider change is labelled "auto-switch" only when there is a matching `chatbot.auto_switch` event for that page at that time; other system rows show "system".

## Done when
- Tests: each FLOW rule fires on the right case and not otherwise; a cleared FLOW shows "→ (none)" in Changes with time; an old system provider row is labelled "system" and a real auto-switch row "auto-switch".
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
- Result: files, migration (if any), commits.
