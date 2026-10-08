# Result: spec 018 Astra identifies the J&T address like the classic checker

> Committed beside the spec: names no people and no decision ids.

**Status:** done
**Date:** 2026-10-08
**Branch / PR:** `feat/018-astra-address-rules` (base `90b900f`, head: the commit that carries this file), no PR, not pushed
**Preview or run link:** n/a (no environment file in the worktree, the application cannot be started here)

## Summary

Astra's decision rules now live in one pure function, `AstraAddressRules::decide()`, with two rule
sets. With the switch off (the default) it is today's code moved, and the request to the model,
the fields written and the log are those of the base commit, apart from a new `replay` block in
each log. With the switch on (a CEO-only box in the Checker 1 settings) the program maps Astra's
own form to the J&T list when the model gave no usable line, the guard accepts a near match
through a new text check (`AstraBarangayMatcher`) that never bridges numbers or sibling names, a
cancel, an inquiry, a same-date duplicate phone and a "found no list line" flag no longer hold a
complete row, and every other hold stays. A read-only command, `astra:replay-address-rules`,
replays a night's stored answers through the new rules and prints counts and order ids only.
The classic checker is untouched except for one optional argument on the shared gate.

## What amendment 018-1 changed, as applied

1. One rule function with both rule sets; the switch-off code was moved, not changed.
2. The gate is handed in by the caller; the checker and the replay pass the same one.
3. Sibling barangays: built (rule 9 of the text check), with added cases in S-26.
4. A city written without "city" and without a known province makes no line; "Naga" and "Danao"
   are in S-24.15. The review widened this (see "Rulings", mapper).
5. The program writes its labels only as a whole line; a partly valid line of the model is written
   as today.
6. Under the new rules each form value goes into the customer-details block as one line of at
   most 250 characters; the model's reason and issues are made one line too. Switch off: as today.
7. `list_crc` is in the `replay` block.
8. `docs/night-run.md` has the new section (the switch, the command, how to read its output).
9. A single letter followed by a dot is an initial.

The three additions to "Done when" are the sections "Rows Astra proceeds today that the new rules
would hold", "The accepted weakness of the near match" and "The replay command" below.

## Case table

Both test files, final run on the head: `AstraAddressRulesTest.php` `OK (89 tests, 1591
assertions)`; `AstraReplayCommandTest.php` `OK (19 tests, 472 assertions)`;
`RunRowCharacterizationTest.php` `OK (5 tests, 53 assertions)`. Every row below passed in those
runs. Tests without a class name are in `AstraAddressRulesTest`.

| Case | Test | Result |
|---|---|---|
| S-22.1 | `test_S_22_1_without_a_setting_row_the_switch_is_off` | pass |
| S-22.2 | `test_S_22_2_the_ceo_turns_the_new_rules_on_and_off_in_the_settings` | pass |
| S-22.3 | `test_S_22_3_another_role_cannot_change_the_switch_and_does_not_see_it` | pass |
| S-22.4 | `test_S_22_4_only_the_exact_stored_value_1_turns_the_switch_on` | pass |
| S-22.5 | `test_S_22_5_a_post_without_the_marker_leaves_the_switch_as_it_is` | pass |
| S-22.6 | `test_S_22_6_text_and_extra_keys_that_ask_for_the_new_rules_change_nothing_and_do_not_turn_the_switch_on` | pass |
| S-22.7 | `test_S_22_7_an_unreadable_settings_table_means_rules_off_and_the_row_runs_as_today` | pass |
| S-22.8 | `test_S_22_8_the_browser_and_the_night_job_use_the_new_rules_and_a_row_after_the_untick_uses_the_old` | pass |
| S-23.1 | `test_S_23_1_the_log_of_an_astra_row_has_todays_keys_plus_replay_with_the_switch_off`, and the existing Astra tests unchanged but for the `replay` key in `RunRowCharacterizationTest::test_astra_engine_returns_the_same_json_and_log_row` | pass |
| S-23.2 | `test_S_23_2_ten_stored_answers_give_the_results_captured_before_the_change` | pass |
| S-23.3 | `test_S_23_3_the_request_the_calls_and_the_cost_of_the_ten_rows_are_the_captured_ones` | pass |
| S-23.4 | `test_S_23_4_the_classic_engine_gives_the_same_json_and_log_row_with_the_switch_row_present` | pass |
| S-23.5 | `test_S_23_5_the_shared_gate_and_the_classic_mapper_matcher_and_text_check_return_the_captured_arrays` | pass |
| S-23.6 | `test_S_23_6_the_program_adds_no_model_call_of_its_own_with_the_switch_row_present` | pass |
| S-24.1 | `test_S_24_1_an_empty_model_line_is_mapped_from_the_form_and_the_row_proceeds` | pass |
| S-24.2 | `test_S_24_2_a_model_line_that_is_not_on_the_list_gives_way_to_the_line_from_the_form` | pass |
| S-24.3 | `test_S_24_3_an_invalid_barangay_of_a_valid_model_city_is_mapped_inside_that_city` | pass |
| S-24.4 | `test_S_24_4_cotabato_city_gets_the_lists_province_and_the_roman_numeral` | pass |
| S-24.5 | `test_S_24_5_sta_is_expanded_and_the_parenthesis_of_the_label_is_ignored` | pass |
| S-24.6 | `test_S_24_6_a_program_line_fills_the_six_fields_the_evidence_the_block_and_the_log` | pass |
| S-24.7 | `test_S_24_7_a_complete_valid_model_line_is_used_and_the_mapper_is_not` | pass |
| S-24.8 | `test_S_24_8_a_city_of_several_provinces_without_a_province_makes_no_line` | pass |
| S-24.9 | `test_S_24_9_an_empty_city_or_an_empty_barangay_in_the_form_makes_no_line` | pass |
| S-24.10 | `test_S_24_10_a_barangay_that_matches_no_label_or_two_labels_is_not_written_and_no_call_is_added` | pass |
| S-24.11 | `test_S_24_11_low_confidence_is_never_mapped` | pass |
| S-24.12 | `test_S_24_12_a_model_city_or_province_that_differs_from_the_forms_makes_no_line` | pass |
| S-24.13 | `test_S_24_13_a_mapped_row_costs_the_calls_and_the_money_of_a_model_line_row` | pass |
| S-24.14 | `test_S_24_14_a_city_with_n_tilde_is_mapped_and_a_10000_character_value_makes_no_line_and_is_cut_in_the_block` | pass |
| S-24.15 | `test_S_24_15_naga_and_danao_without_a_province_make_no_line` | pass |
| S-25.1 | `test_S_25_1_a_barangay_the_customer_spelled_a_little_differently_is_written_as_a_near_match` | pass |
| S-25.2 | `test_S_25_2_abbreviations_double_spaces_a_non_breaking_space_and_capitals_are_confirmed` | pass |
| S-25.3 | `test_S_25_3_each_of_the_three_sources_alone_confirms_the_barangay` | pass |
| S-25.4 | `test_S_25_4_a_barangay_in_none_of_the_sources_at_medium_confidence_is_not_written` | pass |
| S-25.5 | `test_S_25_5_a_part_of_the_name_and_a_three_letter_near_miss_are_not_confirmed` | pass |
| S-25.6 | `test_S_25_6_an_earlier_block_written_by_astra_does_not_confirm_the_barangay` | pass |
| S-25.7 | `test_S_25_7_high_confidence_accepts_a_barangay_that_is_in_none_of_the_sources` | pass |
| S-25.8 | `test_S_25_8_the_forms_own_wording_confirms_only_when_it_maps_to_that_very_label` | pass |
| S-25.9 | `test_S_25_9_a_chat_of_300000_characters_gives_the_result_of_the_short_chat` and `test_S_25_9_a_pancake_query_that_throws_leaves_the_guard_without_the_history` | pass |
| S-25.10 | `test_S_25_10_invalid_utf8_does_not_throw_and_a_bad_byte_is_a_break_between_words` | pass |
| S-25.11 | `test_S_25_11_a_barangay_named_like_its_town_needs_more_than_one_mention_of_the_name` | pass |
| S-26.1 | `test_S_26_1_a_one_digit_numbered_barangay_is_confirmed_by_its_number` | pass |
| S-26.2 | `test_S_26_2_roman_and_arabic_forms_of_the_same_number_are_equal` | pass |
| S-26.3 | `test_S_26_3_every_way_of_writing_the_barangay_word_before_the_number_is_confirmed` | pass |
| S-26.4 | `test_S_26_4_another_number_is_never_confirmed_by_phrase_or_by_near_match` | pass |
| S-26.5 | `test_S_26_5_a_number_is_compared_as_a_whole_number` | pass |
| S-26.6 | `test_S_26_6_no_match_across_a_digit` | pass |
| S-26.7 | `test_S_26_7_a_number_or_letter_the_label_does_not_have_is_never_bridged` | pass |
| S-26.8 | `test_S_26_8_a_number_alone_counts_only_when_attached_to_a_barangay_word` | pass |
| S-26.9 | `test_S_26_9_the_mapper_picks_the_exact_number_and_never_a_neighbour` | pass |
| S-26.10 | `test_S_26_10_a_number_on_the_next_line_is_never_joined_to_the_name` | pass |
| S-26.11 | `test_S_26_11_a_street_initial_after_a_comma_is_not_a_suffix_letter` | pass |
| S-26.12 | `test_S_26_12_digits_written_apart_are_not_one_number` | pass |
| S-26.13 | `test_S_26_13_a_name_that_is_nearer_to_another_barangay_of_the_city_is_not_confirmed` | pass |
| S-26.14 | `test_S_26_14_a_longer_barangay_name_of_the_city_around_the_hit_is_not_confirmed` | pass |
| S-26.15 | `test_S_26_15_an_initial_is_neither_a_number_nor_a_suffix_letter` | pass |
| S-26.16 | `test_S_26_16_a_siblings_number_or_letter_written_another_way_is_never_bridged` | pass |
| S-27.1 | `test_S_27_1_a_cancel_with_everything_valid_proceeds_and_the_cancel_stays_visible` | pass |
| S-27.2 | `test_S_27_2_an_inquiry_with_everything_valid_proceeds_and_the_inquiry_stays_visible` | pass |
| S-27.3 | `test_S_27_3_a_cancel_or_an_inquiry_with_an_incomplete_line_keeps_its_code_and_the_gate_is_not_run` | pass |
| S-27.4 | `test_S_27_4_a_cancel_that_fails_the_gate_or_is_held_keeps_the_cancel_code_and_the_evidence_names_why` | pass |
| S-27.5 | `test_S_27_5_an_unclear_intent_holds_the_row_as_today` | pass |
| S-27.6 | `test_S_27_6_a_cancel_gets_the_cancel_code_and_the_gate_is_not_run` | pass |
| S-27.7 | `test_S_27_7_a_night_cancel_row_is_skipped_when_a_person_sets_status_during_the_call_and_proceeds_when_nobody_did` | pass |
| S-28.1 | `test_S_28_1_a_same_date_duplicate_phone_does_not_hold_the_row_and_the_duplicate_query_is_not_run` | pass |
| S-28.2 | `test_S_28_2_a_same_date_duplicate_phone_holds_the_astra_row` | pass |
| S-28.3 | `test_S_28_3_the_classic_engine_still_reports_the_duplicate_with_the_switch_row_present` | pass |
| S-28.4 | `test_S_28_4_a_blank_short_long_or_dummy_phone_still_holds_the_row` | pass |
| S-28.5 | `test_S_28_5_every_other_gate_rule_holds_the_row_with_todays_code` | pass |
| S-28.6 | `test_S_28_6_the_log_says_whether_the_duplicate_phone_was_checked` | pass |
| S-29.1 | `test_S_29_1_the_models_own_flag_holds_a_row_with_a_valid_model_line` | pass |
| S-29.2 | `test_S_29_2_a_list_only_flag_does_not_hold_a_row_the_program_mapped_and_the_customers_text_confirms` | pass |
| S-29.3 | `test_S_29_3_a_program_line_the_guard_does_not_confirm_loses_its_barangay` | pass |
| S-29.4 | `test_S_29_4_a_valid_line_already_in_the_row_is_checked_but_does_not_proceed_without_a_line_from_astra` | pass |
| S-29.5 | `test_S_29_5_low_confidence_and_a_model_line_that_is_not_in_the_text_is_held_by_the_guard` | pass |
| S-29.6 | `test_S_29_6_a_form_phone_of_9_digits_is_not_written_and_the_row_is_held` | pass |
| S-29.7 | `test_S_29_7_model_failures_and_a_status_set_by_a_person_are_handled_as_today_with_the_switch_row_present` | pass |
| S-29.8 | `test_S_29_8_five_night_rows_end_as_today_and_with_the_switch_on_only_the_intended_rows_change` | pass |
| S-30.1 | `test_S_30_1_every_answered_row_logs_a_replay_block_with_the_fixed_keys_and_types` | pass |
| S-30.2 | `test_S_30_2_the_replay_block_holds_only_booleans_integers_and_fixed_words` | pass |
| S-30.3 | `test_S_30_3_the_replay_block_keeps_the_models_own_flag_apart_from_the_programs` | pass |
| S-30.4 | `RunRowCharacterizationTest::test_astra_engine_returns_the_same_json_and_log_row` (the whole log) and `test_S_30_4_the_characterisation_row_gains_the_replay_key_between_searches_and_summary_and_nothing_else` | pass |
| S-30.5 | `test_S_30_5_a_row_without_a_usable_answer_has_no_replay_block` | pass |
| S-31.1 | `AstraReplayCommandTest::test_S_31_1_the_night_option_and_the_step_option_print_the_same_report` | pass |
| S-31.2 | `AstraReplayCommandTest::test_S_31_2_wrong_options_give_one_fixed_line_and_exit_code_1` | pass |
| S-31.3 | `AstraReplayCommandTest::test_S_31_3_the_command_only_runs_select_statements_and_changes_no_table_and_no_file` | pass |
| S-31.4 | `AstraReplayCommandTest::test_S_31_4_the_command_works_without_an_api_key_and_sends_nothing` | pass |
| S-31.5 | `AstraReplayCommandTest::test_S_31_5_no_text_of_a_customer_or_the_model_reaches_the_output_or_a_log_line` | pass |
| S-31.6 | `AstraReplayCommandTest::test_S_31_6_an_empty_night_and_rows_without_a_log_an_order_or_the_replay_block_are_counted_not_errors` | pass |
| S-31.7 | `AstraReplayCommandTest::test_S_31_7_only_the_rows_of_that_nights_step_are_counted` | pass |
| S-31.8 | `AstraReplayCommandTest::test_S_31_8_a_night_of_1500_rows_is_read_in_chunks` | pass |
| S-31.9 | `AstraReplayCommandTest::test_S_31_9_the_log_the_night_row_points_to_is_used_and_the_switch_does_not_change_the_report` | pass |
| S-32.1 | `AstraReplayCommandTest::test_S_32_1_six_held_rows_give_the_expected_report` | pass |
| S-32.2 | `AstraReplayCommandTest::test_S_32_2_each_held_row_has_two_results_and_everything_is_never_more_than_the_address_rules` | pass |
| S-32.3 | `AstraReplayCommandTest::test_S_32_3_rows_staff_set_to_proceed_are_compared_with_the_line_of_the_new_rules` | pass |
| S-32.4 | `AstraReplayCommandTest::test_S_32_4_rows_staff_set_to_cannot_proceed_are_counted_apart` | pass |
| S-32.5 | `AstraReplayCommandTest::test_S_32_5_rows_astra_proceeded_that_night_still_proceed_and_one_that_would_not_is_listed` | pass |
| S-32.6 | `AstraReplayCommandTest::test_S_32_6_rows_whose_texts_or_list_changed_are_counted_as_not_rebuilt_exactly` | pass |
| S-32.7 | `AstraReplayCommandTest::test_S_32_7_the_first_blocking_reason_partitions_the_held_rows` | pass |
| S-32.8 | `AstraReplayCommandTest::test_S_32_8_an_older_log_with_the_models_own_flag_is_reported_strict_and_lenient` | pass |
| S-32.9 | `AstraReplayCommandTest::test_S_32_9_the_replay_decides_each_stored_answer_as_the_checker_does_with_the_switch_on` | pass |
| S-33.1 | `test_S_33_1_sql_text_in_every_untrusted_place_is_only_ever_bound_and_makes_no_line`, `AstraReplayCommandTest::test_S_33_1_replay_sql_text_in_every_untrusted_place_is_only_ever_bound` | pass |
| S-33.2 | `test_S_33_2_ten_garbled_forms_give_an_exact_list_line_or_none` | pass |
| S-33.3 | `test_S_33_3_text_and_extra_keys_that_ask_for_proceed_change_nothing` | pass |
| S-33.4 | `test_S_33_4_a_wrong_type_in_the_answer_ends_as_a_failed_or_held_row_with_the_fixed_message` (today's behaviour) | pass |
| S-33.5 | `test_S_33_5_one_numbered_barangay_repeated_over_200000_characters_gives_the_result_of_the_short_text` | pass |
| S-33.6 | `test_S_33_6_human_kind_is_read_as_one_of_two_exact_words_and_any_other_value_or_type_counts_as_missing` (switch on) and `test_S_30_2_the_replay_block_holds_only_booleans_integers_and_fixed_words` (switch off) | pass |

## Story changes

- **S-24.15 added:** "Naga", "Danao" and (from the review) "San Carlos City" without a province
  make no line. **S-24.8 gained rows** from the review: "Jaro, Iloilo City" with Iloilo; "Samal"
  with Davao del Norte; "Bagumbayan, Taguig"; a province the list does not know ("Ilo-ilo",
  "Iloilo Province", "Western Visayas", "DDN"); bare "Baguio" with a wrong province.
- **S-25.10 added, then reworded:** invalid UTF-8 never throws; a bad byte is a break between
  words, so it never joins a name to a number, and a name beside it is still read. (The first
  wording, "not confirmed", was broader than what is right: a cut emoji should not void a chat.)
- **S-25.11 added:** a barangay named like its city needs the name twice in one source, or a
  barangay word beside it (found by the review: "Malibcong, Abra" alone confirmed MALIBCONG).
- **S-26.10 to S-26.16 added:** the cases of the spec review and of the two fix loops of the text
  check (a number on the next line, list numbering, an initial after a comma, digits written
  apart, a nearer sibling name, a longer sibling name before or after the hit also with a small
  typo, initials, and a sibling's number written another way: lowercase L for Roman, glued,
  behind a dash, bracket or slash, in words, with "No.", a leading zero, an ordinal, "11" for II,
  two numbers on one line).
- **S-30.1:** the key list includes `list_crc`. **S-32.7:** the blocking reasons include "a
  required field is blank" and, for rows that could not be replayed, "not replayed", so that the
  parts sum to the held count.
- **S-33.6 added, Then rewritten:** `human_kind` as an array, a number or null with the switch on
  counts as missing and the row is decided as if the key were absent; nothing throws. (The first
  wording said "failed row"; failing a whole row for one optional key would hold good orders.)
- The Type column (happy, negative, edge) of the slice was assigned by the developer; the spec
  has none.

## Done-when checklist

- [x] Every case of S-22 to S-33 passes, each with a test named after it: the case table (106
      cases, 0 open).
- [x] A red line per feat slice, watched before the code, and the characterisation values captured
      on the base in a test-only commit: see "Tests" (red lines; commit `3b7c11e` is the
      test-only commit, the first after the plan).
- [x] With the switch off the request, the fields and the log (apart from `replay`) are those of
      the base for the ten captured answers: `test_S_23_2_…` and `test_S_23_3_…` compare with
      `tests/Feature/NightRun/fixtures/astra_018_base.php`; `git diff 3b7c11e..HEAD` on that file
      shows additions only (the `'switch on'` entry of S-29.8).
- [x] `git diff 90b900f --stat` lists only allowed files: `app/Services/AstraEncoder.php`,
      `app/Services/MacroChecker.php`, three new classes (`AstraAddressRules`,
      `AstraBarangayMatcher`, `Console/Commands/AstraReplayAddressRules`), the settings controller
      and view, four files under `tests/`, `qa/stories.md`, `TODO.md`, `docs/night-run.md` (allowed
      by the amendment), the spec, this result and the plan. No diff under `routes/`,
      `database/`, on `composer.json`, `composer.lock` or the list file. The full diff of
      `MacroChecker.php`:

      ```
      -    public function validateRow($row, array $final, array $maps): array
      +    public function validateRow($row, array $final, array $maps, bool $checkDuplicatePhone = true): array
      …
      -        elseif (!isset($refs['whitelist'][$phone]) && ($dupes = $this->duplicatePhoneRows($row, $phone)) !== [])
      +        elseif ($checkDuplicatePhone && !isset($refs['whitelist'][$phone]) && ($dupes = $this->duplicatePhoneRows($row, $phone)) !== [])
      ```

      (two changed lines; no visibility change was needed).
- [x] The replay command runs SELECT only and sends nothing; no marker text in its output:
      `test_S_31_3_…` (query listener, every statement starts with `select`, six tables compared
      before and after), `test_S_31_4_…`, `test_S_31_5_…`.
- [x] `php.bat -l` on every changed PHP file: "No syntax errors detected" for all eleven.
- [x] The full suite, plain PHPUnit. Before (base `90b900f`): `Tests: 589, Assertions: 6575,
      Errors: 1, Failures: 1, Skipped: 3.` After (head): `Tests: 697, Assertions: 8638, Errors: 1,
      Failures: 1, Skipped: 3.` The same two red tests both times:
      `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`
      and `ExampleTest::test_the_application_returns_a_successful_response`. No new failure.
- [x] Adversarial review by a separate reviewing agent of the text check, the rule function and
      the command: the table under "Tests".
- [x] The exact command for a night on the server and each line of its output: "The replay
      command".
- [x] Amendment: the rows Astra proceeds today that the new rules would hold, each with an
      example: its own section below.
- [x] Amendment: the accepted weakness of the near match with the count: its own section below.
- [x] Amendment: the replay's output for the seeded night of S-32.1, exactly as printed: under
      "The replay command".
- [x] The result is filled in.

## How to run

From the worktree (dependencies installed once from the unchanged lock file):

```
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit tests/Feature/NightRun/AstraAddressRulesTest.php
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit tests/Feature/NightRun/AstraReplayCommandTest.php
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit
```

No migration. Nothing to build for the front end (one checkbox with classes the page already
uses).

## The replay command

On the server, in the application's folder, for the night whose run happened on the morning of
7 October (Manila time):

```
php artisan astra:replay-address-rules --night=2026-10-07
```

or, with the id of that night's Astra step, `php artisan astra:replay-address-rules --step=<id>`.
It only reads: no order, log or setting is changed, no model is called, it costs nothing, and it
prints the same report whether the switch is on or off. A wrong option prints one fixed line and
ends with code 1.

The output for the seeded night of S-32.1, exactly as printed (`<fp>` stands for twelve
characters, the fingerprint of the list file):

```
Astra address rules replay (read only, nothing is changed)
Night: 2026-10-05
Step id: 1
List fingerprint: <fp>
Rows that night: 6
  - finished: 6
  - failed: 0
  - skipped: 0
  - not run: 0
  - still waiting or running: 0
  - other: 0
Finished rows without a log: 0
Finished rows without an order: 0
Finished rows whose log could not be read: 0
Finished rows with an older log (the model's own request for a person is not recorded there, it is inferred): 0
Astra proceeded that night: 0
  - could not be replayed (no log, no order or unreadable log): 0
  - the new rules would not proceed: 0
Held for a person that night: 6
  - could not be replayed (no log, no order or unreadable log): 0
Would pass the address rules under the new rules: 5 (ids: 1, 2, 4, 5, 6)
  - of those, the barangay was accepted on high confidence, not from the customer's text: 0
Would pass everything under the new rules: 4 (ids: 1, 2, 4, 5)
First thing that would still hold each held row (the parts add up to the held rows):
  - the model itself asked for a person: 0
  - the customer's intent is unclear: 0
  - no line from the list: 1 (ids: 3)
  - the barangay is not in the customer's text: 0
  - a required field is blank (name, phone or address): 0
  - the final check (item, COD, shop details, blacklists): 1 (ids: 6)
  - nothing, the row would proceed: 4 (ids: 1, 2, 4, 5)
  - not replayed: 0
Where the line of the held rows came from:
  - the model: 4
  - the program, from Astra's form: 1
  - no line: 1
Older logs where the hold was taken as the program's own, not the model's: 0
  If the model itself asked for a person on one of these, that row would stay held: the counts above are an upper bound.
  For an older log, a row where the model wrote a reason but did not itself ask for a person is counted as 'the model itself asked for a person', so that part can be too high.
Rows where the model asked for a person and the log does not say why: 0
  - strict (the request always holds): none of these rows would proceed
  - lenient (the request does not hold when the program found the line and the customer's text confirms it), would pass everything: 0
Held rows that staff have since set to PROCEED: 0
  - the new rules would also proceed: 0
  - with the same province, city and barangay as staff: 0
  - with a different province, city or barangay: 0
Held rows that staff have since set to CANNOT PROCEED: 0
  - would have passed the address rules: 0
  - would have passed everything: 0
Rows that could not be rebuilt exactly as they were that night: 0
  - the chat has a different length now: 0
  - the earlier conversation has a different length now: 0
  - the customer details have a different length now: 0
  - the customer details cannot be checked for older logs: 0
  - the list has changed, or the stored line is no longer in it: 0
Of the rows that would pass everything, the same phone is on another order of the same date today: 1 (ids: 5)
What a replay cannot know:
  - the earlier conversation as it was that night; it is read as it is today.
  - edits made to the chat or the customer details since that night.
  - earlier conversation the model fetched with its own tool.
  - the version of the list that night, for older logs.
  - item, COD, shop details and blacklists are read as they are today.
  - only nights whose logs still exist can be replayed; logs older than 90 days are deleted.
```

The six seeded rows: 1 a barangay the customer misspelt (the guard now accepts the near match);
2 an empty line of the model with a form the program can map; 3 a form the program cannot map;
4 a cancel with a good line; 5 a same-date duplicate phone; 6 COD blank.

Each group in plain words:

- **Night, step, list fingerprint:** which night was replayed and a short code of the address list
  in use today (two runs with the same code used the same list).
- **Rows that night, by state:** how the night ended for each order. Only "finished" rows have an
  answer to replay.
- **Finished rows without a log / without an order / whose log could not be read:** rows the
  replay cannot use. They appear again as "could not be replayed".
- **Finished rows with an older log:** logs written before this change. Whether the model itself
  asked for a person is not stored in them; the command works it out from the reason text.
- **Astra proceeded that night / the new rules would not proceed:** rows that were PROCEED that
  night and would now be left for a person. These are the rows to look at before switching on
  (see the next section for why this can happen).
- **Held for a person that night:** the rows Astra left for staff. Everything below is about them.
- **Would pass the address rules:** the address would now be found and confirmed. The line under
  it says how many of those were accepted because the model was highly confident, not because
  the customer wrote the barangay.
- **Would pass everything:** the rows that would become PROCEED (address, and the final check of
  item, COD, shop details and blacklists, without the duplicate-phone rule).
- **First thing that would still hold each held row:** one reason per row, in a fixed order; the
  parts add up to the held rows.
- **Where the line came from:** the model's own list line, or the program's mapping of Astra's
  form, or no line.
- **Older logs where the hold was taken as the program's own:** for older logs only; the two
  sentences under it say in which direction the counts can be off.
- **Rows where the model asked for a person and the log does not say why:** "strict" counts them
  all as held; "lenient" shows how many would proceed if the model's only reason was that it
  found no list line.
- **Held rows that staff have since set to PROCEED:** how many of them the new rules would also
  proceed, and whether with the same province, city and barangay as staff chose. "Different" is
  the number to check by hand.
- **Held rows that staff have since set to CANNOT PROCEED:** how many of those the new rules
  would have proceeded. These too are worth a look.
- **Rows that could not be rebuilt exactly:** rows whose chat, earlier conversation, customer
  details or list are not what they were that night, so their result is an estimate.
- **The same phone is on another order of the same date:** information only; the new rules do
  not hold a row for it, the validation step does.
- **What a replay cannot know:** the limits of the whole report.

## Rows Astra proceeds today that the new rules would hold

All of these concern rows where the model's confidence is medium or low; at high confidence the
barangay is accepted without the text, as today. In each, the new rules prefer "a person decides"
to a guess.

| Kind | Example text | Why it is held now |
|---|---|---|
| A number or a single letter directly after the barangay's name | "Holy Spirit 2 pcs", "Holy Spirit 2pcs", "Holy Spirit 1127" (a zip code), "Zone 1 B street" | It could be the number or letter of another barangay (Holy Spirit 2, Zone 1-B). A comma or a new line before the number avoids it, except in the next kind. |
| A number after a comma, a dash, a bracket or on the next line, where the city has that numbered barangay | "Brgy Fatima" then "2 pcs" on the next line, "Fatima, 11 Mabini St", "Poblacion 1, 2 pcs" (cities with FATIMA II, POBLACION 2) | The text check found that customers write "Fatima - 2" and "Fatima (2)" for FATIMA II, so a number behind a break counts when that sibling exists. |
| A numbered barangay followed by another number | "Brgy 28, 1 Sampaguita St", "Brgy 28 (1 pc)" | Two numbers, one barangay number: unclear which. |
| A number alone for a numbered barangay | "28 pcs" or "house 28" with BARANGAY 28 | Today any standalone 28 confirmed it; now the number must follow "barangay", "brgy" or "bgy". |
| A numbered list | "3. Poblacion" then "4. Cotabato" on the next line; "Brgy 1: Nasipit" | The list number reads as the barangay's number. |
| The text names two barangays of the city, or a word that is also a sibling's number | "Poblacion West ... Poblacion East po pala"; "Poblacion, una po sa lahat" where POBLACION I exists | The check stops at the first sign of a second barangay. |
| A longer barangay name around the name | "Dugui San Vicente" for SAN VICENTE; "Brgy Vinisitahan - Basud" for BASUD; "Anilao Labak" for ANILAO | The customer wrote the longer barangay (also with one wrong letter). |
| The name inside a longer word, or split | a name that only appears as part of a longer word; "Holy, Spirit" | Today five letters in a row anywhere were enough; now whole words in one phrase are needed. |
| Only Astra's form says the barangay, in other words than the list | form "Poblacion", list line POBLACION 2, chat "Poblacion" | The form's wording counts only when it maps to that very label. |
| A barangay named like its town, said once | "Malibcong, Abra" for barangay MALIBCONG | Once is the town; twice, or with "Brgy" beside it, is the barangay. |
| A label that itself holds a dash or a bracket | `BGY. 1 - EM'S BARRIO (POB.)` | The text is cut at dashes and brackets, so such a label is never confirmed from text. |
| A name written with a dotted abbreviation other than sta, sto, pob, gen | "St. Peter", "Dist. 1" | A full stop ends a phrase. |

The replay prints the number of such rows for a real night in the line "Astra proceeded that
night … the new rules would not proceed", with ids.

In the other direction, with the switch on the program also makes no line (a person decides) for
a city written without a province when the name is not unique in every wording: "Quezon City"
with no province (towns named Quezon exist in five provinces), "Naga", "Danao", "San Carlos
City"; and for a form with a second place name or a province that does not fit the city
("Quezon City, Philippines" in the city field, "Jaro" with "Iloilo"). Such rows are held today
as well when the model gave no line, so nothing is lost; they are simply not gained.

## The accepted weakness of the near match

The guard accepts a barangay when the customer's wording is at least 85 per cent like the list's
name. For a name of one word this also accepts a different name that happens to be that close:
a customer who writes "Mariano" confirms a label MARIANA (85.7). It happens only when the model
proposed that label in the first place, at medium or low confidence, and it is refused when the
other name is itself a barangay of the same city (then the text check sees that the customer
wrote the sibling). Counted from the list file: of 15,972 different one-word barangay names of
five letters or more, at least 9,744 pairs are that close to each other (pairs with the same
first letter and at most two letters' difference in length were counted, so the true number is a
little higher); 47 of those pairs are barangays of one and the same city and are caught, 9,697
are not. Examples that are not caught: "banawon" / "banayon" (85.7), "binuangan" / "binuluangan"
(90.0), "buang" / "buanga" (90.9). Names of several words have the same weakness in a smaller
way; numbers and suffix letters never.

## Rulings

Applied from the plan (R1 to R9, approved with it): the sixth blocking reason; the form's wording
confirms only through the barangay matcher; the form must map by itself and agree with the
model's valid city; the flag is waived only for a program line; the exemption count in the
report; no detection of customer-details edits for older logs; no browser check (R7); the
details of the hard breaks; the rows of the section above (R9).

Made during the build:

- Ruling: the review of task 0 (the test-only commit) was done together with the review of task
  1 — one reviewing run fewer — none found: the reviewer checked the literals and that nothing
  under `app/` changed.
- Ruling: the developer agents were general-purpose agents told to work by the kit's
  `backend-developer` definition — this session cannot start the kit's developer agents by name —
  no cost seen; the reviewer was the kit's `skeptic-reviewer` throughout.
- Ruling: tasks 5 and 6 and the reviews ran at the same time in one worktree, each agent limited
  to its own files and committing only its own paths — the run had a two-hour limit — cost if
  wrong: a mixed commit; the log shows each commit holds only its files.
- Ruling (text check): a rejection by a sibling name voids the whole text, like a rejected
  number — a chat that names two barangays is unclear — a customer who corrects themselves goes
  to a person.
- Ruling (text check): a full stop that is not part of sta., sto., gen., pob., a barangay word or
  an initial, and `)`, `]`, `!`, `?`, end a phrase — "Poblacion. 2 pcs" confirmed POBLACION II
  otherwise — names with other dotted abbreviations go to a person.
- Ruling (text check): invalid bytes and control characters are breaks; the label must be one of
  the city's labels; a needle made only of numbers and single letters needs the barangay word; a
  sibling look reads across breaks in both directions, a second number only on the same line;
  number words, ordinals, "No.", "ika-", lowercase L as Roman and "11" as II are read only to
  find a sibling, never to confirm — each can only remove a confirmation.
- Ruling (guard): for a barangay named like its city the name must appear twice within one
  source, and "pob" beside it counts only when POBLACION is not another barangay there — the
  earlier conversation is often a copy of the chat — a few more such rows go to a person.
- Ruling (mapper, widened by the review beyond the amendment's point 4): both wordings (with and
  without "city") are asked and must agree; a non-empty province must be a list province equal to
  the mapped city's (loose spelling allowed: "Ilo-ilo", "Iloilo Province") or the mapped city
  itself; every part after a comma in the city must fit too; a different province is allowed only
  for the list's own filing of a city that is the only place of its name, when the form says
  "City" or the name is the province's own name (Cotabato City under COTABATO) — the review
  showed "Jaro, Iloilo City" going to Jaro, Leyte and "San Carlos City" to Pangasinan as PROCEED
  — cost: "Quezon City" with no province, or with "Philippines" after a comma, goes to a person.
- Ruling (mapper): values longer than 120 characters (city, barangay or province) are not mapped;
  the mapper's note is made one line before it reaches the note, the block or the reason.
- Ruling (flag): when the model's flag is waived its reason is taken out of the stored answer and
  kept in an evidence line starting `FLAG:` — otherwise the logs page would show a "person"
  reason on a PROCEED row — the reason is read from the evidence.
- Ruling (intent): under the new rules any intent other than order, cancel or inquiry holds.
- Ruling (log): `label_source` is `model` whenever the model's line was complete and valid, also
  when the guard then dropped the barangay; `model_intent` falls back to `order` for an unknown
  word; `model_human_kind` is always `none` under the old rules.
- Ruling (replay): the headline counts are the strict ones; a log with the block but without a
  kind (every log written with the switch off) is treated like an older log for the
  strict/lenient lines; unclear intent is read from the stored answer; a missing recorded length
  counts as "not rebuilt exactly"; the "strict" line has no number because it is always zero.
- Ruling (S-23.1, S-30.4): the whole log is pinned in the edited characterisation test; the tests
  in the new file pin the key order and the block's literal.
- Ruling (tests): the red run of the replay command was taken by moving the already drafted
  command file out of the tree, running the tests, and moving it back — the developer had drafted
  the command first — the tests were seen failing for "command does not exist" only.

## Deferred minors

All are in `TODO.md` under "018", each with its reason (sixteen entries: five on the text
check, three on the mapping, three on the rule function, five on the command, the settings box
and the older-log inference). None can change a result while the switch is off.

## Merge danger

A two-way door. With the switch off (the default, and what a fresh deploy has) the only changes
are the `replay` key in each Astra log and one more read of the settings per row; the ten
captured answers and the existing tests show the rest is identical. Blast radius with the switch
on: every Astra row of the night run and of the browser's Astra Check and Astra Fix; a wrong
PROCEED would be a parcel to a wrong barangay, which is what the two reviews of the text check
and the two of the mapping were for. To know before switching on:

- Run the replay for one or two nights and read "the new rules would not proceed", "with a
  different province, city or barangay" and the CANNOT PROCEED lines by hand.
- A cancel or an inquiry with a complete, valid order becomes PROCEED (the note keeps `CANCEL?`
  or `INQUIRY?`).
- A CEO settings page that was open before the switch was turned off sets it on again on its
  next save (the page sends what it shows).
- The open point for the owner under "Open questions".

Revert: untick the switch (the next row runs the old rules); or revert the branch's commits (no
migration, no stored data to undo except the one settings row and the extra key in logs, which
nothing else reads).

## Conflicts with CLAUDE.md

- The kit's test rule (tests under about three times the code) against the spec's one named test
  per case: the spec was followed, as approved with the plan. 108 tests, variants as table rows.
- The kit names `backend-developer` and `frontend-developer` agents; this session could not start
  them by name (see Rulings).
- The kit's browser check was skipped (R7): no environment file, the application cannot start.
  The settings box was not seen in a browser; its markup is tested for the CEO and for another
  role.
- The kit's per-task review: task 0 was reviewed with task 1, tasks 3 and 4 together, tasks 5 and
  6 together.

## Tests

**Characterisation tests** (commit `3b7c11e`, green on the unchanged product code; all still
green, the fixture's captured literals untouched), and what turns each red:

| Case | Turns red when |
|---|---|
| S-22.6 | text or extra keys in the answer change the fields, STATUS, code, gate or proceed, or a settings row `1` appears |
| S-23.2 | any of the six fields, STATUS, code, note, block, evidence line or gate differs for one of the ten answers with the switch off |
| S-23.3 | one byte of the request changes with the switch off, a `MAP:` line or a near result appears, or the calls or cost change |
| S-23.4, S-28.3 | the classic engine's result changes when the settings row is `1` |
| S-23.5 | the gate called the classic way stops returning the duplicate, or the classic mapper, matcher or text check return another array |
| S-23.6 | the program adds a model call |
| S-27.6, S-28.2 | a cancel or a duplicate phone is treated differently with the switch off |
| S-29.7 | the night job's handling of 401, quota, 500, a connection error or a STATUS set by a person changes |
| S-29.8 | any of the five night rows' state, flag, code, the calls or the cost changes with the switch off (and, with it on, any row other than the three intended ones) |
| S-33.4 | a wrong type in the answer stops giving the fixed failure message or leaks text |

**Red lines** (first failure, before the code):

| Slice | Test | First failure line |
|---|---|---|
| The text check | `test_S_25_2_…` | `Error: Class "App\Services\AstraBarangayMatcher" not found` |
| its fix 1 | `test_S_26_16_…` row "Brgy Fatima ll, SJDM" | expected `none`, actual `phrase 100` |
| its fix 2 | `test_S_26_14_…` row "Vinisitahan - Basud for BASUD" | expected `none`, got `phrase` |
| The rule function, switch reader, `replay` | `test_S_22_1_…`; `test_S_30_1_…` | `Call to undefined method App\Services\AstraEncoder::addressRulesOn()`; `Undefined array key "replay"` |
| New rules, part 1 | `test_S_24_1_…` | `Failed asserting that two arrays are identical` (no line, no PROCEED); 24 tests red in that run |
| New rules, part 2 | `test_S_27_1_…` | expected `PROCEED`, `✅`, true; got null, `CANCEL?`, false; 11 tests red |
| The settings switch | `test_S_22_2_…` | `Failed asserting that null is identical to '1'` |
| The replay command | `test_S_31_1_…` | `CommandNotFoundException: The command "astra:replay-address-rules" does not exist.`; 19 red |
| Mapper fix 1 | `test_S_24_8_…` row "a district before its city" | `Failed asserting that two arrays are identical` (a line was written) |
| Mapper fix 2 | `test_S_24_8_…` row "the province with a hyphen" | got `LEYTE / JARO / SAN ROQUE`, `PROCEED` |

Tests that were green on arrival because they pin a hold that stays (no red run): S-24.9 to
S-24.12, S-25.4, S-29.1, S-29.4 to S-29.6, S-27.3, S-27.5, S-28.4, S-28.5, S-22.3, S-22.8,
S-30.5, S-33.1; two of them were checked by switching the rule off (S-24.12, S-24.15).

**Reviews** (the kit's separate reviewing agent, read-only, adversarial depth, with sweeps over
the real list):

| Reviewed | Findings | What was done |
|---|---|---|
| The plan (spec review, first run) | 10 "needs revision" | all worked into the plan before it went out |
| Task 0 and the text check | 3 majors: the bare name confirmed when the customer wrote a numbered sibling with lowercase L ("Fatima ll"), glued ("San RafaelIV"), or behind a dash or bracket ("Fatima - 2"); 6 minors | fix loop 1: all fixed test-first; sweep of 5,221 checks, only same-barangay duplicates left |
| Re-check 1 and the rule function's move | text check: 2 majors still open (a longer sibling name before the hit, "Vinisitahan - Basud"; with one wrong letter, "Anilao Labak"), 4 minors. The move: no blocker or major; compared with the base statement by statement | fix loop 2: all fixed; sweep of 9,320 sibling texts: 1,587 wrong confirmations before, 32 after (all same-barangay duplicates); no `none` became a confirmation |
| Re-check 2 and the new rules (tasks 3, 4) | text check: closed, 1 minor ("Fatima ika-2"). New rules: 2 majors, both a wrong-province PROCEED from the mapper ("Jaro, Iloilo City" to Jaro, Leyte; "San Carlos City" without a province to Pangasinan); 2 minors. Switch-off path compared with the base for every combination: identical | mapper fix loop 1: both fixed, and the two minors (reason and issues made one line; "ika-2"); sweep: 0 cities map to a different city, 1 of 3,989 own-province wordings lost its line |
| The settings switch and the command (tasks 5, 6) | no blocker, no major; could not make the command write, print stored text or exit other than 0 or 1; 5 minors, 4 missing tests | minors wave: the under-count sentence, the "strict" line, tests for wrong-shaped logs and for a guest post; the rest in `TODO.md` |
| Re-check of mapper fix 1 | 1 major still open: a province the list does not know counted as agreement ("Jaro" with "Ilo-ilo"); 1 minor (the filing exception too wide) | mapper fix loop 2: both fixed; sweep of 1,653 cities: none lost its line, none maps elsewhere |
| Re-check of mapper fix 2 | closed on 43 hand-picked forms and by reading (its full sweep did not finish in its time); 3 minors | accepted with reasons in `TODO.md`; one is the open point below |

## Open questions

1. **A unique town name with no province (for the owner).** With the switch on, a form whose city
   is "Jaro" and whose province is empty maps to Jaro, Leyte, the only list city of that name,
   although Jaro is also a district of Iloilo City; the row is PROCEED when the customer's text
   confirms a barangay that exists in Jaro, Leyte (for example San Roque) and everything else
   passes. The spec's S-24.8 lets a city without a province map when the list has one such city,
   and S-26.9 relies on it ("Cotabato City" with no province). Recommended: leave it as built for
   the replay, read the replay's "different province, city or barangay" line, and decide then
   whether the program should make a line only when the form names a province. That stricter
   rule is a few lines; it would hold more rows.

## Process suggestions

- Time per task (agent run time; the session's own reading and writing comes on top). Plan run:
  about 70 minutes including the spec review (8). Build: task 0, 10 min; the text check 18, its
  review 10, fix 11, fix 12; the rule function's move 7, its review with re-check 16; new rules
  part 1, 13; part 2, 9; their review 7; the settings switch 3; the replay command 9; their review
  4; mapper fix 5, re-check 4, fix 3, re-check 9; minors and doc 6; full suite 4. About 2 hours
  40 minutes of agent time for the build, over two runs. The text check with its reviews and
  fixes (61 minutes) and the mapper's two fix loops were the long parts: a spec of this kind is
  better cut into "the text check alone", "the rule function and the new rules", "the switch and
  the replay".
- Every review that swept the real list found a major that reading had not: a sweep over the
  list should be part of the developer's own task for any matcher or mapper, not left to the
  review.
- A second `Http::fake` for the same address in one test is ignored (the first answer wins);
  tests that run several answers need one fake that reads the current answer. It cost one wrong
  measurement in the plan run.
- The console writes CRLF on this machine; a test that compares command output must normalise
  line ends. It broke the replay tests once after they were committed green (fixed in the minors
  wave).
- The two-hour limit of a run is shorter than this build; running the read-only review at the
  same time as the next task, each agent limited to its files, made the second run fit.

## Proposed tasks

- Decide the rule for a unique town name without a province (open question 1) — a possible wrong
  province — high, before the switch is turned on for good.
- Collapse line breaks in Astra's customer-details block under the old rules too — a form value
  or reason with a line break and `---` can end the block early, so a later run reads the rest as
  the customer's text — normal (it changes the switch-off output, so it was not done here).
- Give the classic checker the "with and without city" tie check and the province check — "Naga",
  "Danao" or "Jaro, Iloilo City" map to the other town there too; only its judge call stands
  behind it — normal.
- Clean the list's duplicate labels (40 pairs that differ only by spacing, brackets or "&" against
  "AND") — the matcher gives "no pick" for them, so those barangays always go to a person — low.
- Characterise the four switch-off paths named in `TODO.md` on a checkout of the base — they are
  verified by comparison only — low.
- Run the replay command once on MySQL with a real-sized night and time it — tested on sqlite
  only — normal, part of the first server run.

## Suggested next steps

1. The reviewer's code review of the branch.
2. After the merge and deploy (switch off): let one night run, so that its logs carry the
   `replay` block, then run the replay for that night and for one earlier night on the server.
3. Read the numbers with the owner, with the three sections above at hand, and decide open
   question 1.
4. Turn the switch on for one night, read that morning's rows, and keep or untick it.
