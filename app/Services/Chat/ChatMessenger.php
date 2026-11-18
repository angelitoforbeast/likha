<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Likha Chat gaya ng Messenger (spec 300): reactions, "Edited" at ang history (CEO lang), voice, mute, alerts at search.
 * Hiwalay sa ChatPresenter (296) para madaling i-merge sa ibang spec: ang presenter ay tumatawag lang dito sa dulo.
 */
class ChatMessenger
{
    public const MUTED_FOREVER = '9999-12-31 23:59:59';

    /** Ang content type ng bawat voice extension na itinatago (fixed; hindi mula sa file). */
    public const VOICE_CONTENT_TYPES = ['webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg'];

    // ── messages ─────────────────────────────────────────────────────────────

    /**
     * Idinadagdag sa JSON ng bawat message (ChatPresenter::messages): reactions, edited, edit_until (sarili lang),
     * history (CEO lang), at ang voice bilang `audio` na may sariling URL at haba.
     *
     * @param  array  $out  ang mga message JSON, kapareho ng pagkakasunod ng $messages
     * @param  Collection<int, ChatMessage>  $messages
     */
    public static function decorate(array $out, Collection $messages, User $viewer): array
    {
        $reactorIds = $messages->flatMap(fn (ChatMessage $m) => array_keys($m->reactions ?? []))->all();
        $people = ChatPeople::lookup($reactorIds);
        $ceo = ChatAccess::isCeo($viewer);
        $editMinutes = (int) config('chat.edit_minutes', 15);

        foreach ($messages->values() as $i => $m) {
            $row = &$out[$i];
            $removed = $m->trashed();
            $row['reactions'] = $removed ? [] : self::reactions($m, $viewer, $people);
            $row['edited'] = $m->edited_at !== null;
            $mine = (int) $m->sender_user_id === $viewer->id && $m->sender_ai === null;
            $row['edit_until'] = $mine && ! $removed && trim((string) $m->body) !== '' && $m->created_at->copy()->addMinutes($editMinutes)->isFuture()
                ? $m->created_at->copy()->addMinutes($editMinutes)->toIso8601String() : null;
            if ($ceo && $m->edit_history) {
                $row['history'] = array_map(fn ($h) => ['text' => (string) ($h['body'] ?? ''), 'at' => $h['at'] ?? null], $m->edit_history);
            }
            if (! $removed) {
                foreach (array_values($m->attachments ?? []) as $k => $f) {
                    if (($f['kind'] ?? '') === 'audio' && isset($row['files'][$k])) {
                        $row['files'][$k] = [
                            'url' => route('chat.voice', ['conversation' => $m->conversation_id, 'message' => $m->id]),
                            'name' => 'Voice message',
                            'size' => (int) ($f['size'] ?? 0),
                            'kind' => 'audio',
                            'duration' => (int) ($f['duration'] ?? 0),
                        ];
                    }
                }
            }
            unset($row);
        }

        return $out;
    }

    /** Sa ayos ng allowlist; bawat isa: emoji, ilan, kung akin, at sino. */
    private static function reactions(ChatMessage $m, User $viewer, Collection $people): array
    {
        $map = $m->reactions ?? [];
        $out = [];
        foreach (config('chat.reactions') as $emoji) {
            $ids = array_map('intval', array_keys(array_filter($map, fn ($e) => $e === $emoji)));
            if (! $ids) {
                continue;
            }
            $out[] = [
                'emoji' => $emoji,
                'count' => count($ids),
                'mine' => in_array($viewer->id, $ids, true),
                'people' => array_map(fn ($id) => ['id' => $id, 'name' => $people->get($id)['name'] ?? 'Unknown'], $ids),
            ];
        }

        return $out;
    }

    /** Isang linya para sa listahan at sa alert: text, o kung ano ang ipinadala. */
    public static function preview(ChatMessage $m, int $limit = 140): string
    {
        $files = $m->attachments ?? [];
        if (trim((string) $m->body) === '' && $files) {
            if (collect($files)->contains(fn ($a) => ($a['kind'] ?? '') === 'audio')) {
                return 'Voice message';
            }
            $n = count($files);
            $images = count(array_filter($files, fn ($a) => ($a['kind'] ?? '') === 'image'));

            return $images === $n ? ($n === 1 ? 'Sent a photo' : "Sent $n photos") : ($n === 1 ? 'Sent a file' : "Sent $n files");
        }

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $m->body)), $limit - 1, '…');
    }

    // ── list rows and the open conversation ──────────────────────────────────

    /** Idinadagdag sa bawat row ng listahan: muted (akin), at sa direct chat ang active_at ng kausap. */
    public static function rows(array $rows, User $me): array
    {
        if (! $rows) {
            return $rows;
        }
        $ids = array_column($rows, 'id');
        $mine = ChatMember::whereIn('conversation_id', $ids)->where('user_id', $me->id)->get(['conversation_id', 'muted_until'])->keyBy('conversation_id');
        $directIds = array_column(array_filter($rows, fn ($r) => $r['kind'] === ChatConversation::DIRECT), 'id');
        $others = $directIds ? ChatMember::whereIn('conversation_id', $directIds)->where('user_id', '!=', $me->id)
            ->get(['conversation_id', 'user_id'])->groupBy('conversation_id') : collect();
        $active = ChatLive::activeAt($others->flatten()->pluck('user_id')->all());

        foreach ($rows as &$r) {
            $m = $mine->get($r['id']);
            $r['muted'] = $m ? $m->isMuted() : false;
            $other = $others->get($r['id']);
            $r['active_at'] = $other && $other->count() === 1 ? ($active[(int) $other->first()->user_id] ?? null) : null;
        }

        return $rows;
    }

    /** Idinadagdag sa conversation ng thread: active_at ng bawat miyembro, at ang mute ko. */
    public static function conversation(array $info, User $me, ?ChatMember $member): array
    {
        $active = ChatLive::activeAt(array_column($info['members'], 'id'));
        foreach ($info['members'] as &$p) {
            $p['active_at'] = $active[(int) $p['id']] ?? null;
        }
        unset($p);
        $muted = $member?->isMuted() ?? false;
        $info['me']['muted'] = $muted;
        $info['me']['muted_until'] = $muted ? ($member->muted_until->year >= 9999 ? null : $member->muted_until->toIso8601String()) : null;

        return $info;
    }

    /** Ang mga nagta-type sa conversation (hindi ako), bilang pangalan. */
    public static function typing(ChatConversation $c, User $me): array
    {
        $ids = ChatLive::typingIds($c->id, $me->id);
        if (! $ids) {
            return [];
        }
        // Mga aktibong miyembro lang (ang umalis na ay hindi na lumalabas).
        $ids = ChatMember::where('conversation_id', $c->id)->whereIn('user_id', $ids)->whereNull('left_at')->pluck('user_id')->all();
        $people = ChatPeople::lookup($ids);

        return array_values(array_map(fn ($id) => ['id' => (int) $id, 'name' => $people->get((int) $id)['name'] ?? 'Someone'], $ids));
    }

    // ── unread, mute and alerts ──────────────────────────────────────────────

    /** Hindi naka-mute ngayon; para sa query na may `chat_members as cm`. */
    public static function notMuted($query)
    {
        return $query->where(fn ($w) => $w->whereNull('cm.muted_until')->orWhere('cm.muted_until', '<=', now()));
    }

    /**
     * Para sa bawat Likha page (MP-1, MP-2): ang kabuuan, ang pinakabagong id, at hanggang 5 bagong message (pinakabago
     * muna) pagkatapos ng $after na hindi akin, hindi naka-mute at hindi pa nababasa. Walang $after = ang simula lang.
     */
    public static function alerts(User $me, ?int $after): array
    {
        $alerts = [];
        if ($after !== null) {
            $ids = self::notMuted(ChatPresenter::unreadQuery($me->id))->where('m.id', '>', $after)
                ->orderByDesc('m.id')->limit(5)->pluck('m.id')->all();
            $messages = $ids ? ChatMessage::whereIn('id', $ids)->orderByDesc('id')->get() : collect();
            $convs = ChatConversation::whereIn('id', $messages->pluck('conversation_id')->unique())->get()->keyBy('id');
            $people = ChatPeople::lookup($messages->pluck('sender_user_id')->all());
            foreach ($messages as $m) {
                $c = $convs->get($m->conversation_id);
                $sender = ChatPresenter::sender($m, $me, $people)['name'] ?? '';
                $alerts[] = [
                    'id' => $m->id,
                    'conversation_id' => (int) $m->conversation_id,
                    'title' => $c && $c->isGroup() ? (string) $c->title : $sender,
                    'group' => (bool) $c?->isGroup(),
                    'sender' => $sender,
                    'text' => self::preview($m, 80),
                    'url' => '/chat?c=' . $m->conversation_id,
                ];
            }
            $last = max($after, (int) ($ids[0] ?? 0));
        } else {
            $last = (int) DB::table('chat_messages as m')
                ->join('chat_members as cm', fn ($j) => $j->on('cm.conversation_id', '=', 'm.conversation_id')->where('cm.user_id', '=', $me->id)->whereNull('cm.left_at'))
                ->max('m.id');
        }

        return ['unread' => ChatPresenter::unreadTotal($me->id), 'last_id' => $last, 'alerts' => $alerts];
    }

    public static function mute(ChatMember $member, string $mute): ?Carbon
    {
        $until = match ($mute) {
            '8h' => now()->addHours(8),
            'on' => Carbon::parse(self::MUTED_FOREVER),
            default => null,
        };
        $member->forceFill(['muted_until' => $until])->save();

        return $until;
    }

    // ── search ───────────────────────────────────────────────────────────────

    /** Sa sariling mga conversation lang (pati ng CEO): mga chat ayon sa pangalan, at mga message ayon sa text. */
    public static function search(User $me, string $q): array
    {
        $q = trim(mb_substr($q, 0, 60));
        if (mb_strlen($q) < 2) {
            return ['chats' => [], 'messages' => []];
        }
        $needle = mb_strtolower($q);
        $rows = collect(ChatPresenter::rows($me));
        $chats = $rows->filter(fn ($r) => str_contains(mb_strtolower((string) $r['title']), $needle))->take(20)->values()->all();

        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle) . '%';
        $found = ChatMessage::query()->select('chat_messages.*')
            ->join('chat_members as cm', fn ($j) => $j->on('cm.conversation_id', '=', 'chat_messages.conversation_id')->where('cm.user_id', '=', $me->id))
            // Ang umalis na: hanggang sa pag-alis lang.
            ->where(fn ($w) => $w->whereNull('cm.left_at')->orWhereColumn('chat_messages.created_at', '<=', 'cm.left_at'))
            ->where(fn ($w) => $w->whereNotNull('chat_messages.sender_user_id')->orWhereNotNull('chat_messages.sender_ai'))
            ->whereRaw("LOWER(chat_messages.body) LIKE ? ESCAPE '!'", [$like])
            ->orderByDesc('chat_messages.id')->limit(30)->get();
        $titles = $rows->pluck('title', 'id');
        $people = ChatPeople::lookup($found->pluck('sender_user_id')->all());

        $messages = $found->map(function (ChatMessage $m) use ($me, $people, $titles, $needle) {
            $s = ChatPresenter::sender($m, $me, $people);

            return [
                'id' => $m->id,
                'conversation_id' => (int) $m->conversation_id,
                'title' => (string) ($titles[$m->conversation_id] ?? ''),
                'sender' => $s['me'] ? 'You' : (string) $s['name'],
                'text' => self::snippet((string) $m->body, $needle),
                'at' => ChatPresenter::iso($m->created_at),
            ];
        })->values()->all();

        return ['chats' => $chats, 'messages' => $messages];
    }

    /** Isang linya sa paligid ng nahanap: mga 40 character bago nito, hanggang 140 lahat. */
    private static function snippet(string $body, string $needle): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $body));
        $at = mb_stripos($text, $needle);
        if ($at !== false && $at > 40) {
            $text = '…' . ltrim(mb_substr($text, $at - 30));
        }

        return Str::limit($text, 139, '…');
    }
}
