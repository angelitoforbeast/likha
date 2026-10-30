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

    // ── Spec 205 (Jarvis E): ang vault, ang loot papunta roon, ang radar at ang ticker ──

    public function test_S_205_3_the_vault_stacks_one_disc_per_five_thousand_up_to_a_max_and_the_loot_says_plus_amount_or_plus_orders(): void
    {
        [$zero, $under, $one, $many, $cap, $bad, $paid, $none, $three, $calmThree, $times, $timesE] = $this->js([
            ['discs', 0], ['discs', 4999.99], ['discs', 5000], ['discs', 63055], ['discs', 9000000], ['discs', 'x'],
            ['loot', 599, ['orders' => 1]], ['loot', 0, ['orders' => 1]], ['loot', 0, ['orders' => 3]], ['loot', null, ['orders' => 3, 'reduced' => true]],
            ['landings', 6], ['landings', 6, ['gap' => 150, 'burst' => 300, 'fly' => 1100]],
        ]);

        $this->assertSame([0, 0, 1, 12, 14, 0], [$zero, $under, $one, $many, $cap, $bad]);
        $this->assertSame(['+₱599', 6, false], [$paid['label'], $paid['coins'], $paid['plate']]);
        $this->assertSame(['+1', 1, true, 0], [$none['label'], $none['coins'], $none['plate'], $none['peso']]);
        // Pinagsamang 3 order na wala pang halaga: isang plate na "+3"; reduced motion: walang plate, ang label pa rin.
        $this->assertSame(['+3', 1, true], [$three['label'], $three['coins'], $three['plate']]);
        $this->assertSame(['+3', 0, false], [$calmThree['label'], $calmThree['coins'], $calmThree['plate']]);
        // Ang dating oras (spec 200) ay pareho; ang E ay 150 ms ang pagitan, 300 ms ang labas at 1.1 s ang lipad sa globo.
        $this->assertSame([1280, 1390, 1500, 1610, 1720, 1830], $times);
        $this->assertSame([1400, 1550, 1700, 1850, 2000, 2150], $timesE);
    }

    public function test_S_205_2_radar_dots_sit_by_age_at_a_stable_angle_blip_once_for_a_new_customer_and_gold_comes_from_the_youngest_order(): void
    {
        $dots = [['k' => 'a1b2c3d4e5f6', 'ago' => 0, 's' => 'chat', 'new' => true, 'name' => 'Ana R.'],
            ['k' => '0f0f0f0f0f0f', 'ago' => 150, 's' => 'order', 'new' => false, 'name' => null],
            ['k' => '123456789abc', 'ago' => 40, 's' => 'order', 'new' => true, 'name' => null],
            ['k' => 'ffffffffffff', 'ago' => 400, 's' => 'ai', 'new' => true, 'name' => 'Old O.']];
        [$p0, $p1, $pOld, $again, $boot, $first, $second, $gold, $noGold] = $this->js([
            ['dotPos', 'a1b2c3d4e5f6', 0], ['dotPos', 'a1b2c3d4e5f6', 150], ['dotPos', 'a1b2c3d4e5f6', 999], ['dotPos', 'a1b2c3d4e5f6', 0],
            ['track', null, $dots], ['track', ['seen' => [], 'booted' => true], $dots], ['track', ['seen' => ['a1b2c3d4e5f6' => true, '123456789abc' => true, '0f0f0f0f0f0f' => true], 'booted' => true], $dots],
            ['goldDot', $dots], ['goldDot', [$dots[0]]],
        ]);

        // Ang anggulo ay pareho bawat poll (mula sa key); ang layo sa gitna ay ang tanda (gitna = ngayon, gilid = 5 minuto).
        $this->assertSame($p0['ang'], $again['ang']);
        $this->assertSame($p0['ang'], $p1['ang']);
        $this->assertGreaterThanOrEqual(0, $p0['ang']);
        $this->assertLessThan(2 * M_PI, $p0['ang']);
        $this->assertEqualsWithDelta(0.12, $p0['r'], 1e-9);
        $this->assertEqualsWithDelta(0.535, $p1['r'], 1e-9);
        $this->assertEqualsWithDelta(0.95, $pOld['r'], 1e-9);
        // Ang unang laman ng page ay walang blip; pagkatapos, ang bagong customer lang, isang beses; ang lampas sa 5 minuto ay wala.
        $this->assertSame([], $boot['blips']);
        $this->assertTrue($boot['state']['booted']);
        $this->assertSame([['k' => 'a1b2c3d4e5f6', 'name' => 'Ana R.'], ['k' => '123456789abc', 'name' => null]], $first['blips']);
        $this->assertSame([], $second['blips']);
        $this->assertSame(['a1b2c3d4e5f6', '0f0f0f0f0f0f', '123456789abc'], array_keys($first['state']['seen']));
        // Ang barya ay lumalabas sa pinakabatang gintong tuldok ng bot (o sa gitna kapag wala).
        $this->assertSame('123456789abc', $gold);
        $this->assertNull($noGold);
    }

    public function test_S_205_1_the_ticker_draws_one_icon_per_event_type(): void
    {
        $e = fn (string $type) => ['type' => $type, 'at' => '2026-10-21 12:29:00', 'page' => 'Page Uno', 'id' => 1, 'bot' => 'forbeast'];
        $this->assertSame(['in', 'sent', 'ai', 'seq', 'order', null], $this->js([['icon', $e('message')], ['icon', $e('confirmed')], ['icon', $e('ai')], ['icon', $e('follow_up')],
            ['icon', $e('order')], ['icon', $e('other')]]));
    }
}
