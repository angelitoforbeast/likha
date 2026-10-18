<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Badge ng "Checklist" link sa nav, para sa naka-login: ang mga task niya ngayong araw na wala pang
 * submission at late na (late), o ang deadline ay sa susunod na 30 minuto (soon), o walang deadline
 * pero bukas pa (open); dagdag ang mga submission niya ngayong araw na not_approved ang huling verdict.
 * Sa CEO (lahat ng staff) at sa OIC (staff ng department niya): ilan ang late at wala pang submission
 * sa team, hindi kasama ang sarili.
 *
 * Nasa bawat page ito, kaya naka-cache nang 60 segundo kada user, at kapag pumalya ay walang badge
 * (class name lang ang nilo-log) sa halip na masira ang page.
 */
class ChecklistBadge
{
    private const SOON_MINUTES = 30;

    private const CACHE_SECONDS = 60;

    /** Parehong rule ng staff feed: ang mga status na ito lang ang hindi na aktibo. */
    private const INACTIVE_STATUSES = ['inactive', 'resigned', 'terminated'];

    /** @return array{count:int, tone:string, title:string, team:int, team_title:string}|null */
    public static function forUser(?User $user): ?array
    {
        if (!$user) {
            return null;
        }
        try {
            return Cache::remember('checklist_badge:' . $user->id, self::CACHE_SECONDS, fn () => self::compute($user));
        } catch (\Throwable $e) {
            Log::warning('Checklist badge failed: ' . get_class($e));

            return null;
        }
    }

    private static function compute(User $user): array
    {
        $now = Carbon::now(ChecklistDueToday::TZ);
        $day = $now->copy()->startOfDay();
        $into = ChecklistDueToday::secondsInto($day, $now);

        $parts = ['late' => 0, 'soon' => 0, 'open' => 0, 'not_approved' => 0];
        foreach (ChecklistDueToday::rows([(int) $user->id], $day) as $r) {
            if ($r['submitted']) {
                continue;
            }
            $key = match (true) {
                $r['deadline'] === null                                         => 'open',
                ChecklistDueToday::isLate($r['deadline'], $day, $now)           => 'late',
                $r['deadline'] * 60 - $into <= self::SOON_MINUTES * 60          => 'soon',
                default                                                         => null,
            };
            if ($key) {
                $parts[$key]++;
            }
        }
        $parts['not_approved'] = self::notApprovedToday((int) $user->id, $day);

        $team = 0;
        $teamIds = self::teamIds($user);
        if ($teamIds) {
            foreach (ChecklistDueToday::rows($teamIds, $day) as $r) {
                if (!$r['submitted'] && ChecklistDueToday::isLate($r['deadline'], $day, $now)) {
                    $team++;
                }
            }
        }

        $labels = ['late' => 'late', 'soon' => 'due soon', 'open' => 'open', 'not_approved' => 'not approved'];
        $title = implode(', ', array_map(
            fn ($k) => $parts[$k] . ' ' . $labels[$k],
            array_keys(array_filter($parts))
        ));

        return [
            'count'      => array_sum($parts),
            'tone'       => $parts['late'] > 0 ? 'red' : 'neutral',
            'title'      => $title,
            'team'       => $team,
            'team_title' => "$team late sa team",
        ];
    }

    /** Ilang submission ng user na naka-date ngayong araw ang not_approved ang pinakahuling verdict. */
    private static function notApprovedToday(int $userId, Carbon $day): int
    {
        if (!ChecklistDueToday::schema()['approval']) {
            return 0;
        }
        $query = DB::table('checklist_submissions as s')
            ->select('s.id')
            ->where('s.user_id', $userId)
            // < kinabukasan: tama rin kahit may oras ang naka-store na date
            ->where('s.date', '>=', $day->toDateString())
            ->where('s.date', '<', $day->copy()->addDay()->toDateString());
        ChecklistDueToday::withApproval($query);

        return $query->get()->where('verdict', 'not_approved')->count();
    }

    /**
     * Team ng CEO = lahat ng aktibong staff; ng OIC = aktibong staff na pareho ang department key.
     * Walang team ang ibang role. Hindi kasama ang mismong user.
     */
    private static function teamIds(User $user): array
    {
        $role = strtoupper(trim((string) ($user->employeeProfile?->role ?? '')));
        $isCeo = $role === 'CEO';
        if (!$isCeo && !str_contains($role, 'OIC')) {
            return [];
        }
        $mine = self::departmentKey($user->employeeProfile?->department, $user->employeeProfile?->role);
        if (!$isCeo && $mine === '') {
            return [];
        }

        return DB::table('employee_profiles as ep')
            ->where('ep.user_id', '!=', $user->id)
            ->where(function ($q) {
                $q->whereNull('ep.status')
                    ->orWhereRaw('LOWER(TRIM(ep.status)) NOT IN (?, ?, ?)', self::INACTIVE_STATUSES);
            })
            ->get(['ep.user_id', 'ep.role', 'ep.department'])
            ->filter(fn ($p) => $isCeo || self::departmentKey($p->department, $p->role) === $mine)
            ->map(fn ($p) => (int) $p->user_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Department ng tao, lowercase at trimmed: ang nasa profile; kapag blangko, ang role na walang
     * "- OIC" (hal. "Marketing - OIC" at "Marketing" → "marketing"), dahil madalas blangko ang
     * department sa profile. Blangko kapag wala sa dalawa: hindi siya kasama sa team ng kahit sinong OIC.
     */
    public static function departmentKey(?string $department, ?string $role): string
    {
        $dept = trim((string) $department);
        if ($dept === '') {
            $dept = trim((string) preg_replace('/\s*-\s*OIC\b.*$/i', '', trim((string) $role)));
        }

        return mb_strtolower($dept);
    }
}
