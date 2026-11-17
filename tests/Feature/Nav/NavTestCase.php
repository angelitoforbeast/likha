<?php

namespace Tests\Feature\Nav;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base of the spec 295 tests (sqlite in memory). The users table and every nav-link migration are the real ones; the
 * rows production has that no migration made (Checklist, Chat, Astra, ...) are added by hand, as the CEO added them.
 * `/nav-probe` and `/orders/probe` render `<x-layout>` so the sidebar can be read without a page's own tables.
 */
abstract class NavTestCase extends TestCase
{
    protected const ROLES = ['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder - OIC', 'Data Encoder'];

    protected const SECTIONS_MIGRATION = 'database/migrations/2026_11_27_100000_add_nav_sections_and_pins.php';

    /** The spec's inventory: route_url => section. */
    public const INVENTORY = [
        '/checklist' => 'Home',
        '/likha_order_import' => 'Orders', '/macro/gsheet/import' => 'Orders', '/encoder/summary' => 'Orders',
        '/encoder/checker_1' => 'Orders', '/encoder/pending-rate' => 'Orders', '/encoder/timetable' => 'Orders',
        '/data_encoder/mes-segregator' => 'Orders', '/jnt/address' => 'Orders',
        '/jnt_upload' => 'Shipping', '/jnt_upload_v2' => 'Shipping', '/encoded_vs_upload' => 'Shipping', '/jnt/stickers' => 'Shipping',
        '/jnt/checker' => 'Shipping', '/jnt/hold' => 'Shipping', '/jnt_rts' => 'Shipping', '/jnt/supply' => 'Shipping',
        '/pancake/retrieve2' => 'Shipping',
        '/ads_manager/report' => 'Marketing', '/ads_manager/cpp' => 'Marketing', '/ads_manager/daily-output' => 'Marketing',
        '/ads_manager/payment/upload' => 'Marketing', '/ads_manager/pancake-subscription-checker' => 'Marketing',
        '/conversation/tracker' => 'Marketing', '/owner/private' => 'Marketing', '/playbook' => 'Marketing',
        '/prompt-generator' => 'Marketing',
        '/chatbot' => 'Chat', '/chatblast' => 'Chat', '/page-settings' => 'Chat', '/chatbot/botcake-accounts' => 'Chat',
        '/pancake/page-id-mapping' => 'Chat', '/chat' => 'Chat',
        '/checklist/ai-followups' => 'AI', '/astra' => 'AI', '/boardroom' => 'AI',
        '/finance' => 'Money', '/finance/supply' => 'Money',
        '/assign-roles' => 'Settings', '/owner/users' => 'Settings', '/owner/nav-settings' => 'Settings',
        '/owner/column-settings' => 'Settings', '/allowed-ips' => 'Settings', '/jnt/config' => 'Settings',
        '/jnt/fee-settings' => 'Settings', '/jnt/supply/excluded-pages' => 'Settings', '/queue-manager' => 'Settings',
        '/system-monitor/storage' => 'Settings', '/owner/access-report' => 'Settings',
    ];

    /** Production rows no migration made: [key, label, url, roles that see it]. */
    protected const HAND_ROWS = [
        ['checklist', 'Checklist', '/checklist', self::ROLES],
        ['chat', 'Chat', '/chat', self::ROLES],
        ['ai_followups', 'AI Follow-ups', '/checklist/ai-followups', ['CEO']],
        ['astra', 'Astra', '/astra', ['CEO', 'Marketing - OIC', 'Marketing']],
        ['boardroom', 'Boardroom', '/boardroom', ['CEO']],
        ['page_settings', 'Page Settings', '/page-settings', ['CEO', 'Marketing - OIC']],
        ['access_report', 'Access Report', '/owner/access-report', ['CEO']],
        ['profit_calc', 'Profit Calculator', '/profit-calculator', ['CEO']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(AllowedIpMiddleware::class);

        Schema::create('employee_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->string('employee_code')->nullable();
            $t->string('role')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) { // counted by the layout's view composer
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });

        $paths = ['database/migrations/0001_01_01_000000_create_users_table.php'];
        foreach (glob(base_path('database/migrations/*nav*')) as $file) {
            $rel = 'database/migrations/' . basename($file);
            if ($rel !== self::SECTIONS_MIGRATION) $paths[] = $rel;
        }
        sort($paths);
        Artisan::call('migrate', ['--path' => $paths, '--force' => true]);
        $this->addHandRows();

        if ($this->runsSectionsMigration()) $this->migrateSections();

        Route::middleware(['web', 'auth'])->get('/nav-probe', fn () => Blade::render('<x-layout><x-slot name="heading">Probe</x-slot>
<p>probe body</p></x-layout>'));
        Route::middleware(['web', 'auth'])->get('/ads_manager/report-probe', fn () => Blade::render('<x-layout><x-slot name="heading">Ads</x-slot>
<p>ads</p></x-layout>'));
    }

    protected function runsSectionsMigration(): bool
    {
        return true;
    }

    protected function migrateSections(): void
    {
        Artisan::call('migrate', ['--path' => [self::SECTIONS_MIGRATION], '--force' => true]);
    }

    protected function addHandRows(): void
    {
        $now = now();
        $sort = (int) DB::table('nav_links')->max('sort_order');
        foreach (self::HAND_ROWS as [$key, $label, $url, $roles]) {
            $id = DB::table('nav_links')->insertGetId([
                'key' => $key, 'label' => $label, 'route_url' => $url, 'icon' => 'fa-solid fa-link',
                'active_pattern' => ltrim($url, '/') . '*', 'sort_order' => ++$sort, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (self::ROLES as $role) {
                DB::table('nav_link_role_visibility')->insert([
                    'nav_link_id' => $id, 'role' => $role, 'is_visible' => in_array($role, $roles, true),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    protected function user(?string $role, string $name = 'Test User'): User
    {
        $user = User::create(['name' => $name, 'email' => uniqid('u') . '@example.test', 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }

    protected function linkId(string $url): int
    {
        return (int) DB::table('nav_links')->where('route_url', $url)->value('id');
    }

    /** Links each role sees: role => sorted list of urls. */
    protected function visibleUrls(): array
    {
        $out = [];
        foreach (self::ROLES as $role) {
            $out[$role] = DB::table('nav_links as l')->join('nav_link_role_visibility as v', 'v.nav_link_id', '=', 'l.id')
                ->where('v.role', $role)->where('v.is_visible', true)->where('l.is_active', true)
                ->orderBy('l.route_url')->pluck('l.route_url')->all();
        }

        return $out;
    }

    /** Sections rendered in the sidebar, in order. */
    protected function sidebarSections(string $html): array
    {
        preg_match_all('/data-nav-section="([^"]+)"/', $html, $m);

        return $m[1];
    }

    /** Urls of the links rendered under one section of the sidebar. */
    protected function sidebarLinks(string $html, ?string $section = null): array
    {
        if ($section !== null) {
            if (!preg_match('/data-nav-section="' . preg_quote($section, '/') . '"(.*?)<\/section>/s', $html, $m)) return [];
            $html = $m[1];
        }
        preg_match_all('/data-nav-link="([^"]+)"/', $html, $m);

        return $m[1];
    }

    protected function paletteData(string $html): array
    {
        $this->assertMatchesRegularExpression('/<script type="application\/json" id="nav-palette-data">/', $html);
        preg_match('/<script type="application\/json" id="nav-palette-data">(.*?)<\/script>/s', $html, $m);

        return json_decode($m[1], true);
    }

    protected function pinsData(string $html): array
    {
        preg_match('/<script type="application\/json" id="nav-pins-data">(.*?)<\/script>/s', $html, $m);

        return json_decode($m[1] ?? '[]', true);
    }
}
