<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\ChatbotFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang mga page na text lang ang natatanggap mula sa isang flow: ang naaabot nito (main flow, flow ng ad group, o flow na
 * tinatakbo ng sequence step ng flow nila) na nasa Botcake, o nasa address ng gateway na wala sa services.gateway.buttons.
 * Para sa paalala ng Publish at sa babala ng editor.
 */
class ButtonPages
{
    private const MAX_FLOWS = 50;

    /** @return array<int, string> ang mga pangalan ng page, naka-ayos */
    public static function textOnly(int $flowId): array
    {
        $flows = self::reaching($flowId);
        $pages = [];
        $rows = PageSetting::where('active', true)->where(fn ($q) => $q->where(fn ($q) => $q->where('provider', ChatbotFields::PROVIDER)->where('key', 'flow_id'))
            ->orWhere(fn ($q) => $q->where('provider', AdGroups::PROVIDER)->where('key', 'like', 'g%.flow_id')))->get(['page_name', 'provider', 'key', 'value', 'is_secret']);
        foreach ($rows as $s) {
            if ($s->provider === AdGroups::PROVIDER && !preg_match('/^g\d+\.flow_id$/', (string) $s->key)) continue;
            if (isset($flows[(int) trim((string) $s->plainValue())])) $pages[(string) $s->page_name] = true;
        }
        $out = array_values(array_filter(array_keys($pages), fn ($p) => !Gateways::buttonsOn(Gateways::sendRoute($p))));
        sort($out);

        return $out;
    }

    /** Ang paalala ng Publish kapag may button o quick reply ang graph at may page na text lang ang makukuha; null kapag wala. */
    public static function notice(int $flowId, array $graph): ?string
    {
        $has = false;
        foreach ($graph['nodes'] ?? [] as $n) if (($n['type'] ?? '') === 'send' && FlowGraph::hasTaps((array) ($n['config']['items'] ?? []), true)) $has = true;
        if (!$has || !($pages = self::textOnly($flowId))) return null;

        return 'Botcake pages get the text only (no buttons or quick replies), and so do gateway addresses not turned on for buttons: ' . implode(', ', $pages) . '.';
    }

    /** Ang flow at ang bawat flow na umaabot dito sa pag-subscribe sa sequence na may step na tumatakbo nito. @return array<int, true> */
    private static function reaching(int $flowId): array
    {
        $reach = [$flowId => true];
        if (!Schema::hasTable('chatbot_sequence_steps')) return $reach;
        $graphs = null;
        for ($todo = [$flowId]; $todo && count($reach) < self::MAX_FLOWS; ) {
            $seqs = DB::table('chatbot_sequence_steps')->whereIn('flow_id', $todo)->where('active', true)->distinct()->pluck('sequence_id')->map(fn ($v) => (int) $v)->all();
            $todo = [];
            if (!$seqs) break;
            $graphs ??= ChatbotFlow::get(['id', 'graph']);
            foreach ($graphs as $f) {
                if (isset($reach[$f->id])) continue;
                foreach ((array) ((is_array($f->graph) ? $f->graph : [])['nodes'] ?? []) as $n) {
                    $c = (array) ($n['config'] ?? []);
                    if (($n['type'] ?? '') === 'sequence' && ($c['action'] ?? '') === 'subscribe' && in_array((int) ($c['sequence_id'] ?? 0), $seqs, true)) {
                        $reach[$f->id] = true;
                        $todo[] = $f->id;
                        break;
                    }
                }
            }
        }

        return $reach;
    }
}
