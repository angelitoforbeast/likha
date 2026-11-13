<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistTask;

/**
 * 270: ang description ng task ay instructions na may maraming linya at clickable na link,
 * sa /checklist/manage (textarea, hanggang 3000) at sa staff page at report (escaped, may <br> at <a>).
 */
class ChecklistInstructionsTest extends ChecklistTestCase
{
    private const STEPS = "1) Buksan ang /ads_manager/daily-output\r\n2) Tingnan ang https://likhaaitech.com/encoding/timetable\r\n- <script>alert(1)</script> \"><img src=x onerror=alert(1)> javascript:alert(1)";

    private const SAVED = "1) Buksan ang /ads_manager/daily-output\n2) Tingnan ang https://likhaaitech.com/encoding/timetable\n- <script>alert(1)</script> \"><img src=x onerror=alert(1)> javascript:alert(1)";

    private function form(array $extra = []): array
    {
        return ['title' => 'Ads Daily Output Check', 'type' => 'any', ...$extra];
    }

    public function test_s_270_1_the_add_form_saves_several_lines_up_to_3000_characters(): void
    {
        $ceo = $this->user('CEO');

        $this->actingAs($ceo)->post(route('checklist.store-task'), $this->form(['description' => self::STEPS]))
            ->assertSessionHasNoErrors();
        $this->assertSame(self::SAVED, ChecklistTask::latest('id')->first()->description);

        // 3000 na character kasama ang mga linya (CRLF ng browser = isang linya).
        $long = str_repeat(str_repeat('a', 98)."\r\n", 30);   // 30 × (98 + 2) = 3000 bago, 2970 pagkatapos
        $long .= str_repeat('b', 30);
        $this->actingAs($ceo)->post(route('checklist.store-task'), $this->form(['description' => $long]))
            ->assertSessionHasNoErrors();
        $this->assertSame(3000, mb_strlen(ChecklistTask::latest('id')->first()->description));

        $this->actingAs($ceo)->post(route('checklist.store-task'), $this->form(['description' => str_repeat('x', 3001)]))
            ->assertSessionHasErrors('description');
        $this->assertSame(2, ChecklistTask::count());
    }

    public function test_s_270_1_the_edit_form_is_a_textarea_that_keeps_the_lines(): void
    {
        $ceo = $this->user('CEO');
        $task = $this->task(['description' => 'Isang linya lang']);

        $this->actingAs($ceo)->patch(route('checklist.update-task', $task), $this->form(['description' => self::STEPS]))
            ->assertSessionHasNoErrors();
        $this->assertSame(self::SAVED, $task->fresh()->description);

        $this->actingAs($ceo)->get(route('checklist.manage'))->assertOk()
            ->assertSee('<textarea name="description" rows="5" maxlength="3000"', false)
            ->assertSee('>'.e(self::SAVED).'</textarea>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<input type="text" name="description"', false);
    }

    public function test_s_270_2_and_3_the_staff_page_shows_the_lines_and_links_and_nothing_runs(): void
    {
        $staff = $this->user('Staff', 'Marketing');
        $this->task(['title' => 'Ads Daily Output Check', 'description' => self::SAVED], [$staff]);

        $this->actingAs($staff)->get(route('checklist.index'))->assertOk()
            ->assertSee('1) Buksan ang <a href="/ads_manager/daily-output" target="_blank" rel="noopener"', false)
            ->assertSee('<br>', false)
            ->assertSee('<a href="https://likhaaitech.com/encoding/timetable" target="_blank" rel="noopener"', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; &quot;&gt;&lt;img src=x onerror=alert(1)&gt; javascript:alert(1)', false)
            ->assertDontSee('<script>alert(1)', false)
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('href="javascript', false);
    }

    public function test_s_270_2_the_report_shows_the_same_rendering(): void
    {
        $ceo = $this->user('CEO');
        $staff = $this->user('Staff', 'Marketing');
        $this->task(['title' => 'Ads Daily Output Check', 'description' => self::SAVED], [$staff]);

        $this->actingAs($ceo)->get(route('checklist.report'))->assertOk()
            ->assertSee('<a href="/ads_manager/daily-output" target="_blank" rel="noopener"', false)
            ->assertDontSee('<script>alert(1)', false)
            ->assertDontSee('<img src=x', false);
    }

    public function test_s_270_4_a_one_line_description_looks_as_before(): void
    {
        $staff = $this->user('Staff', 'Ops');
        $this->task(['description' => 'Linisin ang bodega & ang mesa'], [$staff]);

        $this->actingAs($staff)->get(route('checklist.index'))->assertOk()
            ->assertSee('Linisin ang bodega &amp; ang mesa', false);
    }
}
