<?php

namespace Tests\Feature\SystemMonitor;

use App\Services\ServerStorage;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/** Spec 242 S1: the Storage view (/system-monitor/storage) for the marketing OIC and the CEO. */
class StorageViewTest extends StorageTestCase
{
    private const URL = '/system-monitor/storage';

    public static function oicRoles(): array
    {
        return [
            'exact'               => ['Marketing - OIC'],
            'spaces and case'     => ['  marketing   -  OIC '],
        ];
    }

    #[DataProvider('oicRoles')]
    public function test_S1_1_the_marketing_oic_sees_disk_database_and_binary_logs(string $role): void
    {
        $this->storage->disk = ['total' => 193 * self::GB, 'free' => 41 * self::GB, 'used' => 152 * self::GB, 'percent' => 152 / 193 * 100];
        $this->storage->databaseBytes = 12.5 * self::GB;
        $this->storage->binaryLogs = ['bytes' => 127 * self::GB, 'files' => 42];

        $this->actingAs($this->user($role))
            ->get(self::URL)
            ->assertOk()
            ->assertSeeInOrder(['Disk (/)', 'Total', '193.0 GB', 'Used', '152.0 GB', 'Free', '41.0 GB', 'Used %', '78.8%'])
            ->assertSeeInOrder(['Database size', '12.5 GB'])
            ->assertSeeInOrder(['MySQL binary logs', '127.0 GB', 'in 42 files']);
    }

    public function test_S1_2_the_ceo_sees_the_storage_view_and_the_system_monitor_as_today(): void
    {
        $this->diskAt(50);
        $this->storage->binaryLogs = ['bytes' => 1 * self::GB, 'files' => 1];
        $this->actingAs($this->user('CEO'));

        $this->get(self::URL)->assertOk()->assertSee('100.0 GB')->assertSee('in 1 file');
        $this->get('/system-monitor')->assertOk()->assertSee('System Monitor (CEO)');
    }

    public function test_S1_2_the_oic_gets_only_the_storage_view_not_the_rest_of_the_monitor(): void
    {
        $this->actingAs($this->user('Marketing - OIC'));

        $this->get('/system-monitor')->assertNotFound();
        $this->get('/system-monitor/data')->assertNotFound();
    }

    public static function otherRoles(): array
    {
        return [
            'marketing'     => ['Marketing'],
            'encoder oic'   => ['Encoder - OIC'],
            'oic, no dash'  => ['Marketing OIC'],
            'no role'       => [null],
        ];
    }

    #[DataProvider('otherRoles')]
    public function test_S1_3_other_roles_get_404(?string $role): void
    {
        $this->diskAt(50);

        $this->actingAs($this->user($role))->get(self::URL)->assertNotFound();
        $this->assertSame(0, $this->storage->diskCalls, 'nothing is measured for a refused user');
    }

    public function test_S1_4_refused_binary_logs_say_not_available_and_the_page_renders(): void
    {
        // binaryLogs stays the real one: SHOW BINARY LOGS fails on this database as it is refused on the live server.
        $this->diskAt(50);
        $this->storage->databaseBytes = 2 * self::GB;
        Log::spy();

        $this->actingAs($this->user('Marketing - OIC'))
            ->get(self::URL)
            ->assertOk()
            ->assertSeeInOrder(['MySQL binary logs', 'Not available'])
            ->assertSeeInOrder(['Database size', '2.0 GB']);

        $this->assertSame(1, $this->storage->binaryLogCalls);
        // The layout's checklist badge logs a warning here because its tables are not in this test database; that is
        // not ours, so only that one line is allowed.
        $notChecklist = \Mockery::on(fn ($message) => stripos((string) $message, 'Checklist badge') === false);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning'] as $level) {
            Log::shouldNotHaveReceived($level, [$notChecklist]);
        }
    }

    public function test_S1_4_the_real_class_returns_null_when_the_server_refuses_and_sums_the_rows_otherwise(): void
    {
        $real = new ServerStorage();
        $this->assertNull($real->binaryLogs(), 'SHOW BINARY LOGS refused: null, no exception');
        $this->assertNull($real->databaseBytes(), 'information_schema not readable: null, no exception');

        $rows = new class extends ServerStorage {
            protected function showBinaryLogs(): array
            {
                return [
                    (object) ['Log_name' => 'binlog.000001', 'File_size' => 1073741824, 'Encrypted' => 'No'],
                    (object) ['LOG_NAME' => 'binlog.000002', 'FILE_SIZE' => '536870912'],
                ];
            }
        };
        $this->assertSame(['bytes' => 1610612736.0, 'files' => 2], $rows->binaryLogs());
    }

    public function test_S1_4_a_failed_disk_read_says_not_available(): void
    {
        $this->storage->disk = null;

        $this->actingAs($this->user('CEO'))->get(self::URL)->assertOk()->assertSeeInOrder(['Disk (/)', 'Not available']);
    }

    public function test_S1_5_the_view_is_read_only(): void
    {
        $this->diskAt(95);
        $user = $this->user('Marketing - OIC');
        $this->actingAs($user);

        $page   = $this->get(self::URL)->assertOk()->getContent();
        $layout = (string) $this->blade('<x-layout>body</x-layout>');

        // The layout has its own controls (logout); the view adds none.
        foreach (['<form', '<button', '<input', '<select', '<textarea'] as $tag) {
            $this->assertSame(substr_count($layout, $tag), substr_count($page, $tag), "the view adds no {$tag}");
        }
    }
}
