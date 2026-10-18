<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatMessageCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minsanang ayos ng mga lumang botcake_chats: ang mensaheng link sa Facebook CDN ay nililinis gaya ng /api/botcake/chat
 * (ChatMessageCleaner): Like = "👍", ibang larawan = "[Photo]" na ang link ay nasa payload.image_url. Dry run bilang
 * default (bilang lang); ang --apply ay kailangan ng --expect na tugma sa bilang ng babaguhin. Isang beses lang ang
 * epekto: ang naayos na ay hindi na link.
 */
class NormalizeBotcakeChats extends Command
{
    protected $signature = 'botcake:chats-normalize
        {--apply : Change the rows (default: dry run, counts only)}
        {--expect= : With --apply: the number of rows to change, as the dry run printed}';

    protected $description = 'Turn old Like and Facebook photo links in botcake_chats into 👍 and [Photo]';

    public function handle(): int
    {
        if (!Schema::hasTable('botcake_chats')) {
            $this->error('botcake_chats does not exist.');
            return self::FAILURE;
        }

        $plan = $this->plan();
        $like = count(array_filter($plan, fn ($p) => $p['kind'] === 'like'));
        $photo = count($plan) - $like;
        $untouched = DB::table('botcake_chats')->count() - count($plan);
        $this->line("like: {$like}, photo: {$photo}, untouched: {$untouched}");

        if (!$this->option('apply')) {
            $this->line('Dry run: nothing changed. Run with --apply --expect=' . count($plan) . ' to change these rows.');
            return self::SUCCESS;
        }
        $expect = $this->option('expect');
        if ($expect === null || !ctype_digit((string) $expect) || (int) $expect !== count($plan)) {
            $this->error('Refused: --expect must equal the number of rows to change (' . count($plan) . ').');
            return self::FAILURE;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $id => $p) {
                $payload = json_decode((string) DB::table('botcake_chats')->where('id', $id)->value('payload'), true);
                $payload = is_array($payload) ? $payload : [];
                if ($p['image_url'] !== null) $payload['image_url'] = $p['image_url'];
                DB::table('botcake_chats')->where('id', $id)->update(['message' => $p['message'], 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
        });
        $this->info('changed ' . count($plan));

        return self::SUCCESS;
    }

    /** @return array<int, array{kind: string, message: string, image_url: ?string}> id => ang babaguhin */
    private function plan(): array
    {
        $plan = [];
        DB::table('botcake_chats')->where('message', 'like', '%fbcdn.net%')->orderBy('id')->select(['id', 'message'])
            ->chunkById(1000, function ($rows) use (&$plan) {
                foreach ($rows as $r) {
                    $clean = ChatMessageCleaner::clean((string) $r->message);
                    if ($clean['kind'] !== null) $plan[(int) $r->id] = $clean;
                }
            });

        return $plan;
    }
}
