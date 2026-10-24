<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Jobs\ResumeChatbotAi;
use App\Jobs\RunSpotAstra;
use App\Models\OrderEncode;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\Outbox;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Ang "Upload to macro" at ang bagong "Encode order" ng flow. Ang takbo ay sa tunay na FlowEngine (fake na outbox),
 * ang encode ay sa tunay na Runner (fake na OpenAI ng LiveRowTestCase), at ang mga job ay naka-fake (ang test ang nagpapatakbo).
 */
abstract class UploadEncodeTestCase extends LiveRowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
        ]]);
        if (!Schema::hasColumn('macro_output', 'edited_phone_number')) Schema::table('macro_output', fn (Blueprint $t) => $t->boolean('edited_phone_number')->default(false));
        config(['services.chatbot.replies_enabled' => true, 'order_encode.upload_lock_wait' => 0]);
        Queue::fake([EncodeOrder::class, ResumeChatbotAi::class, RunSpotAstra::class, \App\Jobs\RunEncodeAstra::class]);
    }

    /** Ang mga template ng Upload to macro (A..H, gaya ng Sheet step ng All Orders). */
    protected function uploadConfig(array $over = []): array
    {
        return $over + [
            'date' => '{{last_interaction}}', 'page' => '{{page_name}}', 'name' => '{{full_name}}', 'phone' => '{{PHONE}}', 'input' => '{{last_input}}',
            'shop' => "PAGE: Page Uno\nPRICE: ₱998", 'extracted' => '{{CXD}}', 'price' => '998', 'mode' => 'insert', 'if_not_found' => 'insert',
        ];
    }

    /** @param array<string, array{0: string, 1?: array}> $nodes id => [type, config]; ang n1 ay Start */
    protected function graph(array $nodes, array $edges): array
    {
        $list = [['id' => 'n1', 'type' => 'start', 'x' => 0, 'y' => 0, 'config' => []]];
        foreach ($nodes as $id => $n) $list[] = ['id' => $id, 'type' => $n[0], 'x' => 0, 'y' => 0, 'config' => $n[1] ?? []];

        return FlowGraph::clean(['nodes' => $list, 'edges' => array_map(fn ($e) => ['from' => $e[0], 'port' => $e[1], 'to' => $e[2]], $edges)]);
    }

    /** Start → n2 Upload to macro → next n3 End, failed n4 End. */
    protected function uploadGraph(array $over = []): array
    {
        return $this->graph(['n2' => ['upload_macro', $this->uploadConfig($over)], 'n3' => ['end'], 'n4' => ['end']],
            [['n1', 'next', 'n2'], ['n2', 'next', 'n3'], ['n2', 'failed', 'n4']]);
    }

    protected function fields(int $psid = self::PSID): CustomerFields
    {
        return new CustomerFields((int) self::PAGE_ID, $psid);
    }

    protected function go(array $graph, string $input = 'order po', string $at = '2026-10-23 10:15', ?string $runId = null, bool $test = false, int $psid = self::PSID): FlowRun
    {
        $run = new FlowRun(5, $graph, self::PAGE, self::PAGE_ID, [], $this->fields($psid), 13, $input, 'Ana Cruz', $at, $test);
        $run->runId = $runId;

        return app(FlowEngine::class)->run($run, $this->outbox());
    }

    /** @return array{port: ?string, note: ?string} ang huling dinaanan ng node sa takbo */
    protected function step(FlowRun|OrderEncode $run, string $node): array
    {
        if ($run instanceof OrderEncode) return parent::step($run, $node);

        return collect($run->path)->last(fn ($p) => $p['node'] === $node) ?? [];
    }

    /** @return array<int, array> ang mga detail ng event ng customer na $type */
    protected function events(string $type, int $psid = self::PSID): array
    {
        return DB::table('chatbot_events')->where('psid', $psid)->where('type', $type)->orderBy('id')->get()->map(fn ($e) => (array) json_decode($e->detail, true))->all();
    }

    /** Ang events ng takbo (FlowRun) na $type. */
    protected function runEvents(FlowRun $run, string $type): array
    {
        return array_values(array_filter($run->events, fn ($e) => $e['type'] === $type));
    }

    protected function outbox(): Outbox
    {
        return new class implements Outbox {
            public int $appends = 0;

            public function send(FlowRun $run, string $nodeId, array $messages): array
            {
                return ['status' => 'sent', 'error' => null];
            }

            public function appendRow(FlowRun $run, string $nodeId, string $spreadsheetId, string $tab, array $row): array
            {
                $this->appends++;

                return ['status' => 'sent', 'error' => null];
            }
        };
    }
}
