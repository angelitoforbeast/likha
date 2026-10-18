<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistSubmission;
use App\Models\ChecklistSubmissionValue;
use App\Models\ChecklistTask;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * "Fields to extract": ang task ay may listahan ng value na kinukuha ng AI sa bawat submission
 * (sa approval check), naka-normalise at naka-save sa checklist_submission_values.
 */
class ChecklistExtractFieldsTest extends ChecklistTestCase
{
    private const FIELDS = [
        ['key' => 'total_sticker', 'label' => 'Total sticker', 'type' => 'number', 'hint' => 'the number after TOTAL STICKER in the notes'],
        ['key' => 'bank_balance', 'label' => 'Bank balance', 'type' => 'money', 'hint' => 'Bank balance in the second screenshot'],
        ['key' => 'pending_rate', 'label' => 'Pending rate', 'type' => 'percent', 'hint' => null],
        ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'hint' => null],
        ['key' => 'report_date', 'label' => 'Report date', 'type' => 'date', 'hint' => null],
        ['key' => 'all_posted', 'label' => 'All posted', 'type' => 'yes_no', 'hint' => null],
        ['key' => 'chatgpt_balance', 'label' => 'ChatGPT balance', 'type' => 'money', 'hint' => null],
    ];

    private const ANSWER = [
        'total_sticker'   => '1,612',
        'bank_balance'    => '₱2,295.67',
        'pending_rate'    => '2,01%',
        'remarks'         => 'Naka-post na lahat',
        'report_date'     => 'October 14, 2026',
        'all_posted'      => 'yes',
        'chatgpt_balance' => null,
    ];

    // ── Editor ────────────────────────────────────────────────────────────────

    public function test_the_fields_editor_saves_the_list_logs_fields_changed_and_the_disable_button_keeps_it(): void
    {
        $ceo  = $this->user('CEO');
        $task = $this->task(['department' => 'Ops']);
        $two  = [
            ['key' => 'total_sticker', 'label' => 'Total sticker', 'type' => 'number', 'hint' => 'after TOTAL STICKER'],
            ['key' => 'pending_rate', 'label' => 'Pending rate', 'type' => 'percent', 'hint' => null],
        ];

        $this->actingAs($ceo)->patch(route('checklist.update-task', $task), [
            'title' => $task->title, 'type' => 'any',
            'extract_fields' => [$two[0], array_merge($two[1], ['hint' => ''])],
        ])->assertSessionHasNoErrors();

        $this->assertSame($two, $task->fresh()->extract_fields);
        $row = DB::table('checklist_task_changes')->sole();
        $this->assertSame('fields_changed', $row->action);
        $this->assertSame(['extract_fields' => [null, $two]], json_decode($row->changes, true));

        // Ang "Disable" form ay walang extract_fields: hindi nagagalaw ang fields.
        $this->actingAs($ceo)->patch(route('checklist.update-task', $task), ['title' => $task->title, 'type' => 'any', 'is_active' => '0']);
        $this->assertSame($two, $task->fresh()->extract_fields);

        // Inalis ang lahat ng row: ang form ay nagpapadala ng blangkong extract_fields.
        $this->actingAs($ceo)->patch(route('checklist.update-task', $task), ['title' => $task->title, 'type' => 'any', 'extract_fields' => '']);
        $this->assertNull($task->fresh()->extract_fields);
    }

    public function test_saving_the_same_fields_logs_nothing_even_when_the_database_reorders_the_json_keys(): void
    {
        $task = $this->task();
        // Gaya ng JSON column ng MySQL: iba ang ayos ng keys pagbasa
        DB::table('checklist_tasks')->where('id', $task->id)
            ->update(['extract_fields' => '[{"key": "total_sticker", "hint": null, "type": "number", "label": "Total sticker"}]']);

        $ceo = $this->user('CEO');
        $this->actingAs($ceo)->patch(route('checklist.update-task', $task), [
            'title' => $task->title, 'type' => 'any',
            'extract_fields' => [['key' => 'total_sticker', 'label' => 'Total sticker', 'type' => 'number', 'hint' => '']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('checklist_task_changes')->count());
        $this->actingAs($ceo)->get(route('checklist.manage'))->assertOk()->assertSee('📊 Total sticker');
    }

    public function test_a_new_task_can_be_created_with_fields(): void
    {
        $this->actingAs($this->user('CEO'))->post(route('checklist.store-task'), [
            'title' => 'Bilang ng sticker', 'type' => 'note',
            'extract_fields' => [['key' => 'total_sticker', 'label' => 'Total sticker', 'type' => 'number']],
        ])->assertSessionHasNoErrors();

        $expected = [['key' => 'total_sticker', 'label' => 'Total sticker', 'type' => 'number', 'hint' => null]];
        $this->assertSame($expected, ChecklistTask::sole()->extract_fields);
        $this->assertSame([null, $expected], json_decode(DB::table('checklist_task_changes')->sole()->changes, true)['extract_fields']);
    }

    public static function badFields(): array
    {
        return [
            'duplicate key'      => [[['key' => 'total', 'label' => 'A', 'type' => 'number'], ['key' => 'total', 'label' => 'B', 'type' => 'money']], 'extract_fields.1.key'],
            'bad type'           => [[['key' => 'total', 'label' => 'A', 'type' => 'currency']], 'extract_fields.0.type'],
            'key not snake_case' => [[['key' => 'Total Sticker', 'label' => 'A', 'type' => 'number']], 'extract_fields.0.key'],
            'no label'           => [[['key' => 'total', 'label' => '', 'type' => 'number']], 'extract_fields.0.label'],
        ];
    }

    #[DataProvider('badFields')]
    public function test_the_fields_editor_refuses_a_bad_list(array $fields, string $errorKey): void
    {
        $task = $this->task();

        $this->actingAs($this->user('CEO'))
            ->patch(route('checklist.update-task', $task), ['title' => $task->title, 'type' => 'any', 'extract_fields' => $fields])
            ->assertSessionHasErrors($errorKey);

        $this->assertNull($task->fresh()->extract_fields);
        $this->assertSame(0, DB::table('checklist_task_changes')->count());
    }

    // ── Extraction ────────────────────────────────────────────────────────────

    public function test_a_save_asks_for_the_values_in_the_approval_call_and_stores_them_normalised(): void
    {
        $this->aiAnswer = $this->answer(self::ANSWER);
        [$staff, $task] = $this->fieldTask();

        $this->actingAs($staff)->post(route('checklist.submit', $task), ['notes' => 'TOTAL STICKER (WAREHOUSE MAKATI) : 1,612'])
            ->assertSessionHasNoErrors();

        $v = ChecklistSubmissionValue::all()->keyBy('field_key');
        $this->assertSame(array_column(self::FIELDS, 'key'), $v->keys()->all());
        $this->assertSame(1612.0, $v['total_sticker']->value_number);
        $this->assertSame('1,612', $v['total_sticker']->raw);
        $this->assertSame(2295.67, $v['bank_balance']->value_number);
        $this->assertSame(2.01, $v['pending_rate']->value_number);
        $this->assertSame('Naka-post na lahat', $v['remarks']->value_text);
        $this->assertSame('2026-10-14', $v['report_date']->value_text);
        $this->assertTrue($v['all_posted']->value_bool);
        $this->assertNull($v['chatgpt_balance']->value_number);
        $this->assertNull($v['chatgpt_balance']->raw);
        $this->assertSame([now()->toDateString()], $v->pluck('date')->map(fn ($d) => substr($d, 0, 10))->unique()->values()->all());
        $this->assertSame('ai', $v['remarks']->source);

        $approval = DB::table('checklist_analysis_logs')->where('log_type', 'approval')->sole();
        $this->assertSame('approved', $approval->verdict);
        $this->assertStringNotContainsString('EXTRACTED_VALUES', $approval->analysis_result);
        // Iisang approval call ang may tanong para sa values, kasama ang hint.
        $asks = collect(Http::recorded())->filter(fn ($pair) => str_contains($this->body($pair[0]), 'EXTRACTED_VALUES'));
        $this->assertCount(1, $asks);
        $this->assertStringContainsString('Bank balance in the second screenshot', $this->body($asks->first()[0]));
        $this->assertStringContainsString('APPROVED', $this->body($asks->first()[0]));
    }

    /** Ang sagot ng AI para sa isang field → ang naka-save. */
    public static function normalisedValues(): array
    {
        return [
            'thousands comma'        => ['number', '1,612', 'value_number', 1612.0],
            'plain number'           => ['number', '1612', 'value_number', 1612.0],
            'JSON number'            => ['number', 1645, 'value_number', 1645.0],
            'decimal comma percent'  => ['percent', '2,01%', 'value_number', 2.01],
            'peso amount'            => ['money', '₱2,295.67', 'value_number', 2295.67],
            'millions'               => ['number', '1,612,000', 'value_number', 1612000.0],
            'dash before a space'    => ['number', 'TOTAL RETRIEVED ORDER- 23', 'value_number', 23.0],
            'negative peso'          => ['money', '-₱500', 'value_number', -500.0],
            'no number'              => ['number', 'n/a', 'value_number', null],
            'not found'              => ['money', null, 'value_number', null],
            'date m/d/Y'             => ['date', '10/14/2026', 'value_text', '2026-10-14'],
            'date without digits'    => ['date', 'today', 'value_text', null],
            'yes_no true'            => ['yes_no', true, 'value_bool', true],
            'yes_no Tagalog no'      => ['yes_no', 'Hindi', 'value_bool', false],
            'yes_no unclear'         => ['yes_no', 'maybe', 'value_bool', null],
            'text trimmed'           => ['text', '  ok na  ', 'value_text', 'ok na'],
        ];
    }

    #[DataProvider('normalisedValues')]
    public function test_values_are_normalised_before_storing(string $type, mixed $raw, string $column, mixed $expected): void
    {
        $this->assertSame($expected, ChecklistSubmissionValue::fromAi($type, $raw)[$column]);
    }

    public static function malformedAnswers(): array
    {
        return [
            'cut-off JSON'  => ["APPROVED\n\nKumpleto.\nEXTRACTED_VALUES: {\"total_sticker\": \"1,612\""],
            'no values line' => ["APPROVED\n\nKumpleto."],
            'a list'        => ["APPROVED\n\nKumpleto.\nEXTRACTED_VALUES: [\"1,612\"]"],
        ];
    }

    #[DataProvider('malformedAnswers')]
    public function test_a_malformed_answer_stores_nothing_logs_a_warning_and_the_verdict_still_saves(string $answer): void
    {
        Log::spy();
        $this->aiAnswer = $answer;
        [$staff, $task] = $this->fieldTask();

        $this->actingAs($staff)->post(route('checklist.submit', $task), ['notes' => 'Tapos na'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(0, ChecklistSubmissionValue::count());
        $this->assertSame('approved', DB::table('checklist_analysis_logs')->where('log_type', 'approval')->sole()->verdict);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'values'))->once();
    }

    public function test_a_rerun_of_the_approval_check_replaces_the_values_and_a_bad_rerun_keeps_them(): void
    {
        $this->aiAnswer = $this->answer(self::ANSWER);
        [$staff, $task] = $this->fieldTask();
        $this->actingAs($staff)->post(route('checklist.submit', $task), ['notes' => 'TOTAL STICKER : 1,612']);
        $submission = ChecklistSubmission::sole();

        $this->aiAnswer = "NOT APPROVED\n\nKulang.\nEXTRACTED_VALUES: {\"total_sticker\": \"1,700\"}";
        $this->actingAs($this->user('CEO'))
            ->postJson(route('checklist.approval-check', $submission))
            ->assertOk()->assertJson(['verdict' => 'not_approved', 'analysis' => "NOT APPROVED\n\nKulang."]);

        $v = ChecklistSubmissionValue::all()->keyBy('field_key');
        $this->assertCount(count(self::FIELDS), $v);
        $this->assertSame(1700.0, $v['total_sticker']->value_number);
        $this->assertNull($v['bank_balance']->value_number);   // wala sa bagong sagot

        $this->aiAnswer = "APPROVED\n\nOk.\nEXTRACTED_VALUES: {oops";
        // Ikatlong check na ito (kasama ang awtomatiko sa pagsumite), kaya CEO ang nagpapatakbo
        $this->actingAs($this->user('CEO'))->postJson(route('checklist.approval-check', $submission))
            ->assertOk()->assertJson(['verdict' => 'approved']);
        $this->assertSame(1700.0, ChecklistSubmissionValue::where('field_key', 'total_sticker')->sole()->value_number);
    }

    public function test_a_task_without_fields_is_not_asked_for_values_and_gets_none(): void
    {
        $this->aiAnswer = $this->answer(['total_sticker' => '1,612']);
        $staff = $this->user('Staff', 'Ops');
        $task  = $this->task([], [$staff]);

        $this->actingAs($staff)->post(route('checklist.submit', $task), ['notes' => 'TOTAL STICKER : 1,612']);

        $this->assertSame(0, ChecklistSubmissionValue::count());
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($this->body($r), 'EXTRACTED_VALUES'));
    }

    // ── Report ────────────────────────────────────────────────────────────────

    public function test_the_day_report_shows_the_values_as_chips_under_the_submission(): void
    {
        [$staff, $task] = $this->fieldTask();
        $sub = $this->submission($task, $staff, now()->toDateString());
        $this->value($sub, 'total_sticker', ['value_number' => 1612]);
        $this->value($sub, 'bank_balance', ['value_number' => 2295.67]);
        $this->value($sub, 'all_posted', ['value_bool' => false]);

        $this->actingAs($this->user('CEO'))->get(route('checklist.report'))
            ->assertOk()
            ->assertSeeInOrder(['Total sticker:', '1,612', 'Bank balance:', '₱2,295.67', 'All posted:', 'No']);
    }

    public function test_the_values_csv_has_a_header_and_a_row_and_follows_the_range_visibility(): void
    {
        [$staffA, $taskA] = $this->fieldTask('Bilang ng sticker');
        [$staffB, $taskB] = $this->fieldTask('Balanse ng bangko');
        $day = now()->subDay()->toDateString();
        $this->value($this->submission($taskA, $staffA, $day), 'pending_rate', ['value_number' => 2.01]);
        $this->value($this->submission($taskB, $staffB, $day), 'remarks', ['value_text' => '=SUM(A1)']);

        $csv = fn (User $u) => array_map('str_getcsv', explode("\n", trim(
            $this->actingAs($u)->get(route('checklist.report', ['view' => 'range', 'csv' => 'values']))->assertOk()->streamedContent()
        )));

        $all = $csv($this->user('CEO'));
        $this->assertSame(['Date', 'Task', 'Field', 'Value', 'Submitter'], $all[0]);
        $this->assertContains([$day, 'Bilang ng sticker', 'Pending rate', '2.01', $staffA->name], $all);
        $this->assertContains([$day, 'Balanse ng bangko', 'Remarks', "'=SUM(A1)", $staffB->name], $all);

        $mine = $csv($staffB);
        $this->assertCount(2, $mine);   // header + ang task niya lang
        $this->assertSame('Balanse ng bangko', $mine[1][1]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: ChecklistTask} */
    private function fieldTask(string $title = 'Bilang ng sticker'): array
    {
        $staff = $this->user('Staff', 'Ops');

        return [$staff, $this->task(['title' => $title, 'department' => 'Ops', 'extract_fields' => self::FIELDS], [$staff])];
    }

    private function answer(array $values): string
    {
        return "APPROVED\n\nKumpleto ang submission.\nEXTRACTED_VALUES: " . json_encode($values, JSON_UNESCAPED_UNICODE);
    }

    private function value(ChecklistSubmission $sub, string $key, array $columns): void
    {
        ChecklistSubmissionValue::create(array_merge([
            'submission_id'     => $sub->id,
            'checklist_task_id' => $sub->checklist_task_id,
            'date'              => substr((string) $sub->getRawOriginal('date'), 0, 10),
            'field_key'         => $key,
            'source'            => 'ai',
        ], $columns));
    }

    private function body(HttpRequest $request): string
    {
        return json_encode($request->data(), JSON_UNESCAPED_UNICODE);
    }
}
