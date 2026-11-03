<?php

namespace App\Console\Commands;

use App\Jobs\HandleGatewayEvent;
use App\Models\BotcakeChat;
use App\Services\Chatbot\GatewayProvider;
use App\Services\Chatbot\Gateways;
use App\Services\Chatbot\MessagingProvider;
use App\Services\Chatbot\RealSendTime;
use App\Services\Chatbot\SendStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Isang beses na ayos: ang mga padala ng gateway na tinanggap (2xx) pero walang kumpirmasyon (nawalang send_status) ay
 * itinatanong sa gateway ayon sa client_ref. Walang basa ayon sa client_ref ang gateway: ang parehong client_ref sa POST
 * ng messages ay sinasagot ng 200 "duplicate" na may estado ng padala, at walang ipinapadala. Ang estadong tapos na (sent,
 * failed) ay isinusulat gaya ng live na send_status (SendStatus). Dry run kapag walang --apply: bilang lang, walang tawag.
 * Ang sagot na hindi duplicate (hindi kilala ng gateway ang client_ref, kaya ipinapadala niya) ay humihinto agad.
 */
class ChatbotHealSendConfirmations extends Command
{
    protected $signature = 'chatbot:heal-send-confirmations {--hours=24 : How far back, by sent_at (1-48)} {--apply : Ask the gateway and write; without it only the counts}
        {--sleep-ms=500 : Pause between gateway calls (they share the API limit with live sends)}';
    protected $description = 'One-off: fill the confirmation of unconfirmed gateway replies by asking the gateway for each send by client_ref (dry run by default)';

    public function handle(SendStatus $status): int
    {
        if (!RealSendTime::ready()) {
            $this->warn('chatbot_replies has no confirmation columns yet.');
            return self::SUCCESS;
        }
        $hours = max(1, min(48, (int) $this->option('hours')));
        $rows = DB::table('chatbot_replies')->whereIn('via', Gateways::ALL)->whereIn('kind', ['send', 'manual'])->where('status', 'sent')
            ->whereBetween('http', [200, 299])->whereNull('confirmed_status')->whereNotNull('client_ref')->where('client_ref', '!=', '')
            ->where('sent_at', '>=', now()->subHours($hours)->format('Y-m-d H:i:s'))->orderBy('id')
            ->get(['id', 'page_id', 'psid', 'content', 'client_ref', 'via']);
        $by = $rows->countBy('via')->sortKeys();
        $this->line('Unconfirmed gateway replies of the last ' . $hours . ' h: ' . $rows->count()
            . ($by->isEmpty() ? '' : ' (' . $by->map(fn ($n, $via) => $via . ' ' . $n)->implode(', ') . ')'));
        if (!$this->option('apply')) {
            $this->line('Dry run: nothing asked or written. Run with --apply to ask the gateway.');
            return self::SUCCESS;
        }

        $n = array_fill_keys(['sent', 'failed', 'still', 'already', 'refused', 'no_answer', 'skipped'], 0);
        $pages = BotcakeChat::pageMap();
        $sleep = max(0, (int) $this->option('sleep-ms'));
        $stop = null;
        foreach ($rows as $i => $r) {
            if ($i > 0 && $sleep > 0) usleep($sleep * 1000);
            $page = $pages[(string) $r->page_id] ?? null;
            $messages = json_decode((string) $r->content, true);
            $q = $page === null || !is_array($messages) || $messages === [] ? null
                : (new GatewayProvider((string) $r->via))->request($page, (string) $r->page_id, (string) $r->psid, $messages, (string) $r->client_ref, MessagingProvider::UPDATE);
            if ($q === null) {
                $n['skipped']++;
                continue;
            }
            try {
                $res = Http::withToken($q['key'])->acceptJson()->withOptions(Gateways::httpOptions())->connectTimeout(min(5, $q['timeout']))->timeout($q['timeout'])
                    ->post($q['url'], $q['body']);
            } catch (Throwable) {
                $n['no_answer']++;
                continue;
            }
            $b = $res->json();
            if ($res->successful() && empty($b['duplicate'])) {
                $stop = 'Stopped: the gateway did not know client_ref ' . $r->client_ref . ' (HTTP ' . $res->status() . '), so it took it as a new send.';
                break;
            }
            if ($res->status() === 429) {
                $stop = 'Stopped: the gateway API limit was reached (HTTP 429). Run again later or with a longer --sleep-ms.';
                break;
            }
            if ($res->status() !== 200 || !is_array($b)) {
                $n['refused']++;
                continue;
            }
            $send = self::send($b, (string) $r->client_ref);
            if ($send === null) {
                $n['still']++;
                continue;
            }
            $e = HandleGatewayEvent::fields(['kind' => 'event', 'type' => 'send_status', 'page_id' => (string) $r->page_id, 'psid' => (string) $r->psid, 'send' => $send]);
            $out = $status->receive($e);
            if ($out === 'send_status') $n[$send['status'] === 'sent' ? 'sent' : 'failed']++;
            else $n['already']++;
        }

        $this->line('Asked: confirmed sent ' . $n['sent'] . ', confirmed failed or partial ' . $n['failed'] . ', still sending ' . $n['still']
            . ', already confirmed ' . $n['already'] . ', refused ' . $n['refused'] . ', no answer ' . $n['no_answer'] . ', skipped (no page, messages or gateway) ' . $n['skipped']);
        if ($stop !== null) {
            $this->error($stop);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Ang duplicate na sagot (ang send ng gateway) bilang send ng send_status; null kapag hindi pa tapos (queued, sending). */
    private static function send(array $b, string $ref): ?array
    {
        $sent = is_int($b['sent'] ?? null) ? $b['sent'] : 0;
        $status = match ($b['status'] ?? null) {
            'sent' => 'sent',
            'failed' => $sent > 0 ? 'partial' : 'failed',
            default => null,
        };
        if ($status === null) return null;
        $error = is_array($b['error'] ?? null) ? $b['error'] : [];

        return ['client_ref' => $ref, 'status' => $status, 'accepted_at' => $b['created_at'] ?? null,
            'sent_at' => $status === 'failed' ? null : ($b['sent_at'] ?? null), 'message_count' => $b['messages'] ?? null, 'sent_count' => $sent,
            'error_code' => $error['code'] ?? null, 'error_subcode' => $error['subcode'] ?? null, 'error' => $error['message'] ?? null];
    }
}
