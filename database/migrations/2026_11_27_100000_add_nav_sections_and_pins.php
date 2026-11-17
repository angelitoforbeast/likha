<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 295: the sidebar groups the nav in sections. nav_links.section holds each link's section (nav settings can change
 * it) and users.nav_pins the ids of the links a person pinned (at most six). Every existing link gets its section by
 * route_url, from the spec's inventory (keys differ in production, urls do not); a url the list does not know goes to
 * More, so nothing disappears. Only rows without a section are filled, so running it again keeps what the CEO chose.
 * Visibility rows are not touched. The list is frozen here; App\Support\NavSections keeps the live copy.
 */
return new class extends Migration
{
    private const SECTIONS = [
        'Home' => ['/checklist'],
        'Orders' => ['/likha_order_import', '/macro/gsheet/import', '/encoder/summary', '/encoder/checker_1', '/encoder/pending-rate',
            '/encoder/timetable', '/data_encoder/mes-segregator', '/jnt/address'],
        'Shipping' => ['/jnt_upload', '/jnt_upload_v2', '/encoded_vs_upload', '/jnt/stickers', '/jnt/checker', '/jnt/hold', '/jnt_rts',
            '/jnt/supply', '/pancake/retrieve2'],
        'Marketing' => ['/ads_manager/report', '/ads_manager/cpp', '/ads_manager/daily-output', '/ads_manager/payment/upload',
            '/ads_manager/pancake-subscription-checker', '/conversation/tracker', '/owner/private', '/playbook', '/prompt-generator'],
        'Chat' => ['/chatbot', '/chatblast', '/page-settings', '/chatbot/botcake-accounts', '/pancake/page-id-mapping', '/chat'],
        'AI' => ['/checklist/ai-followups', '/astra', '/boardroom'],
        'Money' => ['/finance', '/finance/supply'],
        'Settings' => ['/assign-roles', '/owner/users', '/owner/nav-settings', '/owner/column-settings', '/allowed-ips', '/jnt/config',
            '/jnt/fee-settings', '/jnt/supply/excluded-pages', '/queue-manager', '/system-monitor/storage', '/owner/access-report'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('nav_links') && !Schema::hasColumn('nav_links', 'section')) {
            Schema::table('nav_links', function (Blueprint $t) {
                $t->string('section', 30)->nullable()->after('icon');
            });
        }
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'nav_pins')) {
            Schema::table('users', function (Blueprint $t) {
                $t->json('nav_pins')->nullable();
            });
        }
        if (!Schema::hasTable('nav_links')) return;

        $byUrl = [];
        foreach (self::SECTIONS as $section => $urls) {
            foreach ($urls as $url) $byUrl[$url] = $section;
        }
        foreach (DB::table('nav_links')->whereNull('section')->get(['id', 'route_url']) as $link) {
            $url = '/' . trim((string) $link->route_url, '/');
            DB::table('nav_links')->where('id', $link->id)->update(['section' => $byUrl[$url] ?? 'More']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nav_links', 'section')) {
            Schema::table('nav_links', fn (Blueprint $t) => $t->dropColumn('section'));
        }
        if (Schema::hasColumn('users', 'nav_pins')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('nav_pins'));
        }
    }
};
