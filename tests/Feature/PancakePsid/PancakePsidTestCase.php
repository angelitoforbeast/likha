<?php

namespace Tests\Feature\PancakePsid;

use App\Models\UploadLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Feature\NightRun\NightRunTestCase;

/**
 * Base ng Pancake PSID tests: totoong pancake_conversations migrations (kasama ang psid), pancake_id mapping,
 * at ang botcake_psid column ng macro_output na wala sa base fixture.
 */
abstract class PancakePsidTestCase extends NightRunTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('macro_output', function (Blueprint $t) {
            $t->string('botcake_psid', 64)->nullable();
        });
        Schema::create('pancake_id', function (Blueprint $t) {
            $t->id();
            $t->string('pancake_page_id');
            $t->string('pancake_page_name');
            $t->timestamps();
        });
        Schema::create('upload_logs', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->string('disk')->nullable();
            $t->string('path')->nullable();
            $t->string('original_name')->nullable();
            $t->string('status')->nullable();
            $t->unsignedInteger('processed_rows')->default(0);
            $t->unsignedInteger('inserted')->default(0);
            $t->unsignedInteger('updated')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        // Bawat view ay dumadaan sa composer ng AppServiceProvider na bumibilang ng tasks ng user.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
    }

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2026_11_05_100000_add_psid_to_pancake_conversations.php',
        ]);
    }

    protected function mapPage(string $name, string $pageId): void
    {
        DB::table('pancake_id')->insert(['pancake_page_id' => $pageId, 'pancake_page_name' => $name]);
    }

    /** @return int id ng bagong row */
    protected function conv(string $pageId, string $name, string $chat, ?string $psid = null): int
    {
        return DB::table('pancake_conversations')->insertGetId([
            'pancake_page_id' => $pageId, 'full_name' => $name, 'psid' => $psid,
            'customers_chat' => $chat, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Gumagawa ng xlsx na kapareho ng Pancake export. $rows = [message, name, senderId|null, pageId]; ang senderId
     * na int ay isusulat na number cell, ang string ay text cell; walang $withSenderId = walang Sender ID column.
     */
    protected function xlsx(array $rows, bool $withSenderId = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pcv') . '.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);

        $header = ['Message content', 'Sender name (customer_name)'];
        if ($withSenderId) $header[] = 'Sender ID';
        $header[] = 'Page ID (page_id)';
        $writer->addRow(Row::fromValues($header));

        foreach ($rows as [$msg, $name, $sid, $pageId]) {
            $cells = [Cell::fromValue($msg), Cell::fromValue($name)];
            if ($withSenderId) $cells[] = Cell::fromValue($sid);
            $cells[] = Cell::fromValue($pageId);
            $writer->addRow(new Row($cells));
        }
        $writer->close();

        return $path;
    }

    /** Pinapatakbo ang import job sa file; ibinabalik ang upload log. */
    protected function import(string $path): UploadLog
    {
        $log = UploadLog::create([
            'type' => 'pancake_conversations', 'disk' => 'local', 'path' => $path, 'original_name' => 'x.xlsx', 'status' => 'queued',
        ]);
        // Binabasa ng job ang file mula sa 'local' disk: ilagay doon.
        $rel = 'pancake_psid_test_' . uniqid() . '.xlsx';
        Storage::disk('local')->put($rel, file_get_contents($path));
        (new \App\Jobs\ProcessPancakeConversationsUpload($rel, null, $log->id))->handle();
        Storage::disk('local')->delete($rel);
        @unlink($path);

        return $log->fresh();
    }
}
