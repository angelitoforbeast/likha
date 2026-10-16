<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Isang row sa staff_events: sino, anong role noong oras na iyon, anong ginawa (route name o login/logout),
 * method, HTTP status at id. Hindi kailanman nagtatapon ng error sa request: warning lang sa log.
 */
class StaffEvents
{
    public static function record(int $userId, string $kind, ?string $method = null, ?int $status = null, ?string $ref = null): void
    {
        try {
            if (!Schema::hasTable('staff_events')) {
                return;
            }
            $role = DB::table('employee_profiles')->where('user_id', $userId)->value('role');

            DB::table('staff_events')->insert([
                'user_id'    => $userId,
                'role'       => $role,
                'kind'       => mb_substr($kind, 0, 191),
                'method'     => $method,
                'status'     => $status,
                'ref'        => $ref !== null ? mb_substr($ref, 0, 64) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('staff_events: hindi na-record ang event', ['kind' => $kind, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Isang checker.* event kada order na talagang nagbago, para sa naka-login na staff lang:
     * ang imports, Astra, night run at queue jobs ay walang Auth user kaya walang naitatala. Ang id ay nasa
     * ref para sa pagbilang lang; walang customer data na pumapasok.
     */
    public static function recordChecker(string $kind, string $source, array $ids): void
    {
        try {
            $userId = Auth::id();
            if ($userId === null || !$ids || !Schema::hasTable('staff_events')) {
                return;
            }
            $role = DB::table('employee_profiles')->where('user_id', $userId)->value('role');
            $now = now();

            $rows = array_map(fn ($id) => [
                'user_id'    => (int) $userId,
                'role'       => $role,
                'kind'       => $kind,
                'source'     => $source,
                'method'     => 'POST',
                'status'     => 200,
                'ref'        => (string) $id,
                'created_at' => $now,
            ], array_values(array_unique($ids)));

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('staff_events')->insert($chunk);
            }
        } catch (\Throwable $e) {
            Log::warning('staff_events: hindi na-record ang checker event', ['kind' => $kind, 'error' => $e->getMessage()]);
        }
    }
}
