<?php

namespace Tests\Feature\Chatblast;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Spec 218, S-218.4: ang payload na isinusulat ng bawat job na may optional na property ay byte-for-byte kapareho ng isinulat
 * ng f5d6d450 (ang live na code bago ang ayos), kaya ang lumang code ay nakakabasa ng bagong payload at ang bagong code ng
 * luma. Ang fixture ay itinala sa f5d6d450 (RECORD_218_PAYLOADS=1 ang nagsusulat nito).
 */
class QueuedJobPayloadTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/218/payloads.json';

    /** @return array<string, array{0: class-string, 1: array}> two argument sets per class: required only, and every argument */
    public static function jobs(): array
    {
        $set = [
            \App\Jobs\RunChatbotSequenceStep::class => [[5], [5, '2026-11-03 01:00:00', '2026-11-03 00:50:00']],
            \App\Jobs\SendOrderToForbeast::class => [[4, 2], [4, 2, true]],
            \App\Jobs\RunChatblastSetupCheck::class => [[], [9]],
            \App\Jobs\RunChatbotContentCheck::class => [[], ['Page Uno']],
            \App\Jobs\SyncBotcakeCustomersJob::class => [['Page Uno'], ['Page Uno', true]],
            \App\Jobs\ImportBotcakePsidFromGoogleSheet::class => [[], ['2026-11-01 00:00:00', 7, true]],
            \App\Jobs\ImportMacroFromGoogleSheet::class => [[3], [3, 9]],
            \App\Jobs\ProcessAdsManagerReportsUpload::class => [['uploads/a.csv'], ['uploads/a.csv', 9, 11]],
            \App\Jobs\ProcessJntCreateOrdersBatch::class => [[3, [1, 2]], [3, [1, 2], 9]],
            \App\Jobs\ProcessPancakeConversationsUpload::class => [['uploads/p.csv'], ['uploads/p.csv', 9, 11]],
            \App\Jobs\ProcessPaymentActivityUpload::class => [['p.csv', 'o.csv', 'b1'], ['p.csv', 'o.csv', 'b1', 'staff', 's3', 4]],
            \App\Jobs\ExportJntStatusToGsheet::class => [[], ['Delivered', '2026-11-01 - 2026-11-02']],
            \App\Jobs\SyncFromJntStatusByTrack::class => [[3, '2026-11-01', '2026-11-02'], [3, '2026-11-01', '2026-11-02', false]],
            \App\Jobs\SyncJntOrderQueryByDate::class => [[3, '2026-11-01'], [3, '2026-11-01', 'Page Uno']],
            \App\Jobs\HandleComment::class => [[5]],
        ];
        $out = [];
        foreach ($set as $class => $args) foreach ($args as $i => $a) $out[class_basename($class) . '#' . $i] = [$class, $a];

        return $out;
    }

    private function payload(string $class, array $args): string
    {
        // Pinned here, not read from phpunit.xml or .env.testing: the recorded bytes carry these values (the connection and
        // queue the constructors set, and HandleComment's until timestamp).
        config(['queue.chatbot_connection' => 'sync', 'order_encode.forbeast.queue' => 'default', 'app.timezone' => 'Asia/Manila']);
        date_default_timezone_set('Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-11-03 01:00:00', 'Asia/Manila')); // HandleComment keeps a time

        return serialize(new $class(...$args));
    }

    public function test_S_218_4_every_payload_is_byte_for_byte_what_f5d6d450_writes(): void
    {
        if (getenv('RECORD_218_PAYLOADS') === '1') {
            $rec = [];
            foreach (self::jobs() as $key => [$class, $args]) $rec[$key] = base64_encode($this->payload($class, $args));
            @mkdir(dirname(self::FIXTURE), 0777, true);
            file_put_contents(self::FIXTURE, json_encode($rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->markTestSkipped('recorded ' . count($rec) . ' payloads');
        }
        $recorded = json_decode((string) file_get_contents(self::FIXTURE), true);
        $this->assertCount(29, $recorded);
        foreach (self::jobs() as $key => [$class, $args]) {
            $this->assertSame(base64_decode($recorded[$key]), $this->payload($class, $args), $key . ': the payload f5d6d450 writes');
        }
    }

    public function test_S_218_4_a_payload_written_now_is_read_by_the_f5d6d450_classes_the_same_way(): void
    {
        // The other direction: what this code writes is exactly the recorded f5d6d450 bytes, and reading it back here gives
        // every property its value (the old classes read the same bytes, so they get the same values).
        foreach (self::jobs() as $key => [$class, $args]) {
            $job = unserialize($this->payload($class, $args));
            foreach ((new \ReflectionObject($job))->getProperties() as $p) {
                if (!$p->isStatic() && $p->hasType()) $this->assertTrue($p->isInitialized($job), $key . ': $' . $p->getName());
            }
        }
    }
}
