<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Models\ChatbotSequenceStep;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang ayos ng bot na binabasa sa bawat chat (ang flow ng page, ang sequence at mga step nito) sa cache nang 60 s. Bihira
 * itong magbago, kaya ang bawat sulat (model save/delete, ang Publish at draft ng editor) ay nagbubura ng cache ngayon at
 * pagkatapos ng commit: ang susunod na mensahe ay laging gumagamit ng bagong naka-save. Ang settings ng page ay nasa
 * PageSettings (sariling cache, binubura rin sa bawat sulat).
 */
class BotConfigCache
{
    public const TTL = 60;

    public static function flowKey(int $id): string
    {
        return 'chatbot-config:v1:flow:' . $id;
    }

    public static function sequenceKey(int $id): string
    {
        return 'chatbot-config:v1:sequence:' . $id;
    }

    /** Ang flow (lahat ng column), o null kapag wala o wala pang table. */
    public static function flow(int $id): ?ChatbotFlow
    {
        $row = Cache::remember(self::flowKey($id), self::TTL, fn () => Schema::hasTable('chatbot_flows') ? ChatbotFlow::find($id)?->getAttributes() : null);

        return is_array($row) ? (new ChatbotFlow)->newFromBuilder($row) : null;
    }

    /** Ang sequence kasama ang lahat ng step nito (ayon sa position, gaya ng steps()), o null kapag wala. */
    public static function sequence(int $id): ?ChatbotSequence
    {
        $data = Cache::remember(self::sequenceKey($id), self::TTL, function () use ($id) {
            $seq = ChatbotSequence::find($id);

            return $seq ? ['sequence' => $seq->getAttributes(), 'steps' => $seq->steps()->get()->map(fn ($s) => $s->getAttributes())->all()] : null;
        });
        if (!is_array($data)) return null;
        $seq = (new ChatbotSequence)->newFromBuilder($data['sequence']);
        $seq->setRelation('steps', (new ChatbotSequenceStep)->newCollection(array_map(fn ($s) => (new ChatbotSequenceStep)->newFromBuilder($s), $data['steps'])));

        return $seq;
    }

    public static function forgetFlow(int $id): void
    {
        self::forget(self::flowKey($id));
    }

    public static function forgetSequence(int $id): void
    {
        self::forget(self::sequenceKey($id));
    }

    /** Ngayon at pagkatapos ng commit: ang basang nauna sa commit ay hindi maiiwan sa cache. */
    private static function forget(string $key): void
    {
        Cache::forget($key);
        DB::afterCommit(fn () => Cache::forget($key));
    }
}
