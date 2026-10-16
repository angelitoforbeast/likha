<?php

namespace App\Listeners;

use App\Support\StaffEvents;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * last_login_at, at ang login / logout na staff_events row. Tahasang nakarehistro sa
 * AppServiceProvider::register(); hindi `handle*` ang pangalan para hindi rin makuha ng event discovery.
 */
class StaffLoginActivity
{
    public function recordLogin(Login $event): void
    {
        $userId = (int) $event->user->getAuthIdentifier();
        try {
            if (Schema::hasColumn('users', 'last_login_at')) {
                DB::table('users')->where('id', $userId)->update(['last_login_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('StaffLoginActivity: hindi na-update ang last_login_at', ['error' => $e->getMessage()]);
        }
        // Dalawang beses nagpapaputok ng Login event ang login ng CEO sa iisang request (attempt, tapos
        // Auth::login na may remember cookie): isang `login` row lang kada user kada request.
        $attributes = request()->attributes;
        $key = "staff_login_recorded:{$userId}";
        if ($attributes->has($key)) {
            return;
        }
        $attributes->set($key, true);

        StaffEvents::record($userId, 'login', request()->getMethod());
    }

    public function recordLogout(Logout $event): void
    {
        if ($event->user) {
            StaffEvents::record((int) $event->user->getAuthIdentifier(), 'logout', request()->getMethod());
        }
    }
}
