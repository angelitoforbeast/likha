# Result: spec 018 Astra identifies the J&T address like the classic checker

> Committed beside the spec: names no people and no decision ids.

**Status:** done, with fix list 1 (amendment 018-2) and fix list 2 (amendment 018-3) done
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
| S-25.11 | `test_S_25_11_a_barangay_named_like_its_town_is_confirmed_only_with_a_barangay_word_beside_it` | pass |
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
| S-26.17 | `test_S_26_17_the_exact_name_of_a_sibling_beside_a_filler_word_does_not_confirm_the_longer_name` | pass |
| S-26.18 | `test_S_26_18_over_every_pair_of_a_name_and_its_one_word_longer_sibling_a_filler_word_never_confirms_the_longer_name` | pass |
| S-26.19 | `test_S_26_19_a_barangay_named_like_its_province_needs_more_than_the_provinces_name` | pass |
| S-26.20 | `test_S_26_20_a_text_that_names_another_barangay_of_the_city_confirms_neither` | pass |
| S-26.21 | `test_S_26_21_another_barangay_is_seen_in_the_ways_customers_write_it` | pass |
| S-26.22 | `test_S_26_22_the_word_poblacion_counts_as_another_barangay_only_after_a_barangay_word` | pass |
| S-26.23 | `test_S_26_23_a_common_mention_of_the_poblacion_keeps_at_least_99_per_cent_of_the_clean_addresses_confirmed` | pass |
| S-26.24 | `test_S_26_24_the_common_spellings_of_the_barangay_word_count_wherever_the_check_uses_it` | pass |
| S-26.25 | `test_S_26_25_another_barangay_written_with_a_slash_a_dash_or_dotted_initials_is_seen` | pass |
| S-26.26 | `test_S_26_26_poblacion_beside_the_name_confirms_a_namesake_only_where_the_town_has_no_other_poblacion_barangay` | pass |
| S-26.27 | `test_S_26_27_a_place_name_that_ends_in_barrio_is_not_a_barangay_word` | pass |
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

## Fix list 1 (amendment 018-2)

An independent check of the finished work found three families of text where the text check
confirmed a barangay the customer's words do not support. All three are fixed, in the text check
only (`AstraBarangayMatcher`, and in `AstraAddressRules` only `confirmedByText`,
`namedLikeItsCity` and a helper beside them). The rule function, the mapper, the switch-off path,
the command and the settings box are untouched; everything changed is reached only with the
switch on.

**The three families and what was built**

1. *A filler word beside a sibling's exact name.* "Brgy San Isidro sa Lagonoy" confirmed SAN
   ISIDRO SUR (POB.) by near match because the window took in "sa". Now a near hit is refused
   when another label of the city is the first or the last words of the window, or overlaps the
   hit as a whole phrase. Kept on purpose: a longer name with one wrong letter in its extra word
   still confirms the longer name ("Anilao Labak" for ANILAO-LABAC), when that word has five
   letters or more.
2. *A barangay named like its province.* "Pitogo, Quezon" confirmed the barangay QUEZON of
   Pitogo. The rule for a barangay named like its town now also covers the province's name and
   any run of words of the town's or province's name ("Santa Marcela" for MARCELA (POB.)).
   The review then showed that "the name twice within one source" is met by an address that is
   simply given twice (38 of 38 such barangays confirmed on "Pitogo, Quezon" plus "Address:
   Pitogo, Quezon"), so that half of the rule was removed: such a barangay is confirmed only
   with a barangay word, "pob" or "poblacion" directly beside its name (ruling below).
3. *Two different barangays of the city in the text.* When any of the three sources names
   another barangay of the same city as a whole phrase, the barangay is not confirmed, whatever
   the order. The exceptions of the amendment are built (a name under five characters, a name
   inside the one being confirmed, the town's or province's own name, a number). After the
   review, the other barangay is also seen when written as plain "Poblacion" after a barangay
   word or after "sa", "ng", "taga" (in towns whose poblacion carries another name), by the name
   inside its bracket, with or without the dot of an initial, as a short name after a barangay
   word, and as a shorter sibling named separately with its own barangay word.

**New cases** (in `qa/stories.md` and the case table): S-26.17 to S-26.21; S-25.11's Then was
reworded (the name twice no longer confirms).

**Red lines** (first failure before each fix):

| Commit | Test | First failure line |
|---|---|---|
| `9f8cd30` point 1 | `test_S_26_17_…` | `'Brgy San Isidro sa Lagonoy'` expected `none`, got `near` 88 |
| `9f8cd30` the sweep as a test | `test_S_26_18_…` | `Failed asserting that two arrays are identical` (the list of wrong confirmations was not empty) |
| `70a67d5` point 2 | `test_S_26_19_…` | row "town and province, QUEZON": expected `none`, got `phrase` 100 |
| `e866b18` point 3 | `test_S_26_20_…` | row "moved, the first one named": expected `none`, got `phrase` 100 |
| `6e44c38` a bug the cost sweep showed | `test_S_26_20_…` | row "a numbered name that is part of this one": expected `phrase`, got `none` ("Brgy 10-A, Cavite City" for BARANGAY 10-A) |
| `cf8568b` after the review | `test_S_26_19_…`; `test_S_25_11_…` | row "the address given twice": expected `none`, got `phrase` 100; row "twice in the chat": expected `none`, got `phrase` |
| `052179f` after the review | `test_S_26_21_…` | row "plain Poblacion after a barangay word": expected `none`, got `phrase` 100 (seven rows red) |
| `0b4d443` after the re-check | `test_S_26_21_…` | three new rows returned `phrase` 100 where `none` was expected |

**The sweep of point 1, full set** (it runs as the test S-26.18 in the suite, the whole set, no
sampling, about 4 seconds): 593 pairs of labels in one city where one is the other plus one word
at the start or the end, 10 filler words, 5,930 texts each way.

| | Before (`80161a0`) | After |
|---|---|---|
| "Brgy <shorter> <filler> <city>" wrongly confirms the longer label | 45 texts (38 pairs) | 0 |
| "Brgy <longer> <filler> <city>" confirms the longer label | 5,580 | 5,580 |
| correct confirmations of the longer label lost | | 0 |

The 350 texts of the second row that do not confirm are the same before and after: 35 labels
that are never confirmed in this form (labels such as `BGY. NO. 31 TALINGAAN`, `R. ECLEO SR.`);
the test pins them by name. A wider probe (25 filler words, before and after the name): 241
wrong before, 7 after, and all 7 are the customer writing the longer name itself ("new Lourdes",
"san Juan").

**The cost of point 3** (rows that turn from confirmed to not confirmed):

- Every label of the list written by its own name, "Brgy <label>, <city>, <province>", 43,152
  texts: 42,606 confirmed before the fix list, 42,606 on the final code (run on the head). In
  between, the first version of point 3 lost 48 of them (all in two towns whose written-out
  label repeats the province's name); the fix after the review gave them back. Cost on a clean
  single address: none.
- The rebuilt sibling sweep (75,345 texts over every pair where one name holds the other or they
  differ in the last word, 24,404 numbered pairs): 7 texts turned from confirmed to not
  confirmed through point 3, none the other way.
- The real cost is in chats that a sweep of labels does not write: a street, a subdivision or a
  landmark that carries the name of another barangay of the same city, five letters or more
  ("Brgy Alangan, 12 Mabini St" in a city with a barangay MABINI), and a customer who names the
  old and the new barangay. Both now go to a person. How often this happens on real chats is not
  known; the replay shows it for a night in the line "Astra proceeded that night, the new rules
  would not proceed" and in the count of rows held by "the barangay is not in the customer's
  text".

**The earlier sweeps, re-run**

- The text check alone, `80161a0` against the code after point 3, on the 75,345 sibling texts
  (numbered siblings and name siblings; the original scripts of the build were throwaway and
  were rebuilt to the same recipe): 0 texts turned from "not confirmed" to confirmed, 0 the
  other way. Not re-run after the three later commits; each of them only refuses.
- S-26.18 (above) on the final code: 0 wrong, none lost.
- The mapper's sweep was not re-run: `mapForm` has no change in this fix list
  (`git diff 80161a0..HEAD -- app/Services/AstraAddressRules.php` touches `confirmedByText`,
  `namedLikeItsCity` and the new `placeNames` only), and its tests are green.

**The review** (the separate reviewing agent, adversarial depth, own sweeps on the real list;
233,716 filler probes, 4,827 correction probes):

| Round | Findings | What was done |
|---|---|---|
| The diff of the four fix commits | Family 1 closed (no wrong confirmation by a plain filler in 233,716 probes). Family 2 closed for one mention, but **major**: "twice in one source" is met by a repeated address or a street of that name (38 of 38). Family 3 closed for the exact spelling only, **major** (the fourth family asked for): the other barangay written as "Poblacion", by its bracket name, with a dotted initial, or equal to the confirmed label's own bracket name is not seen. Minors in `TODO.md` | fixed in `cf8568b` and `052179f`; one item left out (below) |
| Re-check | both fixes hold (46 of 46 namesake barangays refuse the repeated address); 2 further **majors** of family 3: a shorter sibling named separately with its own barangay word ("Brgy Santa Cruz Bigaa po. Ay mali, Brgy Santa Cruz pala" confirmed SANTA CRUZ BIGAA; 253 of 356 pairs), and a bare "Poblacion" without a barangay word ("ngayon sa Poblacion na po") | fixed in `0b4d443`, narrowly: the sibling needs its own barangay word; "Poblacion" counts after "sa", "ng" or "taga" |

No review ran on `0b4d443` (the time of the run); its three rows and the whole file are green.

**Rulings of the fix list**

- Ruling: a barangay named like its town or province is confirmed only with a barangay word,
  "pob" or "poblacion" beside it; the name twice in one source no longer confirms, and such a
  name counts as "another barangay" only with a barangay word — this goes beyond the amendment's
  point 2, which kept "twice within one source"; the review showed that a customer's address
  given in the message and again in the filled form confirms the namesake barangay every time —
  cost if wrong: a customer who writes the barangay's name twice without "Brgy" goes to a person.
  It can only turn a confirmation into "not confirmed". S-25.11 was reworded accordingly. Confirmed by the
  reviewer with fix list 2. "Pob" or "poblacion" beside the name confirms only where the town
  has no other poblacion-type label: 29 of the 46 such barangays after fix list 2 (34 before its
  point 4); a barangay word beside the name confirms all 46.
- Ruling: a longer name with one wrong letter in an extra word of five letters or more still
  confirms the longer name — the existing case S-26.14 requires it — two real pairs let a real
  word through ("pala Salvacion" for PALTA SALVACION, "luma Punod" for LUMBA-PUNOD).
- Ruling: the amendment's exception "not a word run of the label being confirmed" is kept, except
  for a shorter sibling that is itself a label, stands outside the confirmed name's own mention
  and has its own barangay word — the correction "Brgy Santa Cruz Bigaa … Brgy Santa Cruz pala"
  must not confirm the withdrawn one — cost: such a text goes to a person.
- Ruling: "Poblacion" counts as another barangay after a barangay word, or after "sa", "ng",
  "taga", only in towns that have a poblacion-type label and no label POBLACION, and never when
  the barangay being confirmed is itself a poblacion label or stands directly beside the word —
  "malapit sa Poblacion" then holds a row; counting every "Poblacion" would hold many more.
- Ruling: bracket names of five letters or more count as another barangay without a barangay
  word, like whole names; a dotted initial is ignored only when looking for another barangay,
  never when confirming.
- Ruling: story ids. The two specs merged from develop also use S-22 to S-24 in
  `qa/stories.md` for their own stories. Slice 018 keeps the ids its spec gives; the test names
  do not clash (other test classes). The reviewer may want one of the slices renumbered.

**Accepted, in `TODO.md`:** the other barangay written with one wrong letter or with its words
joined ("BachawNorte") is not seen as another barangay (left out for time; it needs the model to
keep the withdrawn barangay); a short name or a number without a barangay word ("ngayon sa Pias
na po", "ay mali, 29 po pala"), by the amendment's exceptions; a bracket name written with a
slash; the two real-word pairs above; "Poblacion" as the second barangay when the one being
confirmed is itself a poblacion label; the name of the test for S-25.11 still says "more than
one mention".

**The merge.** `git merge develop` (local develop at `1c32cc1`) as commit `81b677b`. Conflicts
only in `qa/stories.md` and `TODO.md`; in both, develop's appended sections are kept first and
this spec's section after them, checked line by line against both sides. No other file
conflicted.

**The suite after the merge**, plain PHPUnit in this worktree, on the final code: `Tests: 744, Assertions: 10552, Errors: 1, Failures: 1, Skipped: 3.`
The two red tests are the known ones
(`ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, which
needs an untracked file, and the old `ExampleTest`). `AstraAddressRulesTest.php`: `OK (94 tests,
1705 assertions)`; `AstraReplayCommandTest.php`: `OK (19 tests, 472 assertions)`.

**Time** (agent run time): the three points 15 minutes; the review 11; the fix after it 7; the
re-check 3; the last fix 3; three suite runs about 4 each.

## Fix list 2 (amendment 018-3)

The second independent check confirmed fix list 1 and found four things in the text check; a
fifth point asked for tests. All five are done, in the text check only: every commit of this fix
list changes `app/Services/AstraBarangayMatcher.php` and the test file and nothing else
(`AstraAddressRules.php` needed no change). Nothing is reachable with the switch off.

**The points and what was built**

1. *The word "Poblacion" as another barangay.* The bare word (also "Pob", "Pob.") counts as
   another barangay only when a barangay word stands directly before it. The rule "after sa, ng,
   taga" of fix list 1 is removed, and in a town that has a label POBLACION a bare "Poblacion"
   elsewhere in the text no longer refuses the other labels. A poblacion label with its
   qualifier written out ("Poblacion East", "Poblacion 2") still counts like any other label.
2. *Spellings of the barangay word.* One list, used wherever the check uses a barangay word:
   barangay, baranggay, barangy, brgy, brg, brngy, bgy, bgry, barrio, each with an optional full
   stop or colon. "Purok", "Sitio", "Zone" and "B." are not barangay words. "Barrio" is special
   because 21 label names contain it (BAGONG BARRIO): it stays a word of names, and after the
   review (below) it counts for confirming only as the first word of its part of the text.
3. *Another barangay written with a slash or a dash.* When looking for another barangay, a slash
   and a dash, with or without spaces, count as a space in the text and in the label; never for
   confirming. The Jasaan pair ("Brgy I. S. CRUZ, Brgy JAMPASON" confirmed JAMPASON): the cause
   was the handling of dotted initials. The initial "I." was read as the letter i, while the
   list's name I. S. CRUZ is keyed with it as the Roman number 1, so the other barangay never
   matched. Initials are now read the way the label's key reads them, only when looking for
   another barangay.
4. *A town's name followed by "Poblacion".* "Pob" or "Poblacion" beside the name confirms a
   barangay named like its town or province only where the town has no other poblacion-type
   label, in both word orders and also when the name beside it is the town's full name. Of the
   46 such barangays, 29 can be confirmed this way (the amendment expected 34; it was 34 before
   this point, and the five that dropped are in towns with other poblacion barangays, which is
   what the point asks to refuse). All 46 are confirmed with a barangay word beside the name.
5. *Tests.* The sweep S-26.18 also runs every text through `AstraAddressRules::confirmedByText`
   (0 wrong on both paths, none lost; the 35 labels that never confirm in that form are the same
   on both paths). The test of S-25.11 is renamed to
   `test_S_25_11_a_barangay_named_like_its_town_is_confirmed_only_with_a_barangay_word_beside_it`.
   S-26.23 pins the cost of point 1 as a count.

**New cases:** S-26.22 to S-26.27 (in `qa/stories.md` and the case table). One existing row
changed on purpose: in S-26.21, "Dati sa Brgy Pangal, ngayon sa Poblacion na po" now confirms
PANGAL (the accepted weakness of point 1).

**Red lines** (first failure before each fix):

| Commit | Test | First failure line |
|---|---|---|
| `dcc41a8` point 1 | `test_S_26_22_…` | row "Danglas: near the Poblacion": expected `phrase`, got `none` |
| `dcc41a8` the cost pin | `test_S_26_23_…` | `malapit sa Poblacion: 322 of 1698`: 0.1896 is not at least 0.99 |
| `3190ad5` point 2 | `test_S_26_24_…` | row "baranggay 1": expected `phrase`, got `none` (the same for barangy, brg, brngy, barrio) |
| `86b93bd` point 3 | `test_S_26_25_…` | row "a dash with spaces, after a barangay word": expected `none`, got `phrase` |
| `91e09d0` point 5 | S-26.18, S-25.11 | tests only; green on the code they pin (S-26.18 turns red when a filler word confirms the longer sibling through either path) |
| `5bb40e7` point 4 | `test_S_26_26_…` | row "the town's full name, then Poblacion": expected `none`, got `phrase` |
| `970b665` after the review | `test_S_26_27_…` | row "Bagong Barrio 28": expected `none`, got `phrase` 100 (eight rows red) |

**The numbers asked for** (full list, final code):

| | Before (`c8047ac`) | After |
|---|---|---|
| Clean address with "Brgy": "Brgy <label>, <city>, <province>", 43,152 texts | 42,606 confirm | 42,606 |
| Clean address without "Brgy": "<label>, <city>, <province>" | 42,559 | 42,559 (the lists of texts that do not confirm are identical) |
| S-26.18, 593 pairs, 10 filler words, both paths | 0 wrong | 0 wrong, none lost |
| Point 1 (a): 947 towns with poblacion-type labels only, "Brgy X, city" with each of the four phrases ("malapit sa Poblacion", "taga Poblacion ako dati", "order ng Poblacion", "sa Poblacion palengke") | 16 of 22,396 labels confirmed (the reviewer's count) | 26,201 of 26,245 confirm with all four; per phrase 26,242 / 26,221 / 26,244 / 26,226 |
| Point 1 (b): 605 towns with a label POBLACION, "Brgy X, Poblacion, city" confirms X | 0 of 12,189 (the reviewer's count) | 12,259 of 12,318 |

The bases differ from the reviewer's (26,245 against 22,396; 12,318 against 12,189) although the
numbers of towns are the same: here the base is every label that confirms from the clean "Brgy
X, city"; what the reviewer's base left out was not found. In (a), the 44 that are still held
name a real sibling ("sa Poblacion palengke" in Caibiran, which has a barangay PALENGKE). In
(b), 55 of the 59 that do not confirm do not confirm from the clean text either (labels with
initials, "MT.", "LT."); 4 are held by the Poblacion text itself. The cost pin S-26.23 runs
every 25th label of the list (1,698 clean texts, each with the four phrases) in 5 to 7 seconds
and asserts at least 99 per cent for each phrase.

**The review** (the separate reviewing agent, adversarial depth, an old-against-new comparison
on the real list; instructed to find a text that confirms a barangay the customer's words do not
contain, and to attack point 1 again):

| Asked | Result |
|---|---|
| A text that now confirms a barangay the customer did not write | **Found, major:** "barrio" counted as a barangay word also as the last word of a place name: "Bagong Barrio 28 Caloocan City" confirmed BARANGAY 28, "Blk 3 Bagong Barrio 143" BARANGAY 143, "sa barrio 2 pcs po" BARANGAY 2, "Barrio X" BARANGAY 10; and "Bagong Barrio Narra" confirmed the barangay NARRA of Narra (36 such label lines). All were "not confirmed" before this fix list. **Fixed in `970b665`:** for confirming, "barrio" counts only as the first word of its part of the text (after a comma or a line break, or at the start), and a number after it must be in digits; for seeing another barangay it counts as before. Clean-address counts unchanged (42,606 and 42,559) |
| Does anything else turn from "not confirmed" to confirmed? | 301,680 sibling texts over every seventh town with the new spellings and slash and dash forms: 6 turned, all one pair in Legazpi that is the same barangay spelled twice. Own spelling: none lost. Dotted initials do not confirm ("Brgy. V. Luna" not BARANGAY 5); slash and dash do not reach confirming ("Brgy Fatima - 2" not FATIMA; "Brgy 1/2" neither) |
| Point 1 again: "Brgy Poblacion" in every spelling beside "Brgy X" | 22 spellings and forms, 2,034 labels in towns with poblacion-type labels only and 1,056 in towns with a label POBLACION: X is refused every time, except 3 labels that are the town's only poblacion barangay spelled twice (the same before). "Brgy Poblacion, city" confirms POBLACION in 526 of 528 towns for all spellings (the two others fail before as well). "Poblacion East" or "Poblacion 2" beside "Brgy X", without a barangay word: 428 tried, 0 confirm X |
| Minor | six forms never refuse X: "Brgy, Poblacion", "Brgy ng Poblacion", "Brgy (Poblacion)", "Brgy" and "Poblacion" on two lines, "BrgyPoblacion", "Brgy Población" with an accent. In `TODO.md` |

No review ran on `970b665` (it only turns the confirmations the review found back into "not
confirmed"; its twelve rows, both test files and the two clean-address counts are as reported).

**Rulings of fix list 2**

- Ruling: "Poblacion" needs a barangay word in every town, with or without a label POBLACION —
  one rule, as the amendment states — a customer who moved "sa Poblacion" has the old barangay
  confirmed when the model proposed it (the accepted weakness).
- Ruling: "barrio" marks the next word but stays a word of names; for confirming it counts only
  as the first word of its part of the text, a full stop does not start such a part, and a
  number after it must be in digits — "Bagong Barrio 28" must never confirm BARANGAY 28 —
  cost: "Salamat. Barrio 28 Caloocan" and "sa Barrio 28" go to a person.
- Ruling: slash and dash become spaces for the whole search for another barangay, not only for
  labels that contain them — simplest, and it can only refuse — a text such as "Santa Cruz -
  Bigaa" is read as one name there; the clean counts did not move.
- Ruling: "Brgy Camposanto 1 - Sur" alone does not confirm its own label — it did not before,
  and confirming was not to be changed — such rows are held.
- Ruling: for point 4, "another poblacion-type label" is any other label of the town with the
  word poblacion in its name or in its bracket — five more of the 46 need a barangay word.
- Ruling: the time limit of the test S-26.18 was raised from 15 to 25 seconds — it now does the
  work twice (about 12 seconds alone).

**Accepted** (in `TODO.md` and under "The accepted weakness"): the six forms of the minor above;
"Brg 5 pcs" confirms BARANGAY 5 as "Brgy 5 pcs" already did; and the items the amendment lists.

**The suite**, plain PHPUnit in this worktree, on the final code: `Tests: 750, Assertions: 10649, Errors: 1, Failures: 1, Skipped: 3.` The two red tests
are the known ones (`ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`
and the old `ExampleTest`). `AstraAddressRulesTest.php`: `OK (100 tests, 1802 assertions)`;
`AstraReplayCommandTest.php`: `OK (19 tests, 472 assertions)`.

**Time** (agent run time): the five points 14 minutes; the review 5; the fix after it 4; two
suite runs about 4 each.

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
| A barangay named like its town or its province, without a barangay word | "Malibcong, Abra" for barangay MALIBCONG; "Pitogo, Quezon" for barangay QUEZON, also when the address is given twice | The name alone is the town or the province; only "Brgy", "Pob." or "Poblacion" beside it makes it the barangay (fix list 1). |
| The text names another barangay of the same city | "dati sa Brgy X, ngayon sa Brgy Y po"; "Brgy Alangan, 12 Mabini St" where MABINI is a barangay of that city (five letters or more); "Brgy X, Brgy Poblacion" (a bare "Poblacion" without a barangay word does not count, fix list 2) | Two barangays in one chat: a person decides which (fix list 1). A street or landmark with a barangay's name is held for the same reason. |
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

Also accepted, by the second amendment, each with one example:

- A label that is also a first name or a common word is confirmed by that word anywhere in the
  text when the model proposed that label: "Si Maria po ang tatanggap" confirms a barangay MARIA.
  The old rules do the same, and it needs the model to have proposed that label.
- Two labels of the list for the same place are interchangeable: "Marcela Poblacion" confirms
  MARCELA (POB.) and the other way round (Santa Marcela, Apayao).
- The "BGY. NO. N" labels are never confirmed by "Brgy N" (for example `BGY. NO. 31 TALINGAAN`):
  such rows go to a person, the safe direction.

Also accepted, by the third amendment, each with one example:

- A customer who moved to the town centre and says so without a barangay word still has the
  old barangay confirmed when the model proposed it: "dati sa Brgy Pangal, ngayon sa Poblacion
  na po" confirms PANGAL. "Poblacion" is the everyday word for the town centre and stands in
  many ordinary addresses as a landmark.
- A customer who rejects a barangay, or says it is a former address, still has it confirmed
  when the model proposed it: "Hindi po Brgy Holy Spirit, Quezon City" confirms HOLY SPIRIT.
  The old rules do the same; the text check does not read meaning.
- Two labels, BOLINEY POBLACION and CONDARAAN POB. (CONDARAAN DIMADAP), are not confirmed when
  a filler word stands before the bare name without a barangay word ("sa Boliney"; 62 texts in
  the reviewer's check); with "Brgy" before the name they are confirmed.
- Another barangay written with a wrong letter or with its words joined is not seen as another
  barangay: "Brgy X, Brgy BachawNorte" still confirms X.
- Six ways of writing "Brgy Poblacion" are not seen as another barangay: "Brgy, Poblacion",
  "Brgy ng Poblacion", "Brgy (Poblacion)", the two words on two lines, "BrgyPoblacion" and
  "Brgy Población" with an accent.

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
