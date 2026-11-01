<?php

namespace App\Console\Commands;

use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\ResponseSpeed;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Basa lang: ang huli ng mga sequence step kada oras ng isang araw (Manila). Ang naipadala at ang huli ay mula sa
 * /chatbot/speed (due → ang padala na sinagot ng Botcake o ng gateway, at → Meta kapag kumpirmado), hindi mula sa oras ng
 * hand-off. Ang due pero hindi pa naipadala, ang pinakaluma sa mga iyon, at ang hinihintay pa sa chatbot:sequence-pool ay
 * ngayon (mula sa subscriptions at mga ticket). Walang isinusulat.
 */
class ChatbotSequenceLateness extends Command
{
    protected $signature = 'chatbot:sequence-lateness {--date= : Y-m-d (Manila), default today} {--page= : Facebook page ID}';
    protected $description = 'Read-only: per hour, sequence steps sent, due but unsent, oldest overdue, average lateness and sends waiting in the sequence pool';

    public function handle(ResponseSpeed $speed): int
    {
        $out = $speed->sequencesOutput(array_filter(['date' => $this->option('date'), 'page_id' => $this->option('page')]));
        $date = $out['filters']['date'];
        $page = $out['filters']['page_id'];
        $seq = $out['sequences'];
        if (!empty($seq['too_large'])) {
            $this->warn('The sequence numbers of this day are too large to compute right now.');
            return self::SUCCESS;
        }

        // Due pero hindi pa naipadala (ngayon): ang active na lampas na sa oras ng padala, kada oras ng due.
        $due = [];
        if (Schema::hasTable('chatbot_sequence_subscriptions')) {
            $from = $date . ' 00:00:00';
            $to = CarbonImmutable::parse($date)->addDay()->toDateString() . ' 00:00:00';
            $rows = DB::table('chatbot_sequence_subscriptions')->where('status', 'active')->where('next_send_at', '>=', $from)->where('next_send_at', '<', $to)
                ->where('next_send_at', '<=', now())->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->get(['next_send_at']);
            foreach ($rows as $r) {
                $h = (int) substr((string) $r->next_send_at, 11, 2);
                $due[$h]['n'] = ($due[$h]['n'] ?? 0) + 1;
                $due[$h]['oldest'] = min($due[$h]['oldest'] ?? (string) $r->next_send_at, (string) $r->next_send_at);
            }
        }
        $pending = [];
        if (Schema::hasTable(BotcakeSendQueue::TABLE)) {
            $rows = DB::table(BotcakeSendQueue::TABLE)->where('kind', BotcakeSendQueue::SEQUENCE)->whereIn('status', ['pending', 'sending'])
                ->where('created_at', '>=', $date . ' 00:00:00')->where('created_at', '<', CarbonImmutable::parse($date)->addDay()->toDateString() . ' 00:00:00')
                ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->get(['created_at']);
            foreach ($rows as $r) $pending[(int) substr((string) $r->created_at, 11, 2)] = ($pending[(int) substr((string) $r->created_at, 11, 2)] ?? 0) + 1;
        }

        $blank = fn ($v) => $v === null || $v === 0 ? '' : (string) $v;
        $lines = [];
        foreach ($seq['hours'] as $h => $row) {
            $late = $row === null ? null : $row['scheduled_to_pushed'];
            $meta = $row === null ? null : $row['pushed_to_meta'];
            $lines[] = [sprintf('%02d:00', $h), $blank($row['sent'] ?? null), $blank($due[$h]['n'] ?? null),
                isset($due[$h]['oldest']) ? max(0, now()->getTimestamp() - strtotime($due[$h]['oldest'])) . ' s' : '',
                $late === null || $late['avg'] === null ? '' : $late['avg'] . ' s', $late === null || $late['max'] === null ? '' : $late['max'] . ' s',
                $meta === null || $meta['avg'] === null ? '' : $meta['avg'] . ' s', $blank($pending[$h] ?? null)];
        }
        $this->info('Sequence steps on ' . $date . ($page !== null ? ' (page ' . $page . ')' : '') . '. Lateness is due → the send the API answered (→ Meta when confirmed), not the hand-off.');
        $this->table(['Hour', 'Sent', 'Due, not sent', 'Oldest overdue', 'Avg late', 'Max late', 'Avg to Meta', 'Pool pending'], $lines);

        return self::SUCCESS;
    }
}
