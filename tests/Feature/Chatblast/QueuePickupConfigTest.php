<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\TicketPool;
use Tests\TestCase;

/**
 * Ang agarang pagkuha ng job (spec 102): blocking pop sa Redis queue (block_for), ang predis read_write_timeout na laging
 * mas mataas, ang idle sleep ng Horizon, at ang idle at busy na pagitan ng mga pool, lahat mula sa env.
 */
class QueuePickupConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("$key=$value");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        parent::tearDown();
    }

    private function env(array $vars): void
    {
        foreach ($vars as $key => $value) {
            if (!array_key_exists($key, $this->saved)) $this->saved[$key] = getenv($key);
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }
            putenv("$key=$value");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    private function load(string $file): array
    {
        return require config_path($file);
    }

    public function test_S_102_1_redis_queue_blocks_and_the_predis_timeout_is_safely_above_it(): void
    {
        // Ang defaults, hindi ang env ng makina.
        $this->env(['REDIS_QUEUE_BLOCK_FOR' => null, 'REDIS_CLIENT' => null, 'REDIS_READ_WRITE_TIMEOUT' => null, 'REDIS_QUEUE_CONNECTION' => null]);
        $queue = $this->load('queue.php')['connections']['redis'];
        $redis = $this->load('database.php')['redis'];
        $conn = $redis[$queue['connection']];

        $this->assertSame(5, $queue['block_for']);
        $this->assertSame('predis', $redis['client']);
        $this->assertEquals(60, $conn['read_write_timeout']);
        $this->assertGreaterThanOrEqual($queue['block_for'] * 2, $conn['read_write_timeout']);
    }

    public function test_S_102_2_horizon_chatbot_and_chatblast_do_not_sleep_when_the_pop_blocks(): void
    {
        $horizon = $this->load('horizon.php')['defaults'];

        foreach (['chatbot', 'chatblast'] as $name) {
            $this->assertSame('redis', $horizon[$name]['connection']);
            $this->assertSame(0, $horizon[$name]['sleep'], $name);
            // balance auto: isang queue kada proseso, kaya ang block sa isang queue ay hindi nagpapabagal sa kabila.
            $this->assertSame('auto', $horizon[$name]['balance'], $name);
        }
    }

    public function test_S_102_3_block_for_zero_restores_polling(): void
    {
        foreach (['0', '-1'] as $value) {
            $this->env(['REDIS_QUEUE_BLOCK_FOR' => $value]);

            // null, hindi 0: ang 0 sa blpop ay naghihintay nang walang hanggan.
            $this->assertNull($this->load('queue.php')['connections']['redis']['block_for'], $value);
            $horizon = $this->load('horizon.php')['defaults'];
            $this->assertSame(3, $horizon['chatbot']['sleep'], $value);
            $this->assertSame(3, $horizon['chatblast']['sleep'], $value);
        }
    }

    public function test_S_102_4_pool_intervals_come_from_config_with_env_overrides(): void
    {
        $pools = $this->load('queue.php')['pools'];
        $this->assertSame(200, $pools['idle_ms']);
        $this->assertSame(5, $pools['busy_ms']);

        $this->env(['CHATBOT_POOL_IDLE_MS' => '100', 'CHATBOT_POOL_BUSY_MS' => '2']);
        $this->assertSame(['idle_ms' => 100, 'busy_ms' => 2], $this->load('queue.php')['pools']);

        config(['queue.pools.idle_ms' => 150, 'queue.pools.busy_ms' => 3]);
        $this->assertSame(150_000, TicketPool::pause(false));
        $this->assertSame(3_000, TicketPool::pause(true));
    }
}
