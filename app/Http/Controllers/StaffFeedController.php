<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only staff feed para sa assistant ng owner: sino ang aktibo, ano ang huling ginawa at ilan ngayong araw.
 * Sariling key (X-STAFF-FEED-KEY) para mabawi nang hiwalay sa automation key.
 *
 * Bawat query ay may tahasang listahan ng column at walang model serialisation: walang email, password,
 * token, session id/payload, IP, user agent, o ibang employee_profiles column na lumalabas.
 */
class StaffFeedController extends Controller
{
    private const TZ = 'Asia/Manila';

    /**
     * Mga employee_profiles.status na tahasang hindi na aktibo (lowercase, trimmed). Ang ibang value,
     * pati NULL o blangko, ay aktibo: maraming staff ang walang status pero araw-araw pumapasok.
     */
    private const INACTIVE_STATUSES = ['inactive', 'resigned', 'terminated'];

    private function authorizeFeed(Request $request): void
    {
        $expected = (string) config('services.staff_feed.key');
        abort_if($expected === '', 503, 'Staff feed is not configured');

        $key = (string) $request->header('X-STAFF-FEED-KEY');
        abort_unless($key !== '' && hash_equals($expected, $key), 401, 'Unauthorized');
    }

    public function status(Request $request): JsonResponse
    {
        $this->authorizeFeed($request);

        $date = $request->query('date');
        if ($date !== null && (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date))) {
            return response()->json(['message' => 'date must be YYYY-MM-DD'], 422);
        }
        $day = $date ? Carbon::parse($date, self::TZ)->startOfDay() : Carbon::now(self::TZ)->startOfDay();
        $dayStr = $day->toDateString();
        $next = $day->copy()->addDay();

        $staff = DB::table('employee_profiles as ep')
            ->join('users as u', 'u.id', '=', 'ep.user_id')
            ->where(function ($q) {
                $q->whereNull('ep.status')
                    ->orWhereRaw('LOWER(TRIM(ep.status)) NOT IN (?, ?, ?)', self::INACTIVE_STATUSES);
            })
            ->orderBy('u.id')
            ->get([
                'u.id', 'u.name as user_name', 'ep.name', 'ep.role', 'ep.department',
                'u.last_seen_at', 'u.last_seen_path', 'u.last_login_at',
            ]);
        $ids = $staff->pluck('id')->map(fn ($id) => (int) $id)->all();

        $online = $this->onlineUserIds($ids);

        $counts = [];
        $lastEvents = collect();
        if ($ids && Schema::hasTable('staff_events')) {
            DB::table('staff_events')
                ->whereIn('user_id', $ids)
                ->where('created_at', '>=', $day->format('Y-m-d H:i:s'))
                ->where('created_at', '<', $next->format('Y-m-d H:i:s'))
                ->groupBy('user_id', 'kind')
                ->orderBy('kind')
                ->get(['user_id', 'kind', DB::raw('COUNT(*) as n')])
                ->each(function ($r) use (&$counts) {
                    $counts[(int) $r->user_id][$r->kind] = (int) $r->n;
                });

            $latestIds = DB::table('staff_events')->whereIn('user_id', $ids)->groupBy('user_id')->select(DB::raw('MAX(id)'));
            $lastEvents = DB::table('staff_events')->whereIn('id', $latestIds)
                ->get(['user_id', 'kind', 'created_at'])
                ->keyBy(fn ($r) => (int) $r->user_id);
        }

        $dueOpen = $this->checklistDueNotSubmitted($ids, $day);
        $bots = $this->botUserIds();

        return response()->json([
            'date'         => $dayStr,
            'generated_at' => Carbon::now(self::TZ)->toIso8601String(),
            'staff'        => $staff->map(function ($s) use ($online, $counts, $lastEvents, $dueOpen, $bots) {
                $id = (int) $s->id;
                $last = $lastEvents->get($id);

                return [
                    'user_id'        => $id,
                    'name'           => $s->name ?: $s->user_name,
                    'role'           => $s->role,
                    'department'     => $s->department,
                    'last_seen_at'   => $this->iso($s->last_seen_at),
                    'last_seen_path' => $s->last_seen_path,
                    'last_login_at'  => $this->iso($s->last_login_at),
                    'online'         => $online === null ? null : isset($online[$id]),
                    'last_event'     => $last ? ['kind' => $last->kind, 'at' => $this->iso($last->created_at)] : null,
                    'counts'         => (object) ($counts[$id] ?? []),
                    'checklist_due_not_submitted' => $dueOpen[$id] ?? [],
                    'is_bot'         => isset($bots[$id]),
                ];
            })->values(),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $this->authorizeFeed($request);

        $since = $request->query('since');
        try {
            if (!is_string($since) || trim($since) === '') {
                throw new \InvalidArgumentException();
            }
            $sinceAt = Carbon::parse($since, self::TZ);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'since is required (date or datetime)'], 422);
        }
        $userId = $request->query('user_id');
        if ($userId !== null && !ctype_digit((string) $userId)) {
            return response()->json(['message' => 'user_id must be an integer'], 422);
        }
        $limit = ctype_digit((string) $request->query('limit', '')) ? (int) $request->query('limit') : 200;
        $limit = max(1, min(1000, $limit));

        $rows = collect();
        if (Schema::hasTable('staff_events')) {
            $rows = DB::table('staff_events')
                ->where('created_at', '>=', $sinceAt->format('Y-m-d H:i:s'))
                ->when($userId !== null, fn ($q) => $q->where('user_id', (int) $userId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get(['user_id', 'role', 'kind', 'method', 'status', 'ref', 'created_at']);
        }

        $userIds = $rows->pluck('user_id')->unique()->values()->all();
        $names = $userIds ? DB::table('users as u')
            ->leftJoin('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->whereIn('u.id', $userIds)
            ->get(['u.id', 'u.name as user_name', 'ep.name'])
            ->mapWithKeys(fn ($r) => [(int) $r->id => $r->name ?: $r->user_name]) : collect();

        $bots = $this->botUserIds();

        return response()->json([
            'since'  => $sinceAt->toIso8601String(),
            'count'  => $rows->count(),
            'events' => $rows->map(fn ($r) => [
                'user_id'    => (int) $r->user_id,
                'name'       => $names[(int) $r->user_id] ?? null,
                'role'       => $r->role,
                'kind'       => $r->kind,
                'method'     => $r->method,
                'status'     => $r->status === null ? null : (int) $r->status,
                'ref'        => $r->ref,
                'created_at' => $this->iso($r->created_at),
                'is_bot'     => isset($bots[(int) $r->user_id]),
            ])->values(),
        ]);
    }

    /** user_id => true ng mga automation account (STAFF_FEED_BOT_USER_IDS, comma-separated); hindi integer na bahagi ay nilalaktawan. */
    private function botUserIds(): array
    {
        $out = [];
        foreach (explode(',', (string) config('services.staff_feed.bot_user_ids')) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $out[(int) $part] = true;
            }
        }

        return $out;
    }

    /** user_id => true ng may session na aktibo sa huling 10 minuto; null kapag hindi database ang session driver. */
    private function onlineUserIds(array $ids): ?array
    {
        if (config('session.driver') !== 'database') {
            return null;
        }
        $table = (string) config('session.table', 'sessions');
        if (!Schema::hasTable($table)) {
            return null;
        }
        if (!$ids) {
            return [];
        }

        return DB::table($table)
            ->whereIn('user_id', $ids)
            ->where('last_activity', '>=', Carbon::now()->subMinutes(10)->timestamp)
            ->distinct()
            ->pluck('user_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * user_id => [titles]: aktibong checklist task na naka-assign sa user, due sa araw na iyon (parehong rules ng
     * checklist page: frequency, frequency_day, 'once' hanggang sa unang submission, umiiral na at hindi pa
     * binura noon), at walang submission sa araw na iyon. Group task: sapat ang submission ng kahit sinong
     * naka-assign; individual: ang sariling submission lang ng user.
     */
    private function checklistDueNotSubmitted(array $ids, Carbon $day): array
    {
        if (!$ids || !Schema::hasTable('checklist_tasks') || !Schema::hasTable('checklist_task_users')
            || !Schema::hasTable('checklist_submissions')) {
            return [];
        }
        $dayStr = $day->toDateString();
        $nextStr = $day->copy()->addDay()->toDateString();

        $assigned = DB::table('checklist_tasks as t')
            ->join('checklist_task_users as tu', 'tu.checklist_task_id', '=', 't.id')
            ->whereIn('tu.user_id', $ids)
            ->where('t.is_active', true)
            ->where('t.created_at', '<', $nextStr)
            ->where(function ($q) use ($nextStr) {
                $q->whereNull('t.deleted_at')->orWhere('t.deleted_at', '>=', $nextStr);
            })
            ->orderBy('t.sort_order')
            ->orderBy('t.id')
            ->get(['t.id', 't.title', 't.frequency', 't.frequency_day', 't.submission_type', 'tu.user_id']);
        if ($assigned->isEmpty()) {
            return [];
        }
        $taskIds = $assigned->pluck('id')->unique()->values()->all();

        // < kinabukasan: tama rin kahit may oras ang naka-store na date
        $subsToday = DB::table('checklist_submissions')
            ->whereIn('checklist_task_id', $taskIds)
            ->where('date', '>=', $dayStr)
            ->where('date', '<', $nextStr)
            ->get(['checklist_task_id', 'user_id']);
        $doneByTask = [];
        $doneByUser = [];
        foreach ($subsToday as $s) {
            $doneByTask[(int) $s->checklist_task_id] = true;
            $doneByUser[(int) $s->checklist_task_id][(int) $s->user_id] = true;
        }

        // 'once': due hanggang sa araw ng unang submission (kasama), hindi na pagkatapos
        $onceIds = $assigned->where('frequency', 'once')->pluck('id')->unique()->values()->all();
        $onceDoneBefore = $onceIds ? DB::table('checklist_submissions')
            ->whereIn('checklist_task_id', $onceIds)
            ->where('date', '<', $dayStr)
            ->distinct()
            ->pluck('checklist_task_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all() : [];

        $out = [];
        foreach ($assigned as $t) {
            $tid = (int) $t->id;
            $uid = (int) $t->user_id;
            $due = match ($t->frequency ?? 'daily') {
                'weekly'  => (int) $day->dayOfWeek === (int) $t->frequency_day,
                'monthly' => (int) $day->day === (int) $t->frequency_day,
                'once'    => !isset($onceDoneBefore[$tid]),
                default   => true,
            };
            if (!$due) {
                continue;
            }
            $submitted = ($t->submission_type ?? 'group') === 'individual'
                ? isset($doneByUser[$tid][$uid])
                : isset($doneByTask[$tid]);
            if (!$submitted) {
                $out[$uid][] = $t->title;
            }
        }

        return $out;
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value, self::TZ)->toIso8601String() : null;
    }
}
