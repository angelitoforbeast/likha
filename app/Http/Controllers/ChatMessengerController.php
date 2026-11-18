<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatLive;
use App\Services\Chat\ChatMessenger;
use App\Services\Chat\ChatPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * /chat gaya ng Messenger (spec 300): reactions, edit, typing, mute, search at voice. Bawat route na may conversation
 * ay tumitingin sa membership (o CEO) sa server, gaya ng ChatController; ang mag-post, mag-react, mag-edit, mag-type
 * at mag-mute ay aktibong miyembro lang (ang CEO ay kailangang mag-join muna).
 */
class ChatMessengerController extends Controller
{
    /** Isang reaction bawat tao: bago = idagdag o palitan; pareho = alisin. */
    public function react(Request $request, int $conversation, int $message): JsonResponse
    {
        [$c, $me, $member] = $this->postable($request, $conversation);
        $emoji = $request->validate(['emoji' => ['required', 'string', Rule::in(config('chat.reactions'))]])['emoji'];
        $m = ChatMessage::withTrashed()->where('conversation_id', $c->id)->findOrFail($message);
        if ($m->trashed() || $m->isSystem()) {
            throw ValidationException::withMessages(['emoji' => 'You cannot react to this message.']);
        }

        DB::transaction(function () use ($m, $me, $emoji) {
            $m = ChatMessage::whereKey($m->id)->lockForUpdate()->first();
            $map = $m->reactions ?? [];
            $key = (string) $me->id;
            if (($map[$key] ?? null) === $emoji) {
                unset($map[$key]);
            } else {
                $map[$key] = $emoji;
            }
            $m->reactions = $map ?: null;
            // Kahit walang ibang nagbago: ang updated_at ang nagdadala nito sa poll ng iba.
            $m->updated_at = now();
            $m->save();
        });

        return response()->json(['message' => ChatPresenter::one($m->fresh(), $me)]);
    }

    /** Sariling text message, sa loob ng 15 minuto; ang naunang text ay itinatago (CEO lang ang nakakakita). */
    public function edit(Request $request, int $conversation, int $message): JsonResponse
    {
        [$c, $me, $member] = $this->readable($request, $conversation);
        $m = ChatMessage::withTrashed()->where('conversation_id', $c->id)->findOrFail($message);
        abort_unless((int) $m->sender_user_id === $me->id && $m->sender_ai === null && ChatAccess::mayPost($member), 403);
        if ($m->trashed()) {
            throw ValidationException::withMessages(['body' => 'A removed message cannot be edited.']);
        }
        if (trim((string) $m->body) === '') {
            throw ValidationException::withMessages(['body' => 'Only text can be edited.']);
        }
        if ($m->created_at->copy()->addMinutes((int) config('chat.edit_minutes', 15))->isPast()) {
            throw ValidationException::withMessages(['body' => 'This message can no longer be edited.']);
        }
        $body = trim((string) $request->validate(['body' => 'required|string|max:' . (int) config('chat.max_body')])['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write a message.']);
        }

        if ($body !== $m->body) {
            $history = $m->edit_history ?? [];
            $history[] = ['body' => $m->body, 'at' => ChatPresenter::iso($m->edited_at ?? $m->created_at)];
            $m->forceFill(['body' => $body, 'edited_at' => now(), 'edit_history' => $history])->save();
        }

        return response()->json(['message' => ChatPresenter::one($m->fresh(), $me)]);
    }

    public function typing(Request $request, int $conversation): Response
    {
        [$c, $me] = $this->postable($request, $conversation);
        ChatLive::typing($c->id, $me->id);

        return response()->noContent();
    }

    /** 8 oras, hanggang i-unmute ("on"), o "off". Sariling membership lang ang binabago. */
    public function mute(Request $request, int $conversation): JsonResponse
    {
        [, , $member] = $this->postable($request, $conversation);
        $mute = $request->validate(['mute' => 'required|string|in:8h,on,off'])['mute'];
        $until = ChatMessenger::mute($member, $mute);

        return response()->json(['muted_until' => $until && $until->year < 9999 ? $until->toIso8601String() : ($until ? 'on' : null)]);
    }

    public function search(Request $request): JsonResponse
    {
        $q = $request->query('q');

        return response()->json(ChatMessenger::search($request->user(), is_string($q) ? $q : ''));
    }

    /**
     * Voice message: ang uri ay mula sa bytes (webm, ogg, m4a, mp3), hanggang 5 MB at 2 minuto. Itinatago gaya ng 296
     * files (random na pangalan sa chat disk); ibinibigay lang sa chat.voice route.
     */
    public function voice(Request $request, int $conversation): JsonResponse
    {
        [$c, $me, $member] = $this->postable($request, $conversation);
        $types = config('chat.voice_types');
        $data = $request->validate([
            'voice' => 'required|file|max:' . (int) config('chat.voice_max_kb'),
            'duration' => 'required|integer|min:1|max:' . (int) config('chat.voice_max_seconds'),
        ], [
            'voice.max' => 'Voice message is too large.',
            'duration.max' => 'Voice message is too long (2 minutes at most).',
        ]);
        $file = $request->file('voice');
        // Ang uri ay mula mismo sa bytes (finfo), hindi sa pangalan o sa sinabi ng browser.
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath()) ?: '';
        $ext = $types[$detected] ?? null;
        if ($ext === null) {
            throw ValidationException::withMessages(['voice' => 'This recording format cannot be sent.']);
        }
        $path = $file->storeAs('chat/' . now()->toDateString(), Str::random(40) . ".$ext", config('chat.disk', 'public'));

        $m = DB::transaction(function () use ($c, $me, $member, $path, $file, $ext, $data) {
            $m = ChatMessage::create([
                'conversation_id' => $c->id,
                'sender_user_id' => $me->id,
                'body' => null,
                'attachments' => [[
                    'path' => $path, 'name' => 'Voice message', 'size' => (int) $file->getSize(),
                    'mime' => ChatMessenger::VOICE_CONTENT_TYPES[$ext], 'kind' => 'audio', 'duration' => (int) $data['duration'],
                ]],
            ]);
            $c->forceFill(['last_message_at' => $m->created_at])->save();
            $member->forceFill(['last_read_message_id' => $m->id])->save();

            return $m;
        });

        return response()->json(['message' => ChatPresenter::one($m, $me)], 201);
    }

    /** Ang voice ng isang message: miyembro o CEO, hindi removed; may range (para sa Safari), hindi kailanman HTML. */
    public function voiceFile(Request $request, int $conversation, int $message): Response
    {
        [$c, $me, $member] = $this->readable($request, $conversation);
        $q = ChatMessage::where('conversation_id', $c->id)->whereKey($message);
        if ($until = ChatAccess::visibleUntil($me, $member)) {
            $q->where('created_at', '<=', $until);
        }
        $f = collect($q->first()?->attachments ?? [])->first(fn ($a) => ($a['kind'] ?? '') === 'audio');
        $disk = Storage::disk(config('chat.disk', 'public'));
        $path = is_array($f) ? ($f['path'] ?? null) : null;
        abort_if(! is_string($path) || ! str_starts_with($path, 'chat/') || ! $disk->exists($path), 404);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = ChatMessenger::VOICE_CONTENT_TYPES[$ext] ?? null;
        abort_if($type === null, 404);

        $headers = [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; media-src 'self'; sandbox",
            'Cache-Control' => 'private, max-age=86400',
        ];
        $local = rescue(fn () => $disk->path($path), null, false);
        if (is_string($local) && is_file($local)) {
            return response()->file($local, $headers)->setPrivate()->setContentDisposition('inline', "voice.$ext");
        }

        return $disk->response($path, "voice.$ext", $headers, 'inline');
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

    /** Gaya ng readable, pero aktibong miyembro lang (ang CEO ay kailangang mag-join). */
    private function postable(Request $request, int $id): array
    {
        [$c, $me, $member] = $this->readable($request, $id);
        abort_unless(ChatAccess::mayPost($member), 403, 'Join this conversation first.');

        return [$c, $me, $member];
    }
}
