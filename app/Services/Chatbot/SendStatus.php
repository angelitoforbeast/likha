<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotReply;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ang send_status event ng Forbeast: kailan talaga naipadala sa customer ang isang padala (sent, partial, failed). Itinutugma
 * sa reply ayon sa client_ref; ang lumang reply na walang client_ref (bago ito naitala) ay ang pinakamalapit na hindi pa
 * kumpirmado sa parehong page + PSID (±10 minuto mula sa accepted_at). Isang beses lang: ang ulit ay walang binabago. Ang
 * failed at partial ay nagiging status at error ng reply, gaya ng pumalyang padala ng Botcake (may Meta code sa error).
 */
class SendStatus
{
    public const STATUSES = ['sent', 'partial', 'failed'];
    /** Ang may naipadala sa customer (ang partial ay may naipadala rin). */
    public const DELIVERED = ['sent', 'partial'];
    private const NEAR_SECONDS = 600;
    /**
     * May client_ref pero wala pang reply: ang 202 ng gateway ay maaaring hindi pa nababasa ng nagpadala (ang row ay
     * isinusulat pagkatapos ng sagot), kaya HandleGatewayEvent ang susubok ulit. Hindi ito binibilang.
     */
    public const NOT_YET = 'send_status_not_yet';

    /** @return string ang bibilangin: send_status, send_status_repeat, send_status_unknown o send_status_bad; o NOT_YET */
    public function receive(array $e): string
    {
        $s = $e['send'];
        if (!ChatbotReply::hasConfirm() || !ctype_digit((string) $e['page_id'])) return 'send_status_unknown';
        if (!in_array($s['status'], self::STATUSES, true)) return 'send_status_bad';

        $reply = $this->match($e);
        if ($reply === null) return $s['client_ref'] !== null && $s['client_ref'] !== '' ? self::NOT_YET : 'send_status_unknown';
        if ($reply->confirmed_status !== null) return 'send_status_repeat';

        $status = $s['status'];
        $set = ['confirmed_status' => $status, 'confirmed_at' => ($status === 'failed' ? null : self::time($s['sent_at'])) ?? now()->format('Y-m-d H:i:s')];
        if ($status !== 'sent') $set += ['status' => $status, 'error' => mb_substr(self::error($s), 0, 255)];
        if ($reply->client_ref === null && $s['client_ref'] !== null && $s['client_ref'] !== '') $set['client_ref'] = $s['client_ref'];

        if (DB::table('chatbot_replies')->where('id', $reply->id)->whereNull('confirmed_status')->update($set) !== 1) return 'send_status_repeat';
        // Walang naihatid sa customer: kung ang padala ay ang tanong na hinihintay pa ang sagot, itatanong ulit.
        if ($status === 'failed' || ($status === 'partial' && $s['sent_count'] === 0)) $this->unsent($reply);

        return 'send_status';
    }

    /**
     * Ang tanong ng hakbang na naghihintay (__wait) ay hindi naihatid: ang susunod na mensahe ng customer ay hindi ang sagot
     * nito (WAIT_UNSENT; FlowEngine ang nagtatanong ulit). Kapag lumipat na ang paghihintay (sumagot, ibang hakbang), wala.
     * Ang pagkabigo rito ay hindi pumipigil sa talaan ng send_status.
     */
    private function unsent(object $reply): void
    {
        try {
            if ($reply->kind !== 'send' || $reply->flow_id === null || $reply->node_id === null || !ctype_digit((string) $reply->psid)) return;
            $fields = new CustomerFields((int) $reply->page_id, (int) $reply->psid);
            $wait = $reply->flow_id . ':' . $reply->node_id;
            if ($fields->get(CustomerFields::WAIT) === $wait) $fields->set(CustomerFields::WAIT_UNSENT, $wait);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function match(array $e): ?object
    {
        $s = $e['send'];
        $q = fn () => DB::table('chatbot_replies')->where('page_id', (int) $e['page_id'])->whereIn('kind', ['send', 'manual']);
        $cols = ['id', 'client_ref', 'confirmed_status', 'sent_at', 'kind', 'page_id', 'psid', 'flow_id', 'node_id'];
        if ($s['client_ref'] !== null && $s['client_ref'] !== '') {
            $r = $q()->where('client_ref', $s['client_ref'])->first($cols);
            if ($r) return $r;
        }
        $accepted = self::time($s['accepted_at']);
        if ($accepted === null || !ctype_digit((string) $e['psid'])) return null;
        $at = strtotime($accepted);

        return $q()->where('psid', (int) $e['psid'])->whereNull('client_ref')->whereNull('confirmed_status')->whereIn('via', Gateways::ALL)
            ->where('status', 'sent')->whereBetween('sent_at', [date('Y-m-d H:i:s', $at - self::NEAR_SECONDS), date('Y-m-d H:i:s', $at + self::NEAR_SECONDS)])
            ->get($cols)->sortBy(fn ($r) => [abs(strtotime($r->sent_at) - $at), $r->id])->first();
    }

    /** Ang ISO na oras ng Forbeast (UTC) bilang oras ng app; null kapag wala o sira. */
    private static function time(?string $v): ?string
    {
        if ($v === null || $v === '') return null;
        try {
            return Carbon::parse($v)->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** Ang error gaya ng sa Botcake: ang sinabi ng Meta at ang code nito sa (#…), para pareho ang bilang ng dahilan. */
    private static function error(array $s): string
    {
        $head = $s['status'] === 'failed' ? 'Forbeast could not send' : 'Forbeast sent ' . (int) $s['sent_count'] . ' of ' . (int) $s['message_count'];

        return $head . ($s['error'] !== null && $s['error'] !== '' ? ': "' . mb_substr($s['error'], 0, 150) . '"' : '')
            . ($s['error_code'] !== null ? ' (#' . $s['error_code'] . ')' : '') . ($s['error_subcode'] !== null ? ' subcode ' . $s['error_subcode'] : '');
    }
}
