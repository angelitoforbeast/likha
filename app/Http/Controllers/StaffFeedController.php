<?php

namespace App\Http\Controllers;

use App\Services\ChecklistDueToday;
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

    /** Mga event na isinusulat ng Checker 1 saves at validate buttons, isa kada order na nagbago. */
    private const CHECKER_KINDS = ['checker.status', 'checker.edit', 'checker.validate'];

    private const CHECKER_ZERO = ['status_last_editor' => 0, 'edited_orders' => 0, 'status_changes' => 0, 'validates' => 0];

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

        $dueRows = ChecklistDueToday::rows($ids, $day);
        $dueOpen = $this->checklistDueNotSubmitted($dueRows);
        $late = $this->checklistLate($dueRows, $day);
        $bots = $this->botUserIds();
        $checker = $this->checkerCounts($day, $next);
        $approved = $this->checklistApproved($ids, $day);

        $rows = $staff->map(function ($s) use ($online, $counts, $lastEvents, $dueOpen, $bots, $checker, $approved, $late) {
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
                'checker'        => $checker[$id] ?? self::CHECKER_ZERO,
                'checklist_approved_today' => $approved[$id] ?? 0,
                'checklist_late_today' => $late[$id] ?? [],
            ];
        })->values();

        return response()->json([
            'date'         => $dayStr,
            'generated_at' => Carbon::now(self::TZ)->toIso8601String(),
            'staff'        => $rows,
            // kabuuan ng lahat ng nasa listahan: bilang lang
            'totals'       => [
                'checklist_approved_today' => $rows->sum('checklist_approved_today'),
                'status_changes'           => $rows->sum('checker.status_changes'),
                'edited_orders'            => $rows->sum('checker.edited_orders'),
            ],
            // ilang (tao, task) sa checklist_late_today ang wala pang submission
            'checklist_late_not_submitted' => $rows->sum(fn ($r) => count(array_filter($r['checklist_late_today'], fn ($e) => !$e['submitted']))),
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
                ->get(array_merge(
                    ['user_id', 'role', 'kind', 'method', 'status', 'ref', 'created_at'],
                    Schema::hasColumn('staff_events', 'source') ? ['source'] : []
                ));
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
                'source'     => $r->source ?? null,
                'method'     => $r->method,
                'status'     => $r->status === null ? null : (int) $r->status,
                // order id ng checker events: pang-bilang lang, hindi inilalabas
                'ref'        => in_array($r->kind, self::CHECKER_KINDS, true) ? null : $r->ref,
                'created_at' => $this->iso($r->created_at),
                'is_bot'     => isset($bots[(int) $r->user_id]),
            ])->values(),
        ]);
    }

    /**
     * user_id => checker counts sa araw na iyon. Ang order ay source + ref (para hindi magsalubong ang mga id
     * kapag may ibang source na). status_last_editor: ilang order ang ang huling checker.status event ngayong araw ay sa user
     * (parehong rule ng Checker 1 summary page); edited_orders: ilang order ang may kahit anong checker event niya.
     */
    private function checkerCounts(Carbon $day, Carbon $next): array
    {
        if (!Schema::hasTable('staff_events') || !Schema::hasColumn('staff_events', 'source')) {
            return [];
        }
        $rows = DB::table('staff_events')
            ->whereIn('kind', self::CHECKER_KINDS)
            ->where('created_at', '>=', $day->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $next->format('Y-m-d H:i:s'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['user_id', 'kind', 'source', 'ref']);

        $out = [];
        $lastEditor = [];
        $edited = [];
        foreach ($rows as $r) {
            $uid = (int) $r->user_id;
            $order = $r->source . '|' . $r->ref;
            $out[$uid] ??= self::CHECKER_ZERO;
            $edited[$uid][$order] = true;
            if ($r->kind === 'checker.status') {
                $out[$uid]['status_changes']++;
                $lastEditor[$order] = $uid;
            } elseif ($r->kind === 'checker.validate') {
                $out[$uid]['validates']++;
            }
        }
        foreach ($edited as $uid => $orders) {
            $out[$uid]['edited_orders'] = count($orders);
        }
        foreach ($lastEditor as $uid) {
            $out[$uid]['status_last_editor']++;
        }

        return $out;
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
     * user_id => [titles]: due na checklist task ng user sa araw na iyon na wala pang submission
     * (ang rules ay nasa ChecklistDueToday).
     */
    private function checklistDueNotSubmitted(array $dueRows): array
    {
        $out = [];
        foreach ($dueRows as $r) {
            if (!$r['submitted']) {
                $out[$r['user_id']][] = $r['title'];
            }
        }

        return $out;
    }

    /**
     * user_id => [task na lampas na sa deadline sa araw na iyon]: id, title, deadline, kung naisumite na at
     * kailan, ang pinakahuling verdict at ilang approval check. Walang notes, file o AI text. Para sa
     * nakaraang araw, lampas na ang lahat ng deadline bago maghatinggabi; sa darating na araw, wala pa.
     */
    private function checklistLate(array $dueRows, Carbon $day): array
    {
        $now = Carbon::now(self::TZ);
        $late = array_filter($dueRows, fn ($r) => ChecklistDueToday::isLate($r['deadline'], $day, $now));
        usort($late, fn ($a, $b) => [$a['deadline'], $a['task_id']] <=> [$b['deadline'], $b['task_id']]);

        $out = [];
        foreach ($late as $r) {
            $out[$r['user_id']][] = [
                'task_id'         => $r['task_id'],
                'title'           => $r['title'],
                'deadline'        => ChecklistDueToday::hhmm($r['deadline']),
                'submitted'       => $r['submitted'],
                'submitted_at'    => $r['submission']['submitted_at'] ?? null,
                'verdict'         => $r['submission']['verdict'] ?? null,
                'approval_checks' => $r['submission']['approval_checks'] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * user_id => bilang ng checklist submissions na naka-date sa araw na iyon na ang pinakahuling approval
     * verdict ay approved (isang re-check na nag-approve ay isang beses lang binibilang). Ang credit ay sa
     * user_id ng submission: sa group task iisa lang ang submission kada araw (ang unang nagsumite), sa
     * individual task kanya-kanya. Bilang lang: walang title, notes o verdict text.
     */
    private function checklistApproved(array $ids, Carbon $day): array
    {
        if (!$ids || !Schema::hasTable('checklist_submissions') || !Schema::hasTable('checklist_analysis_logs')
            || !Schema::hasColumn('checklist_analysis_logs', 'verdict')) {
            return [];
        }
        $latestVerdict = DB::table('checklist_analysis_logs as l')
            ->select('l.verdict')
            ->whereColumn('l.submission_id', 's.id')
            ->where('l.log_type', 'approval')
            ->orderByDesc('l.created_at')
            ->orderByDesc('l.id')
            ->limit(1);

        return DB::table('checklist_submissions as s')
            ->select('s.user_id')
            ->selectSub($latestVerdict, 'verdict')
            ->whereIn('s.user_id', $ids)
            // < kinabukasan: tama rin kahit may oras ang naka-store na date
            ->where('s.date', '>=', $day->toDateString())
            ->where('s.date', '<', $day->copy()->addDay()->toDateString())
            ->get()
            ->where('verdict', 'approved')
            ->countBy(fn ($r) => (int) $r->user_id)
            ->all();
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value, self::TZ)->toIso8601String() : null;
    }
}
