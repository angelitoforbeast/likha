<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang dalawang page na kahati ng item table sa column setting: /owner/private at ang section ng column settings na
 * nag-e-edit nito (ang /owner/column-settings mismo ay redirect lang papunta roon).
 * Nakapin ang buong render ng bawat isa (sha1 ng normalised na HTML) mula sa commit bago ang anumang
 * pagbabago sa item table, para mahuli agad kapag may tumagas na pagbabago papunta sa kanila.
 */
class SharedPagesBasePinTest extends OwnerPrivateTestCase
{
    /** sha1 ng normalised render ng CEO, galing sa base commit; muling na-pin nang idagdag ang PROF(1D)-M column (bagong cell at column id lang ang diff), at nang idagdag ang "tie" mark sa Item cell at ang "tie(s) resolved" na mensahe ng recompute, at ang limang delivery column ng campaigns (ad_delivered … ad_rts_pct) kasama ang "Walang ad id" at "Total ng page (ayon sa order)" na linya ng expand, ang POST at tanda ng delivery-only na campaign, at ang RTS / DEL / INT (orders) na cell na pumalit sa lima, at ang Mira RTS at Profit (Mira RTS) na column (cell, totals at ang dalawang bagong entry sa column settings), at ang limang column ng Conditional Formatting ng Mira RTS (gap, error, ratio, orders) kasama ang seeded na rule at ang pagtanggal ng hard-coded na pulang kulay, at ang Prof 1D (Mira) na column, at nang alisin ang native title na umuulit sa hover box ng compact (title attribute lang ang diff), at ang Mira RTS accuracy na column (cell, hover, column def at ang entry sa column settings), at ang presyo sa hover ng Mira RTS at ang "not enough yet" na hover ng walang-value na cell nito (hover at title lang ang diff), at ang sino at kailan sa hover ng RTS at Item Value (title at owTip lang ang diff), at ang tatlong video column ng campaigns (spec 244: video_plays_3s, video_thruplays, video_p50 sa campaigns catalog, order at default visible lang ang diff), at ang tatlong rate ng video (spec 246: hook_rate, hold_rate, hold_rate_50 sa campaigns catalog, order at default visible, ang tatlong column def at ang level ng ads table sa expand lang ang diff), at ang switcher ng creative preview (spec 254: ang template ng "N different creatives" at ang creativeModal.pick lang ang diff), at ang Days running column at ang iisang column list (spec 258: ang cell, ang days_running sa order, catalog at default visible, ang dagdag na sort/align/minw sa catalog JSON ng settings, at ang defaultCols na galing na sa OwnerPrivateColumns, whitespace lang ang iba sa mga dati nang column), at nang alisin ang x-init="init()" sa <html> (spec 261: isang item-summary kada load; ang attribute at ang comment sa itaas ng init() lang ang diff). */
    private const BASE = [
        '/owner/private' => 'cb5ad1ee2efb7e84a85035159149f232edf42726',
        '/owner/column-settings/owner-private' => 'e5682df0d3cdb3c0cafd2a5d2bab6e92edb96944',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Nakapirming araw: may default na petsa ang /owner/private na galing sa "ngayon".
        $this->travelTo('2026-10-08 12:00:00');
        $tables = [
            'tasks'               => ['user_id', 'status'],
            'ads_manager_reports' => ['page_name'],
            'fee_settings'        => ['setting_key', 'setting_value', 'effective_date', 'description', 'host_scope'],
        ];
        foreach ($tables as $table => $columns) {
            if (Schema::hasTable($table)) continue;
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $column) $t->string($column)->nullable();
                $t->timestamps();
            });
        }
    }

    /** CRLF → LF, ang root ng app (plain at JSON-escaped) at ang CSRF token → fixed na salita. */
    private function normalise(string $html): string
    {
        $html = str_replace("\r\n", "\n", $html);
        $root = rtrim(url('/'), '/');
        $html = str_replace([$root, str_replace('/', '\/', $root)], 'APPROOT', $html);
        $token = csrf_token();
        if (is_string($token) && $token !== '') $html = str_replace($token, 'CSRF', $html);

        return (string) preg_replace('/<meta name="csrf-token" content="[^"]*"/', '<meta name="csrf-token" content="CSRF"', $html);
    }

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return array_map(fn (string $url) => [$url], array_combine(array_keys(self::BASE), array_keys(self::BASE)));
    }

    #[DataProvider('pages')]
    public function test_S_42_4_the_shared_owner_pages_render_byte_for_byte_as_at_the_base(string $url): void
    {
        $this->actingAs($this->user());
        $first = $this->normalise((string) $this->get($url)->assertOk()->getContent());
        // Dalawang request, parehong sagot: kung hindi, may bahagi ng page na nagbabago kada request at walang silbi ang pin.
        $this->assertSame(sha1($first), sha1($this->normalise((string) $this->get($url)->assertOk()->getContent())), 'hindi stable ang render');

        $this->assertSame(self::BASE[$url], sha1($first), "nagbago ang render ng {$url}");
    }
}
