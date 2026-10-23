<?php

namespace Tests\Feature\OrderEncode;

use App\Models\ChatbotSequence;
use App\Services\OrderEncode\Settings;

/** Spec 136 E.40: ang kill switch ng Astra on the spot at ang sequence ng tanong sa address, sa /order-encode/final, CEO lang. */
class LiveSettingsTest extends LiveRowTestCase
{
    private array $users = [];

    private function as(string $role)
    {
        return $this->actingAs($this->users[$role] ??= $this->user($role, strtolower(str_replace(' ', '', $role)) . '@example.test'));
    }

    private function save(string $role, array $fields)
    {
        return $this->as($role)
            ->post('/order-encode/import-settings', $fields + ['switch' => 'off', 'cap' => 10]);
    }

    public function test_S_136_40_the_ceo_sets_the_switch_and_the_sequence_and_a_missing_sequence_is_refused(): void
    {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_05_100000_add_final_compare_to_order_encodes_table.php']]); // ang final page
        $seq = ChatbotSequence::create(['name' => 'Ask address <b>', 'active' => true, 'exit_on_reply' => true]);
        $settings = app(Settings::class);
        $this->assertSame([true, null], [$settings->spotAstra(), $settings->addressSequence()]); // defaults: on, walang tanong

        $this->save('CEO', ['spot_astra' => 'off', 'address_sequence' => (string) $seq->id])->assertRedirect();
        $this->assertSame([false, $seq->id], [$settings->spotAstra(), $settings->addressSequence()]);
        $this->as('CEO')->get('/order-encode/final')->assertOk()
            ->assertSee('Ask address &lt;b&gt;', false)->assertSee('name="address_sequence"', false);

        $this->save('CEO', ['address_sequence' => '999999'])->assertSessionHasErrors('address_sequence');
        $this->save('Marketing', ['spot_astra' => 'on', 'address_sequence' => ''])->assertNotFound();
        $this->assertSame([false, $seq->id], [$settings->spotAstra(), $settings->addressSequence()]);

        $this->save('CEO', ['spot_astra' => 'on', 'address_sequence' => ''])->assertRedirect();
        $this->assertSame([true, null], [$settings->spotAstra(), $settings->addressSequence()]);
    }
}
