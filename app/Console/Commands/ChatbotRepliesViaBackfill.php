<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minsanang punan ang chatbot_replies.via ng mga lumang padala (send at manual) na wala pa nito, hangga't tiyak:
 * ang via ng Results (tags.via), o ang hugis ng naitabing sagot ng provider (raw, ang pumalyang padala lang): ang
 * Forbeast ay may message_id o recipient_id, ang Botcake ay may "success". Ang hindi tiyak ay naiiwang null. Kada 1,000
 * row ayon sa id (walang mahabang lock); ligtas ulitin: ang may via na ay hindi ginagalaw. Dry run (bilang lang) ang
 * default; ang --apply ang sumusulat.
 */
class ChatbotRepliesViaBackfill extends Command
{
    protected $signature = 'chatbot:replies-via-backfill {--apply : Write the values (default: dry run, counts only)}';

    protected $description = 'Fill chatbot_replies.via on old sends from their tags or the stored provider answer';

    public function handle(): int
    {
        if (!Schema::hasTable('chatbot_replies') || !Schema::hasColumn('chatbot_replies', 'via')) {
            $this->error('chatbot_replies.via does not exist yet: run the migration first.');
            return self::FAILURE;
        }
        $hasTags = Schema::hasColumn('chatbot_replies', 'tags');
        $hasRaw = Schema::hasColumn('chatbot_replies', 'raw');
        $counts = [ChatbotProvider::BOTCAKE => 0, ChatbotProvider::FORBEAST => 0, 'unsure' => 0];

        DB::table('chatbot_replies')->whereNull('via')->whereIn('kind', ['send', 'manual'])
            ->select(array_merge(['id'], $hasTags ? ['tags'] : [], $hasRaw ? ['raw'] : []))
            ->chunkById(1000, function ($rows) use (&$counts) {
                $set = [ChatbotProvider::BOTCAKE => [], ChatbotProvider::FORBEAST => []];
                foreach ($rows as $r) {
                    $via = self::guess($r->tags ?? null, $r->raw ?? null);
                    if ($via === null) $counts['unsure']++;
                    else $set[$via][] = $r->id;
                }
                foreach ($set as $via => $ids) {
                    $counts[$via] += count($ids);
                    if ($ids && $this->option('apply')) DB::table('chatbot_replies')->whereIn('id', $ids)->whereNull('via')->update(['via' => $via]);
                }
            });

        $this->line(($this->option('apply') ? '' : 'Dry run (nothing changed; add --apply to write): ') . "botcake: {$counts['botcake']}, forbeast: {$counts['forbeast']}, left empty: {$counts['unsure']}");

        return self::SUCCESS;
    }

    /** Ang via mula sa tags (JSON) o sa raw na sagot; null kapag hindi tiyak. */
    public static function guess(?string $tags, ?string $raw): ?string
    {
        $t = $tags !== null ? json_decode($tags, true) : null;
        $via = is_array($t) ? ($t['via'] ?? null) : null;
        if (in_array($via, [ChatbotProvider::BOTCAKE, ChatbotProvider::FORBEAST], true)) return $via;

        $body = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($body)) return null;
        if (array_key_exists('message_id', $body) || array_key_exists('recipient_id', $body)) return ChatbotProvider::FORBEAST;
        if (array_key_exists('success', $body)) return ChatbotProvider::BOTCAKE;

        return null;
    }
}
