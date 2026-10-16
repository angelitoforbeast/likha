<?php

namespace App\Support;

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
}
