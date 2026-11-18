<?php

namespace Tests\Feature\PaymentActivity;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\OwnerPrivate\OwnerPrivateTestCase;

/**
 * Spec 297 AS-3: Upload History on /ads_manager/payment/upload also lists the Meta hourly sync runs (upload_logs type
 * meta_ads_hourly), newest first, mixed with the payment uploads, with a type label and a filter. Each type has its own
 * count, so the 24 runs a day never push the payment uploads out of the list.
 */
class UploadHistoryTest extends OwnerPrivateTestCase
{
    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            // The page's "Ad Account ID" button and the layout's nav read these.
            'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php',
            'database/migrations/2025_08_12_004610_rebuild_upload_logs_table.php',
            'database/migrations/2026_04_28_120001_add_error_message_to_upload_logs_table.php',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tasks', function (Blueprint $t) { // counted by the layout
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->travelTo(Carbon::parse('2026-11-18 10:30:00', 'Asia/Manila'));
    }

    private function log(string $type, string $name, string $status, string $at, ?string $error = null, int $rows = 0, ?string $finished = null): int
    {
        return DB::table('upload_logs')->insertGetId([
            'type' => $type, 'disk' => $type === 'meta_ads_hourly' ? 'api' : 'local', 'path' => $type === 'meta_ads_hourly' ? 'meta' : 'uploads/x.csv',
            'original_name' => $name, 'status' => $status, 'processed_rows' => $rows, 'inserted' => $rows, 'total_rows' => $type === 'payment_activity' ? $rows : null,
            'error_message' => $error, 'started_at' => $at, 'finished_at' => $finished ?? Carbon::parse($at)->addSeconds(4)->toDateTimeString(),
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** The rows of the Upload History table: [type label, file/run, status label, error first line]. */
    private function rows(string $html): array
    {
        $this->assertSame(1, preg_match('#<div class="bg-white rounded shadow p-4 mt-6" id="upload-history">(.*?)</table>#s', $html, $m));
        preg_match_all('#<tr class="border-b hover:bg-gray-50" data-log-type="(\w+)">(.*?)</tr>#s', $m[1], $trs, PREG_SET_ORDER);

        return array_map(function ($tr) {
            preg_match_all('#<td[^>]*>(.*?)</td>#s', $tr[2], $tds);
            $text = fn ($s) => trim(preg_replace('/\s+/', ' ', strip_tags($s)));
            preg_match('#<span class="inline-block px-2 py-0.5 rounded text-xs font-semibold ([^"]+)">([^<]*)</span>#', $tds[1][2], $status);

            return ['kind' => $tr[1], 'type' => $text($tds[1][0]), 'name' => $text($tds[1][1]), 'status' => $status[2] ?? '', 'tone' => $status[1] ?? '',
                'rows' => $text($tds[1][3]), 'started' => $text($tds[1][9]), 'finished' => $text($tds[1][10]), 'cell' => $tds[1][2]];
        }, $trs);
    }

    /** AS-3 H: a done run and a partial run blocked by Meta both show, with the payment uploads, newest first. */
    public function test_AS3_hourly_runs_show_with_the_payment_uploads_newest_first(): void
    {
        $this->log('payment_activity', 'payments-nov.csv', 'done', '2026-11-18 08:45:00', null, 120);
        $this->log('meta_ads_hourly', 'Meta hourly 2026-11-18', 'done', '2026-11-18 09:10:02', null, 216);
        $this->log('meta_ads_hourly', 'Meta hourly 2026-11-18', 'partial', '2026-11-18 10:10:01',
            "act_111 2026-11-18: Graph error 200: API access blocked\nact_222 2026-11-18: Graph error 200: API access blocked", 0);
        $this->log('jnt', 'jnt-upload.xlsx', 'done', '2026-11-18 10:20:00');   // another type: not on this page

        $html = $this->actingAs($this->user('Marketing', 'mk@example.test'))->get('/ads_manager/payment/upload')->assertOk()->getContent();
        $rows = $this->rows($html);

        $this->assertSame(['hourly', 'hourly', 'payment'], array_column($rows, 'kind'), 'newest first, mixed');
        $this->assertSame(['Meta hourly sync', 'Meta hourly sync', 'Payment upload'], array_column($rows, 'type'));
        $this->assertSame(['Partial', 'Done', 'Done'], array_column($rows, 'status'));
        $this->assertSame('bg-amber-100 text-amber-800', $rows[0]['tone'], 'the blocked run is amber');
        $this->assertSame('bg-green-100 text-green-700', $rows[1]['tone']);
        $this->assertStringContainsString('act_111 2026-11-18: Graph error 200: API access blocked</div>', $rows[0]['cell'], 'the error\'s first line in the row');
        $this->assertStringContainsString('title="act_111 2026-11-18: Graph error 200: API access blocked&#10;act_222', str_replace("\n", '&#10;', $rows[0]['cell']), 'the full text on hover');
        $this->assertStringContainsString('<summary class="cursor-pointer text-gray-500">Full error (2 lines)</summary>', $rows[0]['cell']);
        $this->assertSame(['0', '216', '120'], array_column($rows, 'rows'));
        $this->assertSame(['Nov 18, 2026 10:10 AM', 'Nov 18, 2026 10:10 AM'], [$rows[0]['started'], $rows[0]['finished']], 'started and finished, Manila');
        $this->assertSame('Nov 18, 2026 08:45 AM', $rows[2]['started']);
        $this->assertStringNotContainsString('jnt-upload.xlsx', $html);
    }

    /** AS-3: the filter, and failed / skipped runs. */
    public function test_AS3_the_filter_shows_one_type_and_the_status_colours(): void
    {
        $this->log('payment_activity', 'payments-nov.csv', 'done', '2026-11-18 08:45:00', null, 120);
        $this->log('meta_ads_hourly', 'Meta hourly 2026-11-18', 'failed', '2026-11-18 09:10:00', 'Stopped: timeout');
        $this->log('meta_ads_hourly', 'Meta hourly 2026-11-18', 'skipped', '2026-11-18 10:10:00', 'act_111 2026-11-18: rate limited, run skipped (Error 17)');
        $this->actingAs($this->user('Marketing', 'mk@example.test'));

        $hourly = $this->rows($this->get('/ads_manager/payment/upload?show=hourly')->assertOk()->getContent());
        $this->assertSame(['hourly', 'hourly'], array_column($hourly, 'kind'));
        $this->assertSame([['Skipped', 'bg-amber-100 text-amber-800'], ['Failed', 'bg-red-100 text-red-700']], array_map(fn ($r) => [$r['status'], $r['tone']], $hourly));
        $this->assertStringContainsString('text-red-600" title="Stopped: timeout" data-error-first>Stopped: timeout</div>', $hourly[1]['cell']);
        $this->assertStringNotContainsString('<details', $hourly[1]['cell'], 'one short line: nothing to expand');

        $payment = $this->rows($html = $this->get('/ads_manager/payment/upload?show=payment')->assertOk()->getContent());
        $this->assertSame(['payment'], array_column($payment, 'kind'));
        $this->assertSame(1, preg_match('#aria-current="true"\s*>Payment uploads</a>#', $html), 'the chosen filter is marked');

        foreach (['', '?show=all', '?show=bogus', '?show[]=hourly', '?show=%3Cscript%3E'] as $qs) {
            $page = $this->get('/ads_manager/payment/upload' . $qs)->assertOk()->getContent();
            $this->assertSame(['hourly', 'hourly', 'payment'], array_column($this->rows($page), 'kind'), "'$qs' shows all");
            $this->assertStringNotContainsString('<script>', substr($page, strpos($page, 'id="upload-history"')));
        }
    }

    /**
     * AS-3 E: each type keeps its own count: under All the latest 50 payment uploads and the latest 24 runs (one day);
     * under "Hourly sync" the latest 72 runs. Many runs never push the payment uploads out.
     */
    public function test_AS3_the_hourly_runs_never_push_the_payment_uploads_out(): void
    {
        for ($i = 0; $i < 55; $i++) $this->log('payment_activity', "pay-$i.csv", 'done', Carbon::parse('2026-11-17 09:00:00')->subDays(54 - $i)->toDateTimeString());
        for ($i = 0; $i < 80; $i++) $this->log('meta_ads_hourly', "Meta hourly run $i", 'done', Carbon::parse('2026-11-18 10:10:00')->subHours($i)->toDateTimeString());
        $this->actingAs($this->user('Marketing', 'mk@example.test'));

        $all = $this->rows($this->get('/ads_manager/payment/upload')->assertOk()->getContent());
        $this->assertSame(24 + 50, count($all));
        $this->assertSame(24, count(array_filter($all, fn ($r) => $r['kind'] === 'hourly')));
        $this->assertSame(['Meta hourly run 0', 'Meta hourly run 23'], [$all[0]['name'], $all[23]['name']], 'the latest day of runs on top');
        $this->assertSame(['pay-54.csv', 'pay-5.csv'], [$all[24]['name'], $all[73]['name']], 'then the 50 latest payment uploads, as before');

        $hourly = $this->rows($this->get('/ads_manager/payment/upload?show=hourly')->getContent());
        $this->assertCount(72, $hourly);
        $this->assertSame('Meta hourly run 71', $hourly[71]['name']);
        $this->assertCount(50, $this->rows($this->get('/ads_manager/payment/upload?show=payment')->getContent()));
    }

    /** AS-3: the stored error text is Meta's (untrusted): escaped in the row, the hover text and the expand. */
    public function test_AS3_the_error_text_is_escaped(): void
    {
        $evil = "Graph error 1: <script>alert(1)</script> \"quoted\" & <b>bold</b>\n<img src=x onerror=alert(2)>";
        $this->log('meta_ads_hourly', 'Meta hourly <i>2026-11-18</i>', 'partial', '2026-11-18 10:10:00', $evil);

        $html = $this->actingAs($this->user('Marketing', 'mk@example.test'))->get('/ads_manager/payment/upload')->assertOk()->getContent();
        $section = substr($html, strpos($html, 'id="upload-history"'));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $section);
        $this->assertStringNotContainsString('<img src=x', $section);
        $this->assertStringNotContainsString('<b>bold</b>', $section);
        $this->assertStringNotContainsString('<i>2026-11-18</i>', $section);
        $this->assertStringContainsString('Graph error 1: &lt;script&gt;alert(1)&lt;/script&gt; &quot;quoted&quot; &amp; &lt;b&gt;bold&lt;/b&gt;</div>', $section, 'first line, escaped');
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;</pre>', $section, 'the expand, escaped');
        $this->assertSame(1, preg_match('#title="Graph error 1: &lt;script&gt;alert\(1\)&lt;/script&gt; &quot;quoted&quot; &amp; &lt;b&gt;bold&lt;/b&gt;\s+&lt;img src=x onerror=alert\(2\)&gt;" data-error-first>#', $section), 'the hover text, escaped');
    }

    /** AS-3 N: the same route, middleware and page access as before; a guest sees nothing. */
    public function test_AS3_a_user_without_access_sees_nothing_new(): void
    {
        $this->log('meta_ads_hourly', 'Meta hourly 2026-11-18', 'partial', '2026-11-18 10:10:00', 'Graph error 200: API access blocked');

        $this->get('/ads_manager/payment/upload')->assertRedirect('/login');
        $this->get('/ads_manager/payment/upload?show=hourly')->assertRedirect('/login');

        $route = Route::getRoutes()->match(request()->create('/ads_manager/payment/upload', 'GET'));
        $this->assertSame(['web', 'auth', 'allowed_ip'], $route->gatherMiddleware());
        $this->assertSame('ads_payment.upload', $route->getName());
    }
}
