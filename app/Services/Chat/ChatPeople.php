<?php

namespace App\Services\Chat;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Likha Chat (spec 296): sino ang puwedeng kausapin at paano ipinapakita ang tao.
 * Aktibo = gaya ng staff feed (StaffFeedController): may employee profile at ang status ay null o hindi
 * inactive/resigned/terminated. Ang user na walang profile ay hindi lumalabas sa search pero puwedeng magsimula ng chat.
 */
class ChatPeople
{
    private const INACTIVE_STATUSES = ['inactive', 'resigned', 'terminated'];

    /** Bilang ng kulay ng avatar (ang view ang may hawak ng mga kulay). */
    public const TONES = 6;

    /** users + employee_profiles ng mga aktibong empleyado. */
    public static function activeQuery(): Builder
    {
        return DB::table('users as u')
            ->join('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->where(function ($q) {
                $q->whereNull('ep.status')
                    ->orWhereRaw('LOWER(TRIM(ep.status)) NOT IN (?, ?, ?)', self::INACTIVE_STATUSES);
            });
    }

    /** @param  int[]  $ids  @return int[] ang mga aktibo sa kanila */
    public static function activeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $ids ? self::activeQuery()->whereIn('u.id', $ids)->distinct()->pluck('u.id')->map(fn ($id) => (int) $id)->all() : [];
    }

    /** @return array<int, array{id:int,name:string,role:?string,initials:string,tone:int}> */
    public static function search(string $q, int $exceptUserId, int $limit = 30): array
    {
        $query = self::activeQuery()->where('u.id', '!=', $exceptUserId);
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes(mb_strtolower($q), '%_!') . '%';
            $query->where(function ($w) use ($like) {
                $w->whereRaw("LOWER(COALESCE(NULLIF(TRIM(ep.name), ''), u.name)) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(u.name) LIKE ? ESCAPE '!'", [$like]);
            });
        }

        return $query->orderByRaw("COALESCE(NULLIF(TRIM(ep.name), ''), u.name)")
            ->limit($limit)
            ->get(['u.id', 'u.name as user_name', 'ep.name', 'ep.role'])
            ->unique('id')
            ->map(fn ($r) => self::card((int) $r->id, self::pickName($r->name, $r->user_name), $r->role))
            ->values()
            ->all();
    }

    /** Pangalan (at role) ng mga user id, kahit hindi na aktibo o walang profile. @return Collection<int, array> */
    public static function lookup(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return collect();
        }

        return DB::table('users as u')
            ->leftJoin('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->whereIn('u.id', $ids)
            ->get(['u.id', 'u.name as user_name', 'ep.name', 'ep.role'])
            ->unique('id')
            ->mapWithKeys(fn ($r) => [(int) $r->id => self::card((int) $r->id, self::pickName($r->name, $r->user_name), $r->role)]);
    }

    public static function card(int $id, string $name, ?string $role = null): array
    {
        $role = $role !== null ? trim($role) : null;

        return ['id' => $id, 'name' => $name, 'role' => $role ?: null, 'initials' => self::initials($name), 'tone' => $id % self::TONES];
    }

    public static function initials(string $name): string
    {
        $words = preg_split('/[\s._-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last);
    }

    private static function pickName(?string $profileName, ?string $userName): string
    {
        $name = trim((string) $profileName);

        return $name !== '' ? $name : (trim((string) $userName) ?: 'Unknown');
    }
}
