<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\ChatbotPage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang listahan ng variable sa mga form ng editor, hinati sa System, Botfields at Customer fields, may tooltip kung
 * saan galing ang bawat isa at may tanda kapag ang pangalan ay nasa dalawang grupo.
 */
class ChatbotVariableGroupsTest extends ChatblastTestCase
{
    private array $groups;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
        ]]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
        $this->actingAs($this->user());

        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Hi']], 'wait' => true, 'save_to' => 'Reply One']],
            ['id' => 'n4', 'type' => 'ai', 'config' => ['system' => 'x', 'save_to' => 'GPT INPUT']],
            ['id' => 'n5', 'type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'gpt input', 'value' => 'a'], ['op' => 'append', 'field' => 'Price', 'value' => 'b']]]],
        ], 'edges' => []])]);
        app(ChatbotPage::class)->link('Page Uno', $flow);
        foreach ([[1001, 'cxd'], [1001, '__wait'], [1001, 'last input'], [2002, 'other page only']] as [$page, $key]) {
            DB::table('chatbot_customer_values')->insert(['page_id' => $page, 'psid' => 77, 'key' => $key, 'value' => 'v', 'updated_at' => now()]);
        }

        $rows = $this->getJson("/chatbot/flows/{$flow->id}/graph")->assertOk()->json('variable_groups');
        $this->groups = [];
        foreach ($rows as $r) $this->groups[$r['group']][$r['name']] = $r;
    }

    public function test_S_097_1_each_name_is_in_its_group(): void
    {
        $this->assertSame(['last_input', 'full_name', 'first_name', 'psid', 'page_id', 'page_name', 'current_date', 'last_interaction', 'replies_via'], array_keys($this->groups['system']));
        foreach (['PRICE', 'Prompt 1', 'Seq 1', 'Seq Img 20'] as $name) $this->assertArrayHasKey($name, $this->groups['botfield']);
        $this->assertSame(['REPLY ONE', 'GPT INPUT', 'PRICE', 'CXD', 'LAST INPUT'], array_keys($this->groups['customer']));
        $this->assertSame('Built in: the customer\'s name on Facebook', $this->groups['system']['full_name']['tip']);
        $this->assertSame('Botfield: set per page on /chatbot/pages', $this->groups['botfield']['Prompt 1']['tip']);
    }

    public function test_S_097_2_a_customer_field_lists_the_nodes_that_write_it(): void
    {
        $this->assertSame('Customer field: set by n4 (AI), n5 (Set field)', $this->groups['customer']['GPT INPUT']['tip']);
        $this->assertSame('Customer field: set by n3 (Send message)', $this->groups['customer']['REPLY ONE']['tip']);
        $this->assertSame('Customer field: saved on customers of this flow\'s pages; no step of this flow sets it', $this->groups['customer']['CXD']['tip']);
    }

    public function test_S_097_3_reserved_keys_and_other_pages_are_left_out(): void
    {
        $names = array_merge(...array_map('array_keys', array_values($this->groups)));
        $this->assertNotContains('__WAIT', $names);
        $this->assertNotContains('OTHER PAGE ONLY', $names);
    }

    public function test_S_097_4_a_name_in_two_groups_is_flagged_in_both(): void
    {
        $this->assertSame(['customer'], $this->groups['botfield']['PRICE']['also']);
        $this->assertSame(['botfield'], $this->groups['customer']['PRICE']['also']);
        $this->assertSame(['customer'], $this->groups['system']['last_input']['also']);
        $this->assertSame(['system'], $this->groups['customer']['LAST INPUT']['also']);
        $this->assertSame([], $this->groups['customer']['CXD']['also']);
        $this->assertSame('Botfield: set per page on /chatbot/pages. Also a customer field; the customer field wins when the customer has it saved (even blank)', $this->groups['botfield']['PRICE']['tip']);
        $this->assertSame('Customer field: set by n5 (Set field). Also a botfield; the customer field wins when the customer has it saved (even blank)', $this->groups['customer']['PRICE']['tip']);
        $this->assertSame('Built in: what the customer just typed. Also a customer field; the built-in value always wins', $this->groups['system']['last_input']['tip']);
        $this->assertSame('Customer field: saved on customers of this flow\'s pages; no step of this flow sets it. Also a system variable; the built-in value always wins, so this field can\'t be read', $this->groups['customer']['LAST INPUT']['tip']);
    }
}
