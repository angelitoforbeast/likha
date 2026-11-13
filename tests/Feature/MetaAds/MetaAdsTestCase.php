<?php

namespace Tests\Feature\MetaAds;

use App\Models\User;
use App\Services\MetaAds\Connections;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Feature\OwnerPrivate\OwnerPrivateTestCase;

/**
 * Base ng Meta Ads tests (sqlite in-memory). Walang totoong tawag sa Meta: Http::preventStrayRequests, at ang Graph
 * ay pinapalitan ng fakeGraph() na sumasagot ayon sa path (at sa token sa Authorization header).
 */
abstract class MetaAdsTestCase extends OwnerPrivateTestCase
{
    public const TOKEN_ANA = 'EAAB-ana-long-lived-token-SECRET-1234';
    public const TOKEN_BEN = 'EAAB-ben-long-lived-token-SECRET-5678';

    /** @var array<int, string> mga hiningi sa Graph (path lang) */
    protected array $graphCalls = [];

    /** @var array<int, array{path: string, query: array, token: string, url: string}> ang buong hiningi */
    protected array $graphRequests = [];

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            // "Ads" link (/ads_manager/report): Marketing, Marketing - OIC at CEO.
            'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php',
            // Ang Facebook page ID ng bawat Likha page (botcake.page_id): pinanggagalingan ng page_name.
            'database/migrations/2026_10_17_100000_create_page_settings_table.php',
            'database/migrations/2026_11_03_100000_add_replaced_by_to_page_settings.php',
            'database/migrations/2026_11_12_100000_add_source_to_page_settings.php',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();

        config([
            'services.meta_ads.app_id'        => '999000',
            'services.meta_ads.app_secret'    => 'app-secret-xyz',
            'services.meta_ads.graph_version' => 'v25.0',
            'app.url'                         => 'https://likha.test',
        ]);

        Schema::create('tasks', function (Blueprint $t) { // binibilang ng view composer
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('upload_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('type')->default('jnt');
            $t->string('disk')->default('local');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_email')->nullable();
            $t->dateTime('file_created_at')->nullable();
            $t->dateTime('file_modified_at')->nullable();
            $t->string('status')->default('queued');
            $t->unsignedBigInteger('total_rows')->nullable();
            $t->unsignedBigInteger('processed_rows')->default(0);
            $t->unsignedBigInteger('inserted')->default(0);
            $t->unsignedBigInteger('updated')->default(0);
            $t->unsignedBigInteger('skipped')->default(0);
            $t->unsignedBigInteger('error_rows')->default(0);
            $t->json('protected_details')->nullable();
            $t->text('error_message')->nullable();
            $t->string('errors_path')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('batch_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name');
            $t->string('campaign_name')->nullable();
            $t->string('ad_set_id')->nullable();
            $t->string('ad_set_name')->nullable();
            $t->string('campaign_id')->nullable();
            $t->string('campaign_delivery')->nullable();
            $t->string('ad_set_delivery')->nullable();
            $t->bigInteger('reach')->nullable();
            $t->bigInteger('impressions')->nullable();
            $t->decimal('ad_set_budget', 12, 2)->nullable();
            $t->string('ad_set_budget_type')->nullable();
            $t->string('attribution_setting')->nullable();
            $t->string('result_type')->nullable();
            $t->bigInteger('results')->nullable();
            $t->decimal('amount_spent_php', 12, 2)->nullable();
            $t->decimal('cost_per_result', 12, 2)->nullable();
            $t->dateTime('starts')->nullable();
            $t->dateTime('ends')->nullable();
            $t->bigInteger('messaging_conversations_started')->nullable();
            $t->bigInteger('purchases')->nullable();
            $t->dateTime('reporting_starts')->nullable();
            $t->dateTime('reporting_ends')->nullable();
            $t->string('ad_id')->nullable();
            $t->text('headline')->nullable();
            $t->text('body_ad_settings')->nullable();
            $t->text('welcome_message')->nullable();
            $t->string('quick_reply_1')->nullable();
            $t->string('quick_reply_2')->nullable();
            $t->string('quick_reply_3')->nullable();
            $t->string('item_name')->nullable();
            $t->bigInteger('link_clicks')->nullable();
            $t->string('account_id')->nullable();
            // Spec 244 (2026_11_20_100000_add_video_views_to_ads_manager_reports).
            $t->unsignedInteger('video_plays_3s')->nullable();
            $t->unsignedInteger('video_thruplays')->nullable();
            $t->unsignedInteger('video_p50')->nullable();
            $t->timestamps();
            $t->unique(['day', 'ad_id']);
        });
        // Ang page ng adDetail() (object_story_spec.page_id p1).
        $this->pageSetting('Page P1', 'p1');
        Schema::create('ad_campaign_creatives', function (Blueprint $t) {
            $t->id();
            $t->string('ad_id')->nullable()->unique();
            $t->string('campaign_id')->nullable();
            $t->string('account_id')->nullable();
            $t->string('campaign_name')->nullable();
            $t->string('page_name')->nullable();
            $t->string('ad_set_delivery')->nullable();
            $t->text('headline')->nullable();
            $t->text('body_ad_settings')->nullable();
            $t->text('welcome_message')->nullable();
            $t->string('quick_reply_1')->nullable();
            $t->string('quick_reply_2')->nullable();
            $t->string('quick_reply_3')->nullable();
            // 2025_08_26_001847_add_ad_link_to_ad_campaign_creatives (spec 252: the sync fills it).
            $t->string('ad_link')->nullable();
            $t->timestamps();
        });
    }

    /** Isang botcake.page_id row sa page_settings (active = false: naka-replace na). */
    protected function pageSetting(string $page, string $pageId, bool $active = true): void
    {
        DB::table('page_settings')->insert([
            'page_name' => $page, 'provider' => 'botcake', 'key' => 'page_id', 'value' => $pageId, 'is_secret' => false,
            'active' => $active, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function connect(User $user, string $token, array $accounts, array $extra = []): void
    {
        app(Connections::class)->save($user->id, $token, $extra + [
            'fb_user_id'   => 'fb' . $user->id,
            'fb_user_name' => $user->name . ' FB',
            'expires_at'   => now()->addDays(50)->timestamp,
            'ad_accounts'  => array_map(fn ($id) => ['id' => (string) $id, 'name' => 'Account ' . $id, 'status' => 1], $accounts),
        ]);
    }

    /** Isang ad sa insights, sa hugis na sinasagot ng Graph. */
    protected static function insight(string $account, string $ad, string $day, float $spend, array $extra = []): array
    {
        return $extra + [
            'account_id'          => $account,
            'ad_id'               => $ad,
            'ad_name'             => 'Ad ' . $ad,
            'adset_id'            => 'as' . $ad,
            'adset_name'          => 'Adset ' . $ad,
            'campaign_id'         => 'c' . $ad,
            'campaign_name'       => 'Campaign ' . $ad,
            'reach'               => '900',
            'impressions'         => '1500',
            'inline_link_clicks'  => '33',
            'spend'               => (string) $spend,
            'attribution_setting' => '7d_click_1d_view',
            'actions'             => [
                ['action_type' => 'link_click', 'value' => '33'],
                ['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '12'],
                ['action_type' => 'omni_purchase', 'value' => '2'],
            ],
            'results'             => [['indicator' => 'actions:onsite_conversion.messaging_conversation_started_7d', 'values' => [['value' => '12']]]],
            'cost_per_result'     => [['indicator' => 'actions:onsite_conversion.messaging_conversation_started_7d', 'values' => [['value' => round($spend / 12, 2)]]]],
            'date_start'          => $day,
            'date_stop'           => $day,
        ];
    }

    /** Ang detalye ng ad (ad set, campaign, creative) gaya ng /act_x/ads. */
    protected static function adDetail(string $ad, array $extra = []): array
    {
        return array_replace_recursive([
            'id'               => $ad,
            'effective_status' => 'ACTIVE',
            'adset'            => [
                'id' => 'as' . $ad, 'effective_status' => 'ACTIVE', 'daily_budget' => '50000',
                'start_time' => '2026-10-01T08:00:00+0800', 'optimization_goal' => 'CONVERSATIONS',
            ],
            'campaign'         => ['id' => 'c' . $ad, 'effective_status' => 'ACTIVE'],
            'creative'         => [
                'id' => 'cr' . $ad, 'title' => 'Headline ' . $ad, 'body' => 'Body ' . $ad,
                'object_story_spec' => ['page_id' => 'p1'],
            ],
        ], $extra);
    }

    /**
     * Pekeng Graph. $insights / $ads: account id => list (o [page1, page2] kapag 'paged'); $errors: token => error code
     * na isinasagot sa insights. Ang lahat ng tawag ay itinatala sa $graphCalls.
     */
    protected function fakeGraph(array $insights = [], array $ads = [], array $errors = [], array $paged = []): void
    {
        Http::fake(function (Request $req) use ($insights, $ads, $errors, $paged) {
            $url   = $req->url();
            $path  = trim(preg_replace('#^/v25\.0#', '', (string) parse_url($url, PHP_URL_PATH)), '/');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $token = trim(str_replace('Bearer', '', $req->header('Authorization')[0] ?? ''));
            $this->graphCalls[] = $path;
            $this->graphRequests[] = ['path' => $path, 'query' => $q, 'token' => $token, 'url' => $url];

            if ($path === 'oauth/access_token') {
                return ($q['grant_type'] ?? '') === 'fb_exchange_token'
                    ? Http::response(['access_token' => self::TOKEN_ANA, 'token_type' => 'bearer', 'expires_in' => 5184000])
                    : Http::response(['access_token' => 'EAAB-short-token', 'token_type' => 'bearer']);
            }
            if ($path === 'debug_token') {
                return Http::response(['data' => ['user_id' => 'fb-ana', 'is_valid' => true, 'expires_at' => now()->addDays(60)->timestamp, 'data_access_expires_at' => 0]]);
            }
            if ($path === 'me') return Http::response(['id' => 'fb-ana', 'name' => 'Ana Facebook']);
            if ($path === 'me/adaccounts') {
                return Http::response(['data' => [
                    ['id' => 'act_111', 'account_id' => '111', 'name' => 'Likha A', 'account_status' => 1],
                    ['id' => 'act_222', 'account_id' => '222', 'name' => 'Likha B', 'account_status' => 1],
                ]]);
            }
            if (isset($errors[$token]) && str_ends_with($path, '/insights')) {
                $code = $errors[$token];
                return Http::response(['error' => ['message' => 'Error code ' . $code, 'type' => 'OAuthException', 'code' => $code]], $code === 190 ? 401 : 400);
            }
            if (preg_match('#^act_(\d+)/insights$#', $path, $m)) {
                $acc = $m[1];
                if (in_array($acc, $paged, true)) {
                    $pages = $insights[$acc] ?? [[], []];
                    if (($q['after'] ?? '') === 'p2') return Http::response(['data' => $pages[1]]);
                    return Http::response(['data' => $pages[0], 'paging' => ['cursors' => ['after' => 'p2'],
                        'next' => "https://graph.facebook.com/v25.0/act_{$acc}/insights?access_token=EAAB-in-next-url&after=p2"]]);
                }
                return Http::response(['data' => $insights[$acc] ?? []]);
            }
            if (preg_match('#^act_(\d+)/ads$#', $path, $m)) return Http::response(['data' => $ads[$m[1]] ?? []]);

            return Http::response(['error' => ['message' => 'unexpected ' . $path, 'code' => 100]], 400);
        });
    }

    protected function reportRows(): array
    {
        return DB::table('ads_manager_reports')->orderBy('day')->orderBy('ad_id')->get()->map(fn ($r) => (array) $r)->all();
    }
}
