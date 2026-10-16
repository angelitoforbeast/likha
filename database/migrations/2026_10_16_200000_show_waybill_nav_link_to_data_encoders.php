<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ipakita ang "Waybill" (/jnt_upload) nav link sa Data Encoder at Data Encoder - OIC
 * (view only ang page para sa kanila). Idempotent — ligtas i-run kahit umulit;
 * ang dalawang row lang ng waybill para sa mga role na ito ang ginagalaw.
 */
return new class extends Migration
{
    private const ROLES = ['Data Encoder', 'Data Encoder - OIC'];

    public function up(): void
    {
        if (!Schema::hasTable('nav_links') || !Schema::hasTable('nav_link_role_visibility')) return;

        $id = DB::table('nav_links')->where('key', 'waybill')->value('id');
        if (!$id) return;

        $now = now();
        foreach (self::ROLES as $role) {
            $row = DB::table('nav_link_role_visibility')->where('nav_link_id', $id)->where('role', $role);
            if ($row->exists()) {
                // Ang seed ay naglagay ng is_visible=false para sa mga role na ito.
                if (!$row->value('is_visible')) $row->update(['is_visible' => true, 'updated_at' => $now]);
            } else {
                DB::table('nav_link_role_visibility')->insert([
                    'nav_link_id' => $id, 'role' => $role, 'is_visible' => true,
                    'created_at'  => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('nav_links') || !Schema::hasTable('nav_link_role_visibility')) return;

        $id = DB::table('nav_links')->where('key', 'waybill')->value('id');
        if ($id) {
            DB::table('nav_link_role_visibility')->where('nav_link_id', $id)
                ->whereIn('role', self::ROLES)->update(['is_visible' => false, 'updated_at' => now()]);
        }
    }
};
