<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Support\Facades\DB;

/**
 * Pinning ng kasalukuyang Action note (saveAction + actionLogs) — hindi dapat magbago.
 */
class ActionNoteCharacterizationTest extends OwnerPrivateTestCase
{
    public function test_marketing_saves_action_note_and_reads_its_log(): void
    {
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->postJson('/owner/private/action', [
            'page_key' => 'PAGE A',
            'ts_date'  => '2026-10-03',
            'comment'  => '  taasan ang budget  ',
        ])->assertOk()->assertJson([
            'ok'         => true,
            'page_key'   => 'PAGE A',
            'ts_date'    => '2026-10-03',
            'comment'    => 'taasan ang budget',
            'updated_by' => 'Marketing User',
        ]);

        $this->assertSame('taasan ang budget', DB::table('page_day_actions')->value('comment'));
        $this->assertSame(1, DB::table('page_day_action_logs')->count());

        $this->getJson('/owner/private/action/logs?page_key=' . urlencode('PAGE A') . '&ts_date=2026-10-03')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('logs.0.old', null)
            ->assertJsonPath('logs.0.new', 'taasan ang budget')
            ->assertJsonPath('logs.0.by', 'Marketing User');
    }
}
