<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatPeople;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * /chat groups (spec 296). Gumagawa: ang CEO o sinumang OIC. Admin: ang gumawa at ang CEO (ang CEO ay admin ng lahat
 * ng group, miyembro man o hindi). Ang admin ang nagpapalit ng pangalan at nagdadagdag o nag-aalis; sinumang miyembro ay
 * puwedeng umalis. Ang CEO na hindi miyembro ay nag-join muna bago mag-post. Bawat pagbabago ay may system line
 * ("Ana joined") para makita ng lahat; walang binubura: ang umalis ay may left_at.
 */
class ChatGroupController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless(ChatAccess::mayCreateGroup($me), 403, 'Only the CEO and OICs can create groups.');
        $data = $request->validate($this->groupRules() + ['title' => 'required|string|max:80']);
        $ids = $this->activeOthers($data['user_ids'], $me);

        $id = DB::transaction(function () use ($me, $data, $ids) {
            $c = ChatConversation::create(['kind' => ChatConversation::GROUP, 'title' => trim($data['title']), 'created_by' => $me->id]);
            ChatMember::create(['conversation_id' => $c->id, 'user_id' => $me->id, 'role' => ChatMember::ADMIN, 'joined_at' => now()]);
            $ceoIds = $this->ceoIds($ids);
            foreach ($ids as $uid) {
                ChatMember::create([
                    'conversation_id' => $c->id, 'user_id' => $uid, 'joined_at' => now(),
                    'role' => in_array($uid, $ceoIds, true) ? ChatMember::ADMIN : ChatMember::MEMBER,
                ]);
            }
            $this->systemLine($c, $this->name($me) . ' created the group');

            return $c->id;
        });

        return response()->json(['id' => $id]);
    }

    public function rename(Request $request, int $conversation): JsonResponse
    {
        [$c, $me] = $this->manageable($request, $conversation);
        $title = trim($request->validate(['title' => 'required|string|max:80'])['title']);
        if ($title !== $c->title) {
            $c->forceFill(['title' => $title])->save();
            $this->systemLine($c, $this->name($me) . ' renamed the group to "' . $title . '"');
        }

        return response()->json(['ok' => true]);
    }

    public function addMembers(Request $request, int $conversation): JsonResponse
    {
        [$c, $me] = $this->manageable($request, $conversation);
        $ids = $this->activeOthers($request->validate($this->groupRules())['user_ids'], $me);

        DB::transaction(function () use ($c, $me, $ids) {
            $ceoIds = $this->ceoIds($ids);
            $lastId = (int) ChatMessage::withTrashed()->where('conversation_id', $c->id)->max('id');
            $added = [];
            foreach ($ids as $uid) {
                $row = ChatMember::where('conversation_id', $c->id)->where('user_id', $uid)->first();
                if ($row && $row->isActive()) {
                    continue;
                }
                $fields = [
                    'left_at' => null, 'joined_at' => now(), 'last_read_message_id' => $lastId,
                    'role' => in_array($uid, $ceoIds, true) ? ChatMember::ADMIN : ($row->role ?? ChatMember::MEMBER),
                ];
                $row ? $row->forceFill($fields)->save() : ChatMember::create($fields + ['conversation_id' => $c->id, 'user_id' => $uid]);
                $added[] = $uid;
            }
            if ($added) {
                $names = ChatPeople::lookup($added)->pluck('name')->all();
                $this->systemLine($c, $this->name($me) . ' added ' . implode(', ', $names));
            }
        });

        return response()->json(['ok' => true]);
    }

    public function removeMember(Request $request, int $conversation, int $user): JsonResponse
    {
        [$c, $me] = $this->manageable($request, $conversation);
        $row = ChatMember::where('conversation_id', $c->id)->where('user_id', $user)->whereNull('left_at')->firstOrFail();

        DB::transaction(function () use ($c, $me, $row) {
            $text = $row->user_id === $me->id
                ? $this->name($me) . ' left the group'
                : $this->name($me) . ' removed ' . (ChatPeople::lookup([$row->user_id])->get((int) $row->user_id)['name'] ?? 'someone');
            $this->systemLine($c, $text);
            $row->forceFill(['left_at' => now()])->save();
        });

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request, int $conversation): JsonResponse
    {
        [$c, $me, $member] = $this->readable($request, $conversation);
        if (! $c->isGroup()) {
            throw ValidationException::withMessages(['conversation' => 'You cannot leave a direct chat.']);
        }
        if (! $member || ! $member->isActive()) {
            throw ValidationException::withMessages(['conversation' => 'You are not in this group.']);
        }

        DB::transaction(function () use ($c, $me, $member) {
            $this->systemLine($c, $this->name($me) . ' left the group');
            $member->forceFill(['left_at' => now()])->save();
        });

        return response()->json(['ok' => true]);
    }

    /** CEO lang: sumali sa group para makapag-post; nakikita ng lahat bilang "<name> joined". */
    public function join(Request $request, int $conversation): JsonResponse
    {
        [$c, $me, $member] = $this->readable($request, $conversation);
        abort_unless(ChatAccess::isCeo($me), 403);
        if (! $c->isGroup()) {
            throw ValidationException::withMessages(['conversation' => 'Only groups can be joined.']);
        }
        if ($member && $member->isActive()) {
            throw ValidationException::withMessages(['conversation' => 'You are already in this group.']);
        }

        DB::transaction(function () use ($c, $me, $member) {
            $fields = ['role' => ChatMember::ADMIN, 'left_at' => null, 'joined_at' => now()];
            $row = $member ?? new ChatMember(['conversation_id' => $c->id, 'user_id' => $me->id]);
            $row->forceFill($fields)->save();
            $id = $this->systemLine($c, $this->name($me) . ' joined');
            $row->forceFill(['last_read_message_id' => $id])->save();
        });

        return response()->json(['ok' => true]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function groupRules(): array
    {
        return ['user_ids' => 'required|array|min:1|max:100', 'user_ids.*' => 'integer|distinct'];
    }

    private function readable(Request $request, int $id): array
    {
        $c = ChatConversation::findOrFail($id);
        $me = $request->user();
        $member = ChatAccess::membership($c, $me);
        abort_unless(ChatAccess::mayRead($me, $member), 403);

        return [$c, $me, $member];
    }

    /** Group na puwedeng ayusin ng tao: 403 kung hindi miyembro (o CEO) o hindi admin; 422 kung direct chat. */
    private function manageable(Request $request, int $id): array
    {
        [$c, $me, $member] = $this->readable($request, $id);
        if (! $c->isGroup()) {
            throw ValidationException::withMessages(['conversation' => 'A direct chat has no name or member list to change.']);
        }
        abort_unless(ChatAccess::mayManage($c, $me, $member), 403, 'Only the group admins and the CEO can change this group.');

        return [$c, $me, $member];
    }

    /** Ang mga aktibong empleyado sa listahan (hindi kasama ang sarili); 422 kapag may hindi aktibo o wala. @return int[] */
    private function activeOthers(array $ids, User $me): array
    {
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), [$me->id]));
        $active = ChatPeople::activeIds($ids);
        if (! $ids || count($active) !== count($ids)) {
            throw ValidationException::withMessages(['user_ids' => 'Pick active employees only.']);
        }

        return $active;
    }

    /** @return int[] */
    private function ceoIds(array $ids): array
    {
        return $ids ? DB::table('employee_profiles')->whereIn('user_id', $ids)->whereRaw('TRIM(role) = ?', ['CEO'])
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all() : [];
    }

    private function name(User $user): string
    {
        return ChatPeople::lookup([$user->id])->get($user->id)['name'] ?? (string) $user->name;
    }

    /** Isang system line (walang sender) at ang activity ng conversation. */
    private function systemLine(ChatConversation $c, string $text): int
    {
        $m = ChatMessage::create(['conversation_id' => $c->id, 'body' => $text]);
        $c->forceFill(['last_message_at' => $m->created_at])->save();

        return $m->id;
    }
}
