<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Isang row sa item_edit_logs kada field na nagbago (luma → bago) — para makita kung sino ang nag-edit
 * ng ano sa /item at kailan. Walang row kapag pareho. Best-effort: hindi kailanman ibinabato sa request,
 * dahil tapos na ang edit mismo kapag tinawag ito.
 */
class ItemEditLog
{
    /** Hanggang dito lang ang text na itinatala (maikli; walang file data). */
    private const MAX = 500;

    /**
     * @param array<string, array{0:mixed, 1:mixed}> $changes field => [luma, bago] (normalised na ng tumawag)
     */
    public static function write(string $action, string $itemName, array $changes, ?string $itemKey = null, ?string $pageName = null): void
    {
        try {
            $rows = [];
            $role = self::role();
            $now  = now();
            foreach ($changes as $field => [$old, $new]) {
                $old = self::text($old);
                $new = self::text($new);
                if ($old === $new) continue;
                $rows[] = [
                    'user_id'    => Auth::id(),
                    'role'       => $role,
                    'action'     => $action,
                    'item_key'   => $itemKey ?? ItemBaseKey::key($itemName),
                    'item_name'  => mb_substr($itemName, 0, 255),
                    'page_name'  => $pageName,
                    'field'      => mb_substr((string) $field, 0, 190),
                    'old_value'  => $old,
                    'new_value'  => $new,
                    'created_at' => $now,
                ];
            }
            if ($rows && Schema::hasTable('item_edit_logs')) {
                DB::table('item_edit_logs')->insert($rows);
            }
        } catch (\Throwable $e) {
            Log::warning('item_edit_logs write failed: ' . $e->getMessage());
        }
    }

    private static function text(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, self::MAX);
    }

    private static function role(): ?string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? null;

        return $raw === null ? null : mb_substr(preg_replace('/\s+/u', ' ', trim((string) $raw)), 0, 60);
    }
}
