<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang usage ng chatbot AI papunta sa Forbeast ng page (spec 210): ang bilang sa screen ng pool.
 */
class ChatbotAiUsage
{
    public const PENDING = 'pending';
    public const NO_GATEWAY = 'no_gateway';

    /** Ang gateway na tatanggap ng usage ng page (chatbot.gateway, o ang default), kapag may URL at site key ito; o null. */
    public static function gatewayOf(string $page): ?string
    {
        $g = Gateways::active($page);

        return Gateways::setUp($g) && Gateways::config($g)['site_key'] !== '' ? $g : null;
    }

    /** May mga column na ng key at gastos (ang migration ng spec 210)? */
    public static function ready(): bool
    {
        return Schema::hasTable(ChatbotAiQueue::TABLE) && Schema::hasColumn(ChatbotAiQueue::TABLE, 'usage_status');
    }

    /** Ang bilang ng bawat status kada gateway, at ang pause (401) ng gateway. @return array<int, array> */
    public static function panel(): array
    {
        if (!self::ready()) return [];
        $counts = DB::table(ChatbotAiQueue::TABLE)->whereNotNull('usage_status')->groupBy('usage_gateway', 'usage_status')
            ->selectRaw('usage_gateway, usage_status, COUNT(*) AS n')->get();
        $out = [];
        foreach (Gateways::ALL as $g) {
            $mine = $counts->where('usage_gateway', $g)->pluck('n', 'usage_status')->map(fn ($n) => (int) $n)->all();
            $paused = app(ChatbotAiKeys::class)->latest('usage.' . $g . '.paused');
            if (!$mine && !$paused && !Gateways::setUp($g)) continue;
            $out[] = ['gateway' => $g, 'label' => Gateways::label($g), 'counts' => $mine, 'paused' => $paused];
        }
        $none = (int) $counts->where('usage_status', 'no_gateway')->sum('n');
        if ($none > 0) $out[] = ['gateway' => null, 'label' => 'No gateway', 'counts' => ['no_gateway' => $none], 'paused' => null];

        return $out;
    }
}
