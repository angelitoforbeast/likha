<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatPeople;
use App\Services\Chat\ChatPresenter;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * /chat — Likha Chat (spec 296): ang Messenger ng team sa loob ng Likha. Naka-login lang (walang office IP check),
 * dahil sa phone nag-uusap ang staff. Bawat route na may conversation ay tumitingin sa membership (o CEO) sa server,
 * pati ang poll at ang file. Polling (bawat 3 s, hinto kapag nakatago ang tab), hindi websockets.
 */
class ChatController extends Controller
{
    /** Ang file na nakikita sa browser (inline) ay larawan lang; ang iba ay download. Walang SVG o HTML. */
    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
    ];

    public function index(Request $request)
    {
        $me = $request->user();
        $card = ChatPeople::lookup([$me->id])->get($me->id) ?? ChatPeople::card($me->id, (string) $me->name);
        $open = filter_var($request->query('c'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        return view('chat.index', [
            'boot' => [
                'me' => $card + ['ceo' => ChatAccess::isCeo($me), 'may_create_group' => ChatAccess::mayCreateGroup($me)],
                'open' => $open,
                'poll_ms' => (int) config('chat.poll_ms', 3000),
                'max_body' => (int) config('chat.max_body'),
                'max_files' => (int) config('chat.max_files'),
                'max_file_kb' => (int) config('chat.max_file_kb'),
                'accept' => implode(',', array_map(fn ($e) => ".$e", array_merge(config('chat.image_types'), config('chat.file_types')))),
            ],
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $me = $request->user();

        return response()->json([
            'conversations' => ChatPresenter::rows($me, $this->wantsAll($request, $me)),
            'unread' => ChatPresenter::unreadTotal($me->id),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function people(Request $request): JsonResponse
    {
        $q = $request->query('q');
        $q = is_string($q) ? mb_substr($q, 0, 60) : '';

        return response()->json(['people' => ChatPeople::search($q, $request->user()->id)]);
    }

    /** Buksan (o gawin) ang iisang direct chat ng dalawang tao. */
    public function direct(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|integer']);
        $me = $request->user();
        $other = (int) $data['user_id'];
        if ($other === $me->id) {
            throw ValidationException::withMessages(['user_id' => 'You cannot start a chat with yourself.']);
        }
        if (! ChatPeople::activeIds([$other])) {
            throw ValidationException::withMessages(['user_id' => 'That person is not an active employee.']);
        }

        $key = ChatConversation::directKey($me->id, $other);
        $existing = ChatConversation::where('direct_key', $key)->first();
        if ($existing) {
            return response()->json(['id' => $existing->id]);
        }
        try {
            $id = DB::transaction(function () use ($key, $me, $other) {
                $c = ChatConversation::create(['kind' => ChatConversation::DIRECT, 'direct_key' => $key, 'created_by' => $me->id]);
                foreach ([$me->id, $other] as $uid) {
                    ChatMember::create(['conversation_id' => $c->id, 'user_id' => $uid, 'role' => ChatMember::MEMBER, 'joined_at' => now()]);
                }

                return $c->id;
            });
        } catch (QueryException $e) {
            // Sabay nilang binuksan: ang unique direct_key ang nagbantay; ibalik ang nauna.
            $id = ChatConversation::where('direct_key', $key)->value('id') ?? throw $e;
        }

        return response()->json(['id' => $id]);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        [$c, $me, $member] = $this->readable($request, $conversation);
        $before = $request->query('before');
        if ($before !== null && filter_var($before, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw ValidationException::withMessages(['before' => 'Invalid message id.']);
        }
        $size = (int) config('chat.page_size', 50);

        $q = $this->visibleMessages($c, $me, $member);
        if ($before !== null) {
            $q->where('id', '<', (int) $before);
        }
        $rows = $q->orderByDesc('id')->limit($size + 1)->get();
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size)->reverse()->values();

        if ($before === null) {
            $this->markRead($c, $member);
        }

        return response()->json([
            'conversation' => $this->conversationInfo($c, $me, $member),
            'messages' => ChatPresenter::messages($rows, $me),
            'has_more' => $hasMore,
            'reads' => ChatPresenter::reads($c, $me),
            'now' => now()->toIso8601String(),
        ]);
    }

    /**
     * Ang maliit na poll (bawat 3 s): mga row ng listahan na may activity mula `since`, at kung may bukas na
     * conversation (`c`), ang mga message pagkatapos ng `after`, ang na-remove mula `since`, at ang "Seen by".
     */
    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'c' => 'nullable|integer|min:1',
            'after' => 'nullable|integer|min:0',
            'since' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', 'date'],
            'all' => 'nullable|boolean',
        ]);
        $me = $request->user();
        $since = isset($data['since']) ? Carbon::parse($data['since'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;

        $thread = null;
        if (isset($data['c'])) {
            [$c, , $member] = $this->readable($request, (int) $data['c']);
            $new = $this->visibleMessages($c, $me, $member)
                ->where('id', '>', (int) ($data['after'] ?? 0))
                ->whereNull('deleted_at')
                ->orderBy('id')->limit(200)->get();
            $removed = $since === null ? [] : $this->visibleMessages($c, $me, $member)
                ->whereNotNull('deleted_at')->where('deleted_at', '>=', $since)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($new->isNotEmpty()) {
                $this->markRead($c, $member);
            }
            $thread = [
                'messages' => ChatPresenter::messages($new, $me),
                'removed' => $removed,
                'reads' => ChatPresenter::reads($c, $me),
            ];
        }

        return response()->json([
            'conversations' => $since === null ? [] : ChatPresenter::rows($me, $this->wantsAll($request, $me), $since),
            'thread' => $thread,
            'unread' => ChatPresenter::unreadTotal($me->id),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function send(Request $request, int $conversation): JsonResponse
    {
        [$c, $me, $member] = $this->readable($request, $conversation);
        abort_unless(ChatAccess::mayPost($member), 403, 'Join this conversation to post.');

        $types = implode(',', array_merge(config('chat.image_types'), config('chat.file_types')));
        $data = $request->validate([
            'body' => 'nullable|string|max:' . (int) config('chat.max_body'),
            'files' => 'nullable|array|max:' . (int) config('chat.max_files'),
            'files.*' => 'file|max:' . (int) config('chat.max_file_kb') . "|mimes:$types",
            'reply_to_id' => 'nullable|integer',
        ], [
            'files.max' => 'At most :max files per message.',
            'files.*.max' => 'Each file must be 10 MB or smaller.',
            'files.*.mimes' => 'Only photos (JPG, PNG, GIF, WebP) and PDF, Word, Excel or CSV files can be sent.',
        ]);
        $body = trim((string) ($data['body'] ?? ''));
        $files = $request->file('files', []);
        if ($body === '' && ! $files) {
            throw ValidationException::withMessages(['body' => 'Write a message or add a file.']);
        }
        if (! empty($data['reply_to_id'])) {
            $ok = ChatMessage::where('conversation_id', $c->id)->where('id', (int) $data['reply_to_id'])->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['reply_to_id' => 'That message is not in this conversation.']);
            }
        }

        // Gaya ng checklist uploads: public disk, pangalang hash (hindi mahuhulaan); ibinibigay lang sa chat.file route.
        $attachments = [];
        $images = config('chat.image_types');
        foreach ($files as $file) {
            $path = $file->store('chat/' . now()->toDateString(), config('chat.disk', 'public'));
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $attachments[] = [
                'path' => $path,
                'name' => mb_substr(basename((string) $file->getClientOriginalName()), 0, 200) ?: "file.$ext",
                'size' => (int) $file->getSize(),
                'mime' => self::CONTENT_TYPES[$ext] ?? 'application/octet-stream',
                'kind' => in_array($ext, $images, true) ? 'image' : 'file',
            ];
        }

        $message = DB::transaction(function () use ($c, $me, $member, $body, $attachments, $data) {
            $m = ChatMessage::create([
                'conversation_id' => $c->id,
                'sender_user_id' => $me->id,
                'body' => $body !== '' ? $body : null,
                'reply_to_id' => ! empty($data['reply_to_id']) ? (int) $data['reply_to_id'] : null,
                'attachments' => $attachments ?: null,
            ]);
            $c->forceFill(['last_message_at' => $m->created_at])->save();
            $member->forceFill(['last_read_message_id' => $m->id])->save();

            return $m;
        });

        return response()->json(['message' => ChatPresenter::one($message, $me)], 201);
    }

    /** Sariling message lang; soft delete (nananatili ang row, "Message removed" ang nakikita). */
    public function remove(Request $request, int $conversation, int $message): JsonResponse
    {
        [$c, $me, $member] = $this->readable($request, $conversation);
        $m = ChatMessage::where('conversation_id', $c->id)->findOrFail($message);
        abort_unless((int) $m->sender_user_id === $me->id && ChatAccess::mayPost($member), 403);
        $m->delete();

        return response()->json(['ok' => true]);
    }

    public function read(Request $request, int $conversation): JsonResponse
    {
        [$c, , $member] = $this->readable($request, $conversation);
        $this->markRead($c, $member);

        return response()->json(['ok' => true]);
    }

    /** Kabuuang hindi pa nababasa (para sa nav badge sa susunod na spec). */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread' => ChatPresenter::unreadTotal($request->user()->id)]);
    }

    public function file(Request $request, int $conversation, int $message, int $index): Response
    {
        [$c, $me, $member] = $this->readable($request, $conversation);
        $m = $this->visibleMessages($c, $me, $member)->whereNull('deleted_at')->where('id', $message)->first();
        $f = $m?->attachments[$index] ?? null;
        $disk = Storage::disk(config('chat.disk', 'public'));
        abort_if(! $f || ! is_string($f['path'] ?? null) || ! str_starts_with($f['path'], 'chat/') || ! $disk->exists($f['path']), 404);

        $ext = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION));
        $type = self::CONTENT_TYPES[$ext] ?? 'application/octet-stream';
        $inline = ($f['kind'] ?? '') === 'image' && str_starts_with($type, 'image/');

        return $disk->response($f['path'], (string) ($f['name'] ?? "file.$ext"), [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=86400',
        ], $inline ? 'inline' : 'attachment');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Ang conversation (404 kung wala) at ang membership; 403 kapag hindi miyembro at hindi CEO. */
    private function readable(Request $request, int $id): array
    {
        $c = ChatConversation::findOrFail($id);
        $me = $request->user();
        $member = ChatAccess::membership($c, $me);
        abort_unless(ChatAccess::mayRead($me, $member), 403);

        return [$c, $me, $member];
    }

    private function visibleMessages(ChatConversation $c, User $me, ?ChatMember $member)
    {
        $q = ChatMessage::withTrashed()->where('conversation_id', $c->id);
        if ($until = ChatAccess::visibleUntil($me, $member)) {
            $q->where('created_at', '<=', $until);
        }

        return $q;
    }

    private function markRead(ChatConversation $c, ?ChatMember $member): void
    {
        if (! $member || ! $member->isActive()) {
            return;
        }
        $last = (int) ChatMessage::withTrashed()->where('conversation_id', $c->id)->max('id');
        if ($last > (int) $member->last_read_message_id) {
            $member->forceFill(['last_read_message_id' => $last])->save();
        }
    }

    private function wantsAll(Request $request, User $me): bool
    {
        return $request->boolean('all') && ChatAccess::isCeo($me);
    }

    private function conversationInfo(ChatConversation $c, User $me, ?ChatMember $member): array
    {
        $members = ChatMember::where('conversation_id', $c->id)->whereNull('left_at')->orderBy('id')->get();
        $all = ChatMember::where('conversation_id', $c->id)->pluck('user_id')->all();
        $people = ChatPeople::lookup($all);
        [$title, $avatar] = ChatPresenter::titleAndAvatar($c, $me, $all, $people);
        $active = $member?->isActive() ?? false;

        return [
            'id' => $c->id,
            'kind' => $c->kind,
            'title' => $title,
            'avatar' => $avatar,
            'members' => $members->map(fn (ChatMember $mb) => ($people->get((int) $mb->user_id) ?? ChatPeople::card((int) $mb->user_id, 'Unknown'))
                + ['admin' => $mb->role === ChatMember::ADMIN])->values()->all(),
            'me' => [
                'member' => $member !== null,
                'left' => $member !== null && ! $active,
                'admin' => $active && $member->role === ChatMember::ADMIN,
                'can_post' => ChatAccess::mayPost($member),
                'can_manage' => ChatAccess::mayManage($c, $me, $member),
                'can_join' => $c->isGroup() && ! $active && ChatAccess::isCeo($me),
                'can_leave' => $c->isGroup() && $active,
            ],
        ];
    }
}
