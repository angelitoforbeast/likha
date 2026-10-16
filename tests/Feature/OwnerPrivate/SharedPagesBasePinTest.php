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
    /** sha1 ng normalised render ng CEO, galing sa base commit; muling na-pin nang idagdag ang PROF(1D)-M column (bagong cell at column id lang ang diff), at nang idagdag ang "tie" mark sa Item cell at ang "tie(s) resolved" na mensahe ng recompute. */
    private const BASE = [
        '/owner/private' => '07469c675380fee5ddbf9c9c2635fe285fd20b1f',
        '/owner/column-settings/owner-private' => 'ce0fff4796f1dec01038c2b3d4e9d38b419c17b9',
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
