<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Iisang sagot sa "anong checklist task ang due sa tao sa araw na ito, naisumite na ba, at late na ba",
 * para sa staff feed at sa badge ng Checklist link.
 *
 * Due: aktibong task na naka-assign sa user, umiiral na at hindi pa binura noong araw na iyon,
 * frequency / frequency_day, at 'once' hanggang sa araw ng unang submission (kasama). Submitted:
 * group task = may submission ang kahit sino sa araw na iyon; individual = ang sariling submission.
 *
 * Deadline: ang deadline_time kung may laman, kung wala ang scheduled_time + 15 minuto, kung wala
 * pareho ay walang deadline. Minuto mula hatinggabi ng araw ng task; 1440 pataas ay kinabukasan
 * na (hal. 11:50 PM + 15 = 12:05 AM), kaya hindi iyon nagiging late sa araw ng task.
 */
class ChecklistDueToday
{
    public const TZ = 'Asia/Manila';

    private const GRACE_MINUTES = 15;

    /** "H:MM" o "H:MM:SS" → minuto mula hatinggabi; null kapag blangko o hindi oras. */
    public static function minutes(?string $time): ?int
    {
        if (!is_string($time) || !preg_match('/^\s*(\d{1,2}):(\d{2})(?::\d{2})?\s*$/', $time, $m)) {
            return null;
        }
        [$h, $i] = [(int) $m[1], (int) $m[2]];

        return $h > 23 || $i > 59 ? null : $h * 60 + $i;
    }

    public static function deadlineMinutes(?string $scheduled, ?string $deadline): ?int
    {
        $set = self::minutes($deadline);
        if ($set !== null) {
            return $set;
        }
        $start = self::minutes($scheduled);

        return $start === null ? null : $start + self::GRACE_MINUTES;
    }

    /** "HH:MM" ng deadline (ang kinabukasan ay oras pa rin ng orasan, hal. "00:05"). */
    public static function hhmm(int $minutes): string
    {
        $minutes %= 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Ilang segundo na ang lumipas sa araw ng task, ayon sa $now: lampas sa buong araw kapag nakaraan
     * na ang araw, negatibo kapag darating pa.
     */
    public static function secondsInto(Carbon $day, Carbon $now): int
    {
        $start = $day->copy()->setTimezone(self::TZ)->startOfDay();

        return (int) $start->diffInSeconds($now->copy()->setTimezone(self::TZ), false);
    }

    /** Late = lampas na ang deadline sa mismong araw ng task (10:15 ay late mula 10:15:01). */
    public static function isLate(?int $deadline, Carbon $day, Carbon $now): bool
    {
        return $deadline !== null && $deadline < 1440 && self::secondsInto($day, $now) > $deadline * 60;
    }

    /**
     * Bawat (user, task) na due sa $day, sa ayos ng sort_order at id ng task:
     * user_id, task_id, title, deadline (minuto o null), submitted, at ang submission na binibilang
     * (group: ang unang nagsumite sa araw na iyon; individual: ang sa user) na may submitted_at
     * (oras na naka-store), verdict (pinakahuling approval, pati override), approval_checks at ang huling override (overridden_by, overridden_at).
     *
     * @return list<array{user_id:int, task_id:int, title:string, deadline:?int, submitted:bool, submission:?array}>
     */
    public static function rows(array $userIds, Carbon $day): array
    {
        $schema = self::schema();
        if (!$userIds || !$schema['tasks']) {
            return [];
        }
        $dayStr = $day->toDateString();
        $nextStr = $day->copy()->addDay()->toDateString();
        $timeCols = $schema['time_cols'];

        $assigned = DB::table('checklist_tasks as t')
            ->join('checklist_task_users as tu', 'tu.checklist_task_id', '=', 't.id')
            ->whereIn('tu.user_id', $userIds)
            ->where('t.is_active', true)
            ->where('t.created_at', '<', $nextStr)
            ->where(function ($q) use ($nextStr) {
                $q->whereNull('t.deleted_at')->orWhere('t.deleted_at', '>=', $nextStr);
            })
            ->orderBy('t.sort_order')
            ->orderBy('t.id')
            ->get(array_merge(
                ['t.id', 't.title', 't.frequency', 't.frequency_day', 't.submission_type', 'tu.user_id'],
                array_map(fn ($c) => "t.$c", $timeCols)
            ));
        if ($assigned->isEmpty()) {
            return [];
        }
        $taskIds = $assigned->pluck('id')->unique()->values()->all();

        // < kinabukasan: tama rin kahit may oras ang naka-store na date
        $subsQuery = DB::table('checklist_submissions as s')
            ->whereIn('s.checklist_task_id', $taskIds)
            ->where('s.date', '>=', $dayStr)
            ->where('s.date', '<', $nextStr)
            ->orderBy('s.created_at')
            ->orderBy('s.id')
            ->select('s.id', 's.checklist_task_id', 's.user_id', 's.created_at');
        self::withApproval($subsQuery);
        $firstByTask = [];
        $byUser = [];
        foreach ($subsQuery->get() as $s) {
            $sub = [
                'submitted_at'    => $s->created_at ? Carbon::parse($s->created_at, self::TZ)->format('H:i') : null,
                'verdict'         => in_array($s->verdict ?? null, ['approved', 'not_approved'], true) ? $s->verdict : null,
                'approval_checks' => (int) ($s->approval_checks ?? 0),
                'overridden_by'   => ($s->overridden_at ?? null) ? ($s->overridden_by ?? null) : null,
                'overridden_at'   => ($s->overridden_at ?? null) ? Carbon::parse($s->overridden_at, self::TZ)->format('H:i') : null,
            ];
            $firstByTask[(int) $s->checklist_task_id] ??= $sub;
            $byUser[(int) $s->checklist_task_id][(int) $s->user_id] ??= $sub;
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
            $submission = ($t->submission_type ?? 'group') === 'individual'
                ? ($byUser[$tid][$uid] ?? null)
                : ($firstByTask[$tid] ?? null);
            $out[] = [
                'user_id'    => $uid,
                'task_id'    => $tid,
                'title'      => $t->title,
                'scheduled'  => self::minutes($t->scheduled_time ?? null),
                'deadline'   => self::deadlineMinutes($t->scheduled_time ?? null, $t->deadline_time ?? null),
                'submitted'  => $submission !== null,
                'submission' => $submission,
            ];
        }

        return $out;
    }

    /**
     * Mga table at column na kailangan, isang beses kada request (nasa app container, kaya bago ulit
     * sa susunod na request): tasks = may checklist_tasks, checklist_task_users at checklist_submissions;
     * time_cols = ang mga oras na column na nandoon na (hal. bago ang migration ng deadline);
     * approval = may checklist_analysis_logs na may verdict; override = may is_override na rin.
     *
     * @return array{tasks:bool, time_cols:list<string>, approval:bool, override:bool}
     */
    public static function schema(): array
    {
        $key = self::class . '.schema';
        if (!app()->bound($key)) {
            $tasks = Schema::hasTable('checklist_tasks') && Schema::hasTable('checklist_task_users')
                && Schema::hasTable('checklist_submissions');
            app()->instance($key, [
                'tasks'     => $tasks,
                'time_cols' => $tasks ? array_values(array_intersect(['scheduled_time', 'deadline_time'], Schema::getColumnListing('checklist_tasks'))) : [],
                'approval'  => $approval = $tasks && Schema::hasTable('checklist_analysis_logs') && Schema::hasColumn('checklist_analysis_logs', 'verdict'),
                'override'  => $approval && Schema::hasColumn('checklist_analysis_logs', 'is_override'),
            ]);
        }

        return app($key);
    }

    /**
     * Dagdag sa query ng submissions (alias s): verdict = pinakahuling approval verdict,
     * approval_checks = ilang approval check na (hindi kasama ang override), overridden_at at overridden_by ng huling override. Wala kapag wala pang approval logs.
     */
    public static function withApproval($query): void
    {
        if (!self::schema()['approval']) {
            return;
        }
        $query->selectSub(DB::table('checklist_analysis_logs as l')
            ->select('l.verdict')
            ->whereColumn('l.submission_id', 's.id')
            ->where('l.log_type', 'approval')
            ->orderByDesc('l.created_at')
            ->orderByDesc('l.id')
            ->limit(1), 'verdict');
        $checks = DB::table('checklist_analysis_logs as l')
            ->selectRaw('COUNT(*)')
            ->whereColumn('l.submission_id', 's.id')
            ->where('l.log_type', 'approval');
        if (!self::schema()['override']) {
            $query->selectSub($checks, 'approval_checks');

            return;
        }
        // Ang override ay hindi check; ang huling override (sino, kailan) ay hiwalay
        $query->selectSub($checks->where('l.is_override', false), 'approval_checks');
        $lastOverride = fn (string $col) => DB::table('checklist_analysis_logs as o')
            ->whereColumn('o.submission_id', 's.id')
            ->where('o.log_type', 'approval')
            ->where('o.is_override', true)
            ->orderByDesc('o.created_at')
            ->orderByDesc('o.id')
            ->limit(1)
            ->select($col);
        $query->selectSub($lastOverride('o.created_at'), 'overridden_at');
        $query->selectSub(
            $lastOverride('u.name')->leftJoin('users as u', 'u.id', '=', 'o.user_id'),
            'overridden_by'
        );
    }
}
