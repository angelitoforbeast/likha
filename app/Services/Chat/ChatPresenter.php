<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Likha Chat (spec 296): ang JSON ng listahan, ng thread at ng bawat message. Tahasang mga field lang (walang model
 * serialisation): pangalan, initials at role ang lumalabas sa tao, walang email o ibang profile column.
 */
class ChatPresenter
{
    public const LIST_LIMIT = 500;

    /**
     * Ang mga conversation ng tao, pinakabagong activity muna. $all = lahat ng conversation (CEO lang, tinitingnan ng
     * caller). $since = ang may activity mula noon lang (poll).
     */
    public static function rows(User $viewer, bool $all = false, ?string $since = null): array
    {
        $q = ChatConversation::query()->select('chat_conversations.*')
            ->addSelect('me.id as my_member_id', 'me.left_at as my_left_at');
        $join = fn ($j) => $j->on('me.conversation_id', '=', 'chat_conversations.id')->where('me.user_id', '=', $viewer->id);
        $all ? $q->leftJoin('chat_members as me', $join) : $q->join('chat_members as me', $join);
        // Ang umalis na ay walang bagong activity: ang oras ng pag-alis niya ang activity ng row niya.
        $activity = 'CASE WHEN me.left_at IS NOT NULL THEN me.left_at'
            . ' ELSE COALESCE(chat_conversations.last_message_at, chat_conversations.created_at) END';
        if ($since !== null) {
            $q->whereRaw("$activity >= ?", [$since]);
        }
        $convs = $q->orderByRaw("$activity DESC")
            ->orderByDesc('chat_conversations.id')
            ->limit(self::LIST_LIMIT)
            ->get();
        if ($convs->isEmpty()) {
            return [];
        }
        $ids = $convs->pluck('id')->all();

        $last = ChatMessage::withTrashed()
            ->whereIn('id', ChatMessage::withTrashed()->selectRaw('MAX(id)')->whereIn('conversation_id', $ids)->groupBy('conversation_id'))
            ->get()->keyBy('conversation_id');
        $unread = self::unreadQuery($viewer->id)->whereIn('m.conversation_id', $ids)
            ->groupBy('m.conversation_id')->selectRaw('m.conversation_id, COUNT(*) as n')->pluck('n', 'conversation_id');
        $directMembers = ChatMember::whereIn('conversation_id', $convs->where('kind', ChatConversation::DIRECT)->pluck('id'))
            ->orderBy('id')->get(['conversation_id', 'user_id'])->groupBy('conversation_id');
        $people = ChatPeople::lookup(array_merge(
            $directMembers->flatten()->pluck('user_id')->all(),
            $last->pluck('sender_user_id')->all(),
        ));

        return $convs->map(function (ChatConversation $c) use ($viewer, $last, $unread, $directMembers, $people) {
            $member = $c->my_member_id !== null;
            $left = $c->my_left_at !== null;
            $m = $last->get($c->id);
            [$title, $avatar] = self::titleAndAvatar($c, $viewer, $directMembers->get($c->id, collect())->pluck('user_id')->all(), $people);

            if ($left) {
                $line = ['text' => 'You left this group', 'sender' => null, 'ai' => false, 'at' => self::iso($c->my_left_at)];
            } elseif ($m) {
                $line = self::lastLine($m, $viewer, $people);
            } else {
                $line = null;
            }

            return [
                'id' => $c->id,
                'kind' => $c->kind,
                'title' => $title,
                'avatar' => $avatar,
                'last' => $line,
                'at' => self::iso($left ? $c->my_left_at : ($c->last_message_at ?? $c->created_at)),
                'unread' => $left ? 0 : (int) ($unread[$c->id] ?? 0),
                'member' => $member,
                'left' => $left,
            ];
        })->values()->all();
    }

    /** Hindi pa nababasa: mula sa iba (tao o AI), hindi removed, hindi system line, sa mga aktibong membership lang. */
    public static function unreadQuery(int $userId)
    {
        return DB::table('chat_messages as m')
            ->join('chat_members as cm', function ($j) use ($userId) {
                $j->on('cm.conversation_id', '=', 'm.conversation_id')->where('cm.user_id', '=', $userId)->whereNull('cm.left_at');
            })
            ->whereNull('m.deleted_at')
            ->whereRaw('m.id > COALESCE(cm.last_read_message_id, 0)')
            ->where(function ($w) use ($userId) {
                $w->whereNotNull('m.sender_ai')
                    ->orWhere(fn ($x) => $x->whereNotNull('m.sender_user_id')->where('m.sender_user_id', '!=', $userId));
            });
    }

    public static function unreadTotal(int $userId): int
    {
        // Spec 300: ang naka-mute ay hindi kasama sa kabuuan (badge, title); ang row niya ay may sariling bilang pa rin.
        return (int) ChatMessenger::notMuted(self::unreadQuery($userId))->count();
    }

    /** @param  int[]  $memberIds */
    public static function titleAndAvatar(ChatConversation $c, User $viewer, array $memberIds, Collection $people): array
    {
        if ($c->isGroup()) {
            $title = (string) $c->title;

            return [$title, ['initials' => ChatPeople::initials($title), 'tone' => null, 'group' => true]];
        }
        $others = array_values(array_filter($memberIds, fn ($id) => (int) $id !== $viewer->id));
        if (count($others) === 1) {
            $p = $people->get((int) $others[0]) ?? ChatPeople::card((int) $others[0], 'Unknown');

            return [$p['name'], ['initials' => $p['initials'], 'tone' => $p['tone'], 'group' => false]];
        }
        // Binabasa ng CEO ang direct chat ng dalawang iba: "Ana and Ben".
        $names = array_map(fn ($id) => ($people->get((int) $id)['name'] ?? 'Unknown'), $others);

        return [implode(' and ', $names), ['initials' => ChatPeople::initials(implode(' ', $names)), 'tone' => null, 'group' => false]];
    }

    private static function lastLine(ChatMessage $m, User $viewer, Collection $people): array
    {
        if ($m->trashed()) {
            $text = 'Message removed';
        } elseif (trim((string) $m->body) === '' && $m->attachments) {
            // Spec 300: pati "Voice message".
            $text = ChatMessenger::preview($m);
        } else {
            $text = ChatText::oneLine($m->body);
        }
        $sender = self::sender($m, $viewer, $people);

        return [
            'text' => $text,
            'sender' => $sender['kind'] === 'system' ? null : ($sender['me'] ? 'You' : $sender['name']),
            'ai' => $sender['kind'] === 'ai',
            'at' => self::iso($m->created_at),
        ];
    }

    public static function sender(ChatMessage $m, User $viewer, Collection $people): array
    {
        if ($m->sender_ai !== null) {
            $name = config("chat.ai_members.{$m->sender_ai}") ?: Str::headline($m->sender_ai);

            return ['kind' => 'ai', 'id' => null, 'key' => $m->sender_ai, 'name' => $name, 'initials' => ChatPeople::initials($name), 'tone' => null, 'me' => false];
        }
        if ($m->sender_user_id === null) {
            return ['kind' => 'system', 'id' => null, 'key' => null, 'name' => null, 'initials' => null, 'tone' => null, 'me' => false];
        }
        $p = $people->get((int) $m->sender_user_id) ?? ChatPeople::card((int) $m->sender_user_id, 'Unknown');

        return ['kind' => 'user', 'id' => $p['id'], 'key' => null, 'name' => $p['name'], 'initials' => $p['initials'], 'tone' => $p['tone'], 'me' => (int) $m->sender_user_id === $viewer->id];
    }

    /** @param  Collection<int, ChatMessage>  $messages */
    public static function messages(Collection $messages, User $viewer): array
    {
        if ($messages->isEmpty()) {
            return [];
        }
        $replyIds = $messages->pluck('reply_to_id')->filter()->unique()->all();
        $replies = $replyIds ? ChatMessage::withTrashed()->whereIn('id', $replyIds)->get()->keyBy('id') : collect();
        $people = ChatPeople::lookup(array_merge($messages->pluck('sender_user_id')->all(), $replies->pluck('sender_user_id')->all()));

        $out = $messages->map(fn (ChatMessage $m) => self::message($m, $viewer, $people, $replies))->values()->all();

        // Spec 300: reactions, "Edited", history (CEO), voice.
        return ChatMessenger::decorate($out, $messages->values(), $viewer);
    }

    public static function one(ChatMessage $m, User $viewer): array
    {
        return self::messages(collect([$m]), $viewer)[0];
    }

    private static function message(ChatMessage $m, User $viewer, Collection $people, Collection $replies): array
    {
        $removed = $m->trashed();
        $reply = null;
        if ($m->reply_to_id && ($r = $replies->get($m->reply_to_id)) && (int) $r->conversation_id === (int) $m->conversation_id) {
            $rs = self::sender($r, $viewer, $people);
            $reply = [
                'id' => $r->id,
                'name' => $rs['me'] ? 'You' : ($rs['name'] ?? ''),
                'text' => $r->trashed() ? null : (trim((string) $r->body) !== '' ? ChatText::oneLine($r->body, 160) : (count($r->attachments ?? []) ? 'Attachment' : '')),
                'removed' => $r->trashed(),
            ];
        }
        $files = [];
        if (! $removed) {
            foreach (array_values($m->attachments ?? []) as $i => $f) {
                $files[] = [
                    'url' => route('chat.file', ['conversation' => $m->conversation_id, 'message' => $m->id, 'index' => $i]),
                    'name' => (string) ($f['name'] ?? 'file'),
                    'size' => (int) ($f['size'] ?? 0),
                    'kind' => ($f['kind'] ?? 'file') === 'image' ? 'image' : 'file',
                ];
            }
        }
        $body = (string) $m->body;

        return [
            'id' => $m->id,
            'sender' => self::sender($m, $viewer, $people),
            'html' => $removed ? null : ($body !== '' ? ChatText::toHtml($body) : ''),
            'text' => $removed ? null : $body,
            'removed' => $removed,
            'at' => self::iso($m->created_at),
            'reply' => $reply,
            'files' => $files,
        ];
    }

    /** Ang mga aktibong miyembro maliban sa tumitingin, at ang huling nabasa nila (para sa "Seen by"). */
    public static function reads(ChatConversation $c, User $viewer): array
    {
        $members = ChatMember::where('conversation_id', $c->id)->whereNull('left_at')->where('user_id', '!=', $viewer->id)->get();
        $people = ChatPeople::lookup($members->pluck('user_id')->all());

        return $members->map(fn (ChatMember $mb) => [
            'user_id' => (int) $mb->user_id,
            'name' => $people->get((int) $mb->user_id)['name'] ?? 'Unknown',
            'initials' => $people->get((int) $mb->user_id)['initials'] ?? '?',
            'tone' => (int) $mb->user_id % ChatPeople::TONES,
            'last_read_message_id' => (int) $mb->last_read_message_id,
        ])->values()->all();
    }

    public static function iso($at): ?string
    {
        return $at ? \Illuminate\Support\Carbon::parse($at)->toIso8601String() : null;
    }
}
