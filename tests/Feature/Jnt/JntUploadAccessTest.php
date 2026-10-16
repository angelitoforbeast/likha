<?php

namespace Tests\Feature\Jnt;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Jobs\ProcessJntUpload;
use App\Models\UploadLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Sino ang puwede sa /jnt_upload: CEO, Marketing at Marketing - OIC ang nag-a-upload; ang Data Encoder
 * at Data Encoder - OIC ay tumitingin lang (403 sa upload); ibang role ay 404 pa rin. Kasama ang
 * "Waybill" link sa menu ng Data Encoders.
 */
class JntUploadAccessTest extends AuthTestCase
{
    private const NAV_MIGRATION = 'database/migrations/2026_10_16_200000_show_waybill_nav_link_to_data_encoders.php';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path'     => [
                'database/migrations/2025_08_12_004610_rebuild_upload_logs_table.php',
                'database/migrations/2025_12_10_125104_add_batch_at_to_upload_logs_table.php',
                'database/migrations/2026_04_28_120001_add_error_message_to_upload_logs_table.php',
                'database/migrations/2026_05_11_130000_add_user_tracking_to_upload_logs.php',
                'database/migrations/2026_05_13_150000_add_file_metadata_to_upload_logs.php',
                'database/migrations/2026_05_15_120000_add_protected_details_to_upload_logs.php',
                'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php',
                self::NAV_MIGRATION,
            ],
            '--realpath' => false,
        ]);

        // allowed_ip: hinaharang ang hindi CEO sa 127.0.0.1 — hindi ito ang sinusubok dito.
        $this->withoutMiddleware(AllowedIpMiddleware::class);
        Storage::fake(config('filesystems.default'));
        Bus::fake();
    }

    private function signedIn(string $role): User
    {
        $user = $this->user($role);
        $this->actingAs($user);

        return $user;
    }

    private function log(): UploadLog
    {
        return UploadLog::create(['type' => 'jnt', 'disk' => 'local', 'path' => 'uploads/jnt/a.csv', 'original_name' => 'a.csv', 'status' => 'done']);
    }

    private function postUpload()
    {
        return $this->post('/jnt_upload', ['file' => UploadedFile::fake()->createWithContent('waybills.csv', "a,b\n1,2\n")]);
    }

    public static function viewers(): array
    {
        return [
            'Data Encoder'          => ['Data Encoder'],
            'Data Encoder - OIC'    => ['Data Encoder - OIC'],
            'spaces, case, en dash' => ['  data encoder – oic '],
        ];
    }

    public static function uploaders(): array
    {
        return [
            'CEO'             => ['CEO'],
            'Marketing'       => ['Marketing'],
            'Marketing - OIC' => ['Marketing - OIC'],
        ];
    }

    public static function strangers(): array
    {
        return [
            'no role'      => [''],
            'unknown role' => ['Sales'],
        ];
    }

    #[DataProvider('viewers')]
    public function test_S_035_1_data_encoders_view_the_page_status_and_history_without_the_upload_form(string $role): void
    {
        $this->signedIn($role);
        $log = $this->log();

        $page = $this->get('/jnt_upload')->assertOk();
        $page->assertDontSee('id="uploadForm"', false);
        $page->assertDontSee('id="btnUpload"', false);
        $page->assertDontSee('id="batch_at"', false);
        $page->assertSee('View only');
        $page->assertSee('a.csv');

        $this->get('/jnt_upload/status/' . $log->id)->assertOk()->assertJson(['id' => $log->id, 'status' => 'done']);
        $this->get('/jnt_upload/history')->assertOk()->assertJsonCount(1, 'rows');
    }

    #[DataProvider('viewers')]
    public function test_S_035_2_data_encoders_get_403_on_upload_and_nothing_is_stored_or_queued(string $role): void
    {
        $this->signedIn($role);

        $this->postUpload()->assertForbidden();

        $this->assertSame(0, UploadLog::count());
        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles());
        Bus::assertNotDispatched(ProcessJntUpload::class);
    }

    #[DataProvider('uploaders')]
    public function test_S_035_3_ceo_and_marketing_still_see_the_form_and_may_upload(string $role): void
    {
        $this->signedIn($role);

        $page = $this->get('/jnt_upload')->assertOk();
        $page->assertSee('id="uploadForm"', false);
        $page->assertSee('id="btnUpload"', false);
        $page->assertDontSee('View only');

        $this->postUpload()->assertCreated();
        $this->assertSame(1, UploadLog::count());
        Bus::assertDispatched(ProcessJntUpload::class);
    }

    #[DataProvider('strangers')]
    public function test_S_035_4_other_roles_still_get_404_everywhere(string $role): void
    {
        $this->signedIn($role);
        $log = $this->log();

        $this->get('/jnt_upload')->assertNotFound();
        $this->get('/jnt_upload/status/' . $log->id)->assertNotFound();
        $this->get('/jnt_upload/history')->assertNotFound();
        $this->postUpload()->assertNotFound();
        $this->assertSame(1, UploadLog::count());
        Bus::assertNotDispatched(ProcessJntUpload::class);
    }

    /** Ang menu ay tumutugma sa eksaktong role sa database, kaya ang dalawang tunay na pangalan lang. */
    #[DataProvider('menuRoles')]
    public function test_S_035_5_the_menu_shows_the_waybill_link_to_data_encoders(string $role): void
    {
        $this->signedIn($role);

        $this->get('/jnt_upload')->assertOk()->assertSee('href="/jnt_upload"', false);
    }

    public static function menuRoles(): array
    {
        return ['Data Encoder' => ['Data Encoder'], 'Data Encoder - OIC' => ['Data Encoder - OIC']];
    }

    public function test_S_035_6_the_menu_migration_reruns_without_duplicates_and_touches_no_other_row(): void
    {
        $before = DB::table('nav_link_role_visibility')->orderBy('id')->get()->toArray();
        $count  = count($before);

        (require base_path(self::NAV_MIGRATION))->up();

        $this->assertEquals($before, DB::table('nav_link_role_visibility')->orderBy('id')->get()->toArray());
        $this->assertSame($count, DB::table('nav_link_role_visibility')->count());

        $waybill = DB::table('nav_links')->where('key', 'waybill')->value('id');
        $visible = DB::table('nav_link_role_visibility')->where('nav_link_id', $waybill)->where('is_visible', true)->orderBy('role')->pluck('role')->all();
        $this->assertSame(['CEO', 'Data Encoder', 'Data Encoder - OIC', 'Marketing', 'Marketing - OIC'], $visible);
    }
}
