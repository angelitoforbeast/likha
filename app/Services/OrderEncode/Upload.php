<?php

namespace App\Services\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\Chatbot\FlowRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Upload to macro" step: ang hilaw na order row sa macro_output, walang encode (ang "Encode order" ng parehong takbo ang
 * mag-e-encode nito). Bawat upload ay isang order_encodes row (status "uploaded") na may usapan ng order na ito at ang row id,
 * kaya ito ang "row ng takbo". Insert = laging bagong row. Update = ang bukas na row ng customer (LiveOrder::find, ang mga
 * patakaran ng isang row kada araw) ay dinudugtungan ng mga bagong mensahe; ang final na row ay hindi ginagalaw.
 */
class Upload
{
    public const SKIPPED = 'No open row of the customer: nothing uploaded';

    public function __construct(private LiveOrder $live, private Conversation $conversation)
    {
    }

    /**
     * @param array<int, string> $row ang mga cell A..I ng step
     * @return array{result: string, row: ?int, note: string} result: inserted | updated | not_accepted | skipped | already
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException kapag may ibang upload pa ng customer (Update)
     */
    public function run(FlowRun $run, string $nodeId, array $row, string $mode, string $ifNotFound): array
    {
        if ($done = self::ofRun($run, $nodeId)) return ['result' => 'already', 'row' => (int) $done->macro_output_id, 'note' => 'Already uploaded in this run: row ' . $done->macro_output_id];

        $o = new OrderEncode([
            'page' => $run->pageName, 'psid' => (int) $run->fields->psid, 'chat_id' => $run->chatId, 'flow_id' => $run->flowId, 'run_id' => $run->runId,
            'spreadsheet_id' => FlowHook::NODE_SHEET, 'tab' => FlowHook::TAB, 'row_number' => null, 'mode' => 'live', 'status' => 'uploaded',
            'input' => ['row' => array_values($row), 'node' => $nodeId, 'page_id' => $run->pageId, 'upload' => $mode],
        ]);
        $in = Input::fromRow($row);
        if ($mode !== 'update') return $this->insert($o, $in);

        // Ang double tap ng Update: isa-isa kada customer (ang lock ng live na encode), kaya ang ikalawa ay nakikita ang row ng una.
        return Cache::lock(Runner::customerKey($o), Runner::CUSTOMER_LOCK_SECONDS)->block((int) config('order_encode.upload_lock_wait', 5), function () use ($o, $in, $ifNotFound) {
            $found = $this->live->find($o, $in);
            if (isset($found['final'])) return $this->notAccepted($o, $found['final']);
            if (!isset($found['open'])) return $ifNotFound === 'skip' ? ['result' => 'skipped', 'row' => null, 'note' => self::SKIPPED] : $this->insert($o, $in);

            $id = (int) $found['open']->id;

            return RowLock::hold($id, (int) config('order_encode.upload_row_lock_wait', 20), fn () => DB::transaction(function () use ($o, $in, $id) {
                $row = MacroOutput::find($id);
                if (!$row || trim((string) $row->STATUS) !== '' || trim((string) $row->waybill) !== '') return $row ? $this->notAccepted($o, $row) : $this->insert($o, $in);
                $o->macro_output_id = $id;
                $o->input = array_merge((array) $o->input, ['linked' => true]);
                $in = $this->save($o, $in);
                if (!$this->live->appendRaw($row, $in)) return $this->notAccepted($o, $row);
                // Ang Update ay bagong version ng parehong order sa Forbeast (ang row ay may usapan na ng order na ito).
                app(ForbeastOrders::class)->queue($o, $row, ForbeastOrders::UPLOADED);

                return ['result' => 'updated', 'row' => $id, 'note' => 'Row ' . $id . ' updated with this order\'s messages'];
            }));
        });
    }

    /** Ang upload ng takbong ito (ng step na $nodeId, o ang huli ng takbo kapag null), o null. */
    public static function ofRun(FlowRun $run, ?string $nodeId = null): ?OrderEncode
    {
        if ($run->runId === null) return null;

        return OrderEncode::where('mode', 'live')->where('psid', (int) $run->fields->psid)->where('run_id', $run->runId)->where('flow_id', $run->flowId)
            ->whereNotNull('input->upload')->whereNotNull('macro_output_id')->when($nodeId !== null, fn ($q) => $q->where('input->node', $nodeId))
            ->orderByDesc('id')->first();
    }

    private function insert(OrderEncode $o, Input $in): array
    {
        $made = DB::transaction(function () use ($o, $in) {
            $in = $this->save($o, $in);

            return $this->live->insertRaw($o, $in);
        });

        return ['result' => 'inserted', 'row' => (int) $made->id, 'note' => 'Row ' . $made->id . ' inserted'];
    }

    /** Ang order_encodes row ng upload, saka ang usapan ng order na ito (kailangan ang id para sa naunang takbo ng customer). */
    private function save(OrderEncode $o, Input $in): Input
    {
        $o->save();
        $in = $this->conversation->build($o, $in);
        $o->update(['input' => array_merge((array) $o->input, ['fields' => $in->v])]);

        return $in;
    }

    /** Ang final na row ng customer: walang ginagalaw; tala sa upload (ang Encode order ng takbo ay "proceed yes"). */
    private function notAccepted(OrderEncode $o, MacroOutput $row): array
    {
        $note = LiveOrder::NOT_ACCEPTED . $row->id;
        $o->macro_output_id = $row->id;
        $o->input = array_merge((array) $o->input, ['not_accepted' => $row->id, 'live_notes' => [$note]]);
        $o->save();

        return ['result' => 'not_accepted', 'row' => (int) $row->id, 'note' => $note];
    }
}
