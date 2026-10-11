<?php

namespace Tests\Feature\Rts;

use App\Http\Middleware\AllowedIpMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ang /jnt_rts page (sqlite in-memory): may filter control ang bawat header ng table at kasama ang script nito.
 * Ang mismong pag-filter ay nasa browser (DataTables): ang lohika nito ay nasa RtsColumnFilterScriptTest.
 * Minimal na legacy columns lang ng from_jnts ang ginagawa rito, at imbento ang mga row.
 */
class RtsPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // allowed_ip: hinaharang ang hindi CEO sa 127.0.0.1 — hindi ito ang sinusubok dito.
        $this->withoutMiddleware(AllowedIpMiddleware::class);

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('employee_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->string('employee_code')->nullable();
            $t->string('role')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('from_jnts', function (Blueprint $t) {
            $t->id();
            $t->string('sender')->nullable();
            $t->string('item_name')->nullable();
            $t->string('cod')->nullable();
            $t->string('status')->nullable();
            $t->string('rts_reason')->nullable();
            $t->dateTime('submission_time')->nullable();
        });

        foreach ([
            ['Zeta Shop', '1 x TSAA NA IMBENTO', '499', 'Delivered'],
            ['Zeta Shop', '1 x TSAA NA IMBENTO', '499', 'Returned'],
            ['Beta Mart', '2 x SABON NA IMBENTO', '899', 'In Transit'],
        ] as [$sender, $item, $cod, $status]) {
            DB::table('from_jnts')->insert([
                'sender' => $sender, 'item_name' => $item, 'cod' => $cod, 'status' => $status,
                'submission_time' => '2026-09-10 09:00:00',
            ]);
        }

        $user = User::create(['name' => 'Marketing User', 'email' => 'marketing@example.test', 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => 'Marketing']);
        $this->actingAs($user->fresh());
    }

    public function test_every_column_header_has_a_filter_control_and_the_page_loads_the_filter_script(): void
    {
        $html = $this->get('/jnt_rts?from=2026-09-01&to=2026-09-30')->assertOk()->getContent();

        $this->assertStringContainsString('Zeta Shop', $html);
        $this->assertSame(13, preg_match_all('/<th\b[^>]*>.*?<\/th>/s', $html, $headers));
        foreach ($headers[0] as $col => $th) {
            $this->assertMatchesRegularExpression(
                '/<button type="button" class="rts-cf-btn" data-col="' . $col . '" aria-label="Filter [^"]+" aria-haspopup="dialog" aria-expanded="false">/',
                $th
            );
        }
        $this->assertMatchesRegularExpression('#<script src="[^"]*/js/rts-column-filter\.js\?v=[0-9a-f]{10}"></script>#', $html);
        // Lumalabas lang ito kapag may aktibong filter; ang script ng page ang nagpapakita.
        $this->assertMatchesRegularExpression('/<button type="button" id="rtsCfClearAll"[^>]*\bhidden\b[^>]*>\s*Clear column filters\s*<\/button>/', $html);
    }
}
