<?php

namespace Tests\Feature\Chatblast;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Spec 199, ang mga tuntunin ng animation ng /chatbot/live (public/js/chatbot-live-rules.js), pinapatakbo sa node gaya ng
 * ibang pure function ng repo (tests/js/call.cjs): ang money packet at ang chip nito, ang pagsasama ng sunod-sunod na order,
 * ang hindi pagbaba ng bilang sa loob ng isang minuto, at ang reduced motion.
 */
class ChatbotLiveRulesTest extends TestCase
{
    /** @param array<int, array{0: string, ...}> $calls [fn, ...args] @return array<int, mixed> ang sagot ng bawat tawag */
    private function js(array $calls): array
    {
        try {
            $probe = new Process(['node', '--version']);
            $probe->run();
            $found = $probe->isSuccessful();
        } catch (\Throwable $e) {
            $found = false;
        }
        if (!$found) $this->markTestSkipped('skipped: node not found');
        $p = new Process(['node', base_path('tests/js/call.cjs'), public_path('js/chatbot-live-rules.js'), 'LiveRules']);
        $p->setInput(json_encode(array_map(fn ($c) => ['fn' => $c[0], 'args' => array_slice($c, 1)], $calls), JSON_THROW_ON_ERROR));
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'node: ' . trim($p->getErrorOutput()));
        $out = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($out as $o) $this->assertArrayNotHasKey('error', $o, json_encode($o));

        return array_column($out, 'value');
    }

    /** Gaya ng js(), pero ang args pagkatapos ng bawat tawag (para makita ang binago ng function, hal. ang storage). */
    private function jsArgs(array $calls): array
    {
        $this->js([]); // node check (skip kapag wala)
        $p = new Process(['node', base_path('tests/js/call.cjs'), public_path('js/chatbot-live-rules.js'), 'LiveRules']);
        $p->setInput(json_encode(array_map(fn ($c) => ['fn' => $c[0], 'args' => array_slice($c, 1)], $calls), JSON_THROW_ON_ERROR));
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'node: ' . trim($p->getErrorOutput()));

        return array_column(json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR), 'args');
    }

    private static function order(int $id, int|float|null $amount, ?string $bot = 'forbeast'): array
    {
        return ['type' => 'order', 'at' => '2026-10-21 12:29:0' . ($id % 10), 'page' => 'Page Uno', 'id' => $id, 'bot' => $bot, 'amount' => $amount];
    }

    private static function money(array $plan): array
    {
        return array_values(array_filter($plan['actions'], fn ($a) => $a['type'] === 'order'));
    }

    public function test_S_199_7_an_order_with_an_amount_plays_the_money_packet_and_without_one_says_plus_one_order(): void
    {
        [$paid, $none, $noBot] = $this->js([['schedule', [self::order(1, 1149)], []], ['schedule', [self::order(2, null)], []], ['schedule', [self::order(3, 2050.5, null)], []]]);

        $this->assertEquals(['kind' => 'money', 'at' => 0, 'label' => '+₱1,149', 'orders' => 1, 'peso' => 1149, 'route' => [['forbeast', false], ['ord', true]]],
            array_intersect_key(self::money($paid)[0], array_flip(['kind', 'at', 'label', 'orders', 'peso', 'route'])));
        $this->assertSame(['+1 ORDER', 1, 0], [self::money($none)[0]['label'], self::money($none)[0]['orders'], self::money($none)[0]['peso']]);
        // Walang bot ang order: mula sa core lang papunta sa Orders.
        $this->assertSame([['ord', true]], self::money($noBot)[0]['route']);
        $this->assertSame('+₱2,051', self::money($noBot)[0]['label']);
    }

    public function test_S_199_7_a_burst_of_orders_is_merged_into_at_most_one_packet_per_one_and_a_half_seconds_and_none_is_dropped(): void
    {
        $orders = [self::order(1, 1000), self::order(2, null), self::order(3, 200), self::order(4, 300), self::order(5, null)];
        $messages = array_map(fn ($i) => ['type' => 'message', 'at' => '2026-10-21 12:29:00', 'page' => 'Page Uno', 'id' => $i, 'bot' => 'forbeast'], range(1, 130));
        [$burst, $busy] = $this->js([['schedule', $orders, []], ['schedule', array_merge($messages, $orders), []]]);

        $m = self::money($burst);
        $this->assertSame([0, 1500, 3000], array_column($m, 'at'));
        $this->assertSame(['+₱1,000', '+1 ORDER', '+₱500 · 3 orders'], array_column($m, 'label'));
        $this->assertSame(5, array_sum(array_column($m, 'orders')));
        $this->assertSame(1500, array_sum(array_column($m, 'peso')));
        // 130 mensahe sa isang poll: hanggang 60 lang ang ilaw, pero ang lahat ng order ay nandoon pa rin.
        $this->assertLessThanOrEqual(60, count($busy['actions']) - count(self::money($busy)));
        $this->assertSame(5, array_sum(array_column(self::money($busy), 'orders')));
        $this->assertSame(130 - (count($busy['actions']) - count(self::money($busy))), $busy['sampled']);
    }

    public function test_S_199_7_numbers_hold_for_the_landing_and_never_count_down_inside_a_minute(): void
    {
        $st = ['shown' => 10, 'server' => 10, 'heldSince' => null, 'lastUp' => null];
        [$held, $rolled, $landed, $down, $downLater, $now] = $this->js([
            ['settle', $st, 11, 1000, 8000],                                                     // ang bilang ay nauna sa event: hinihintay
            ['settle', ['shown' => 10, 'server' => 11, 'heldSince' => 1000, 'lastUp' => null], 11, 9000, 8000], // 8 s na: umaakyat
            ['land', ['shown' => 10, 'server' => 11, 'heldSince' => 1000, 'lastUp' => null], 1, 3000],         // dumating ang packet
            ['settle', ['shown' => 12, 'server' => 12, 'heldSince' => null, 'lastUp' => 5000], 11, 64000, 8000], // pababa, wala pang minuto
            ['settle', ['shown' => 12, 'server' => 11, 'heldSince' => null, 'lastUp' => 5000], 11, 65000, 8000], // isang minuto na
            ['settle', $st, 14, 1000, 0],                                                        // walang hinihintay: tuloy agad
        ]);

        $this->assertSame([10, 1000], [$held['shown'], $held['heldSince']]);
        $this->assertSame([11, null], [$rolled['shown'], $rolled['heldSince']]);
        $this->assertSame([11, null, 3000], [$landed['shown'], $landed['heldSince'], $landed['lastUp']]);
        $this->assertSame(12, $down['shown']);
        $this->assertSame(11, $downLater['shown']);
        $this->assertSame(14, $now['shown']);
    }

    public function test_S_199_7_reduced_motion_shows_the_new_values_without_packets(): void
    {
        $events = [['type' => 'message', 'at' => '2026-10-21 12:29:00', 'page' => 'Page Uno', 'id' => 1, 'bot' => 'forbeast'], self::order(2, 1149), self::order(3, null)];
        [$plan] = $this->js([['schedule', $events, ['reduced' => true]]]);

        $this->assertSame(['hit', 'instant', 'instant'], array_column($plan['actions'], 'kind'));
        $this->assertSame([0, 0, 0], array_column($plan['actions'], 'at'));
        $this->assertSame(['+₱1,149', '+1 ORDER'], array_column(self::money($plan), 'label'));
    }

    public function test_S_200_5_an_amount_bursts_six_coins_that_roll_the_total_up_to_the_amount_and_null_drops_one_plus_one_plate(): void
    {
        [$paid, $cents, $none, $calm, $calmNone, $times] = $this->js([['loot', 599, []], ['loot', 2050.5, []], ['loot', 0, []], ['loot', 1149, ['reduced' => true]],
            ['loot', 0, ['reduced' => true]], ['landings', 6]]);

        // Anim na barya; ang bawat paglapag ay nagdadagdag ng bahagi nito, at ang kabuuan ay eksaktong ang halaga.
        $this->assertSame(['coins' => 6, 'plate' => false, 'parts' => [100, 100, 100, 100, 100, 99], 'label' => '+₱599', 'peso' => 599], $paid);
        $this->assertSame([342, 342, 342, 342, 341, 341.5], $cents['parts']);
        $this->assertEquals(2050.5, array_sum($cents['parts']));
        // Walang halaga: isang "+1" na plate, walang piso.
        $this->assertSame(['coins' => 1, 'plate' => true, 'parts' => [0], 'label' => '+1', 'peso' => 0], $none);
        // Reduced motion: walang barya; ang bagong halaga ay isang hakbang.
        $this->assertSame(['coins' => 0, 'plate' => false, 'parts' => [1149], 'label' => '+₱1,149', 'peso' => 1149], $calm);
        $this->assertSame(['coins' => 0, 'plate' => false, 'parts' => [0], 'label' => '+1', 'peso' => 0], $calmNone);
        // Ang paglapag: 110 ms ang pagitan ng labas, 380 ms ang pagsabog at 900 ms ang arko.
        $this->assertSame([1280, 1390, 1500, 1610, 1720, 1830], $times);
    }

    public function test_S_200_5_coin_landings_roll_the_total_without_counting_down_and_merged_bursts_keep_every_order(): void
    {
        // Ang server ay nauna (63,462) habang lumilipad ang mga barya: bawat paglapag ay umaakyat nang bahagi nito.
        $st = ['shown' => 62863, 'server' => 63462, 'heldSince' => 1000, 'lastUp' => null];
        $shown = [];
        foreach ([100, 100, 100, 100, 100, 99] as $i => $part) {
            [$st] = $this->js([['land', $st, $part, 2000 + $i * 110]]);
            $shown[] = $st['shown'];
        }
        $this->assertSame([62963, 63063, 63163, 63263, 63363, 63462], $shown);
        $this->assertNull($st['heldSince']);
        // Ang server ay luma pa (cache): hindi bumababa ang nakikita sa loob ng isang minuto.
        [$down] = $this->js([['settle', $st, 62863, 2600, 8000]]);
        $this->assertSame(63462, $down['shown']);

        // Sunod-sunod na 5 order sa isang poll: hanggang 3 pagsabog (1.5 s ang pagitan), ang huli ay pinagsama, walang nawawala.
        [$plan] = $this->js([['schedule', [self::order(1, 599), self::order(2, null), self::order(3, 200), self::order(4, 300), self::order(5, null)], []]]);
        $m = self::money($plan);
        $this->assertSame([599, 0, 500], array_column($m, 'peso'));
        $this->assertSame(5, array_sum(array_column($m, 'orders')));
        [$a, $b, $c] = $this->js([['loot', 599, []], ['loot', 0, []], ['loot', 500, []]]);
        $this->assertSame([6, 1, 6], [$a['coins'], $b['coins'], $c['coins']]);
    }

    public function test_S_200_5_the_3d_toggle_is_saved_and_restored_per_browser_and_2d_is_the_default(): void
    {
        [$none, $saved, $restored, $broken, $odd, $saveBroken] = $this->js([
            ['readView', (object) []], ['saveView', (object) [], '3d'], ['readView', ['likha-live-view' => '3d']], ['readView', null],
            ['readView', ['likha-live-view' => '<script>']], ['saveView', null, '3d'],
        ]);
        $after = $this->jsArgs([['saveView', (object) [], '3d'], ['saveView', ['likha-live-view' => '3d'], '2d']]);

        $this->assertSame('2d', $none);
        $this->assertTrue($saved);
        $this->assertSame(['likha-live-view' => '3d'], $after[0][0]);
        $this->assertSame('3d', $restored);
        $this->assertSame('2d', $broken);   // walang storage (private window): 2D, walang error
        $this->assertSame('2d', $odd);      // sirang value: 2D
        $this->assertFalse($saveBroken);
        $this->assertSame(['likha-live-view' => '2d'], $after[1][0]);
    }
}
