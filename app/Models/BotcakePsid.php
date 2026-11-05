<?php

namespace App\Models;

use App\Services\PageSettings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class BotcakePsid extends Model
{
    protected $table = 'botcake_psids';

    protected $fillable = [
        'setting_id',
        'page',
        'fb_name',
        'psid',
        'sheet_date',
        'status',
        'first_seen_at',
        'matched_at',
        'matched_rows',
        'last_checked_at',
        'source',
        'phone_numbers',
        'subscribed_at',
        'last_interactive_at',
        'botcake_customer_id',
        'last_seen_at',
        'ad_id',
        'ad_ids',
        'name_key',
        'phone_key',
        'page_id',
    ];

    protected $casts = [
        'first_seen_at'   => 'datetime',
        'matched_at'      => 'datetime',
        'last_checked_at' => 'datetime',
        'phone_numbers'       => 'array',
        'subscribed_at'       => 'datetime',
        'last_interactive_at' => 'datetime',
        'last_seen_at'        => 'datetime',
        'ad_ids'              => 'array',
    ];

    /**
     * May page_id column na ba (naka-memo kada proseso)? Dumarating ang code bago patakbuhin ng kamay ang migrate,
     * kaya hanggang wala pa ang column, pangalan lang ng page ang ginagamit gaya ng dati.
     */
    public static function hasPageId(): bool
    {
        return once(fn () => Schema::hasColumn('botcake_psids', 'page_id'));
    }

    /** Ang botcake.page_id ng page sa page settings, o null. */
    public static function pageIdOf(string $page): ?string
    {
        $id = trim((string) app(PageSettings::class)->get($page, 'botcake', 'page_id'));

        return $id === '' ? null : $id;
    }

    /** ['page_id' => id] para sa create o update kapag kilala ang id at may column na; kung hindi, wala. */
    public static function pageIdAttr(?string $pageId): array
    {
        $pageId = trim((string) $pageId);

        return $pageId !== '' && self::hasPageId() ? ['page_id' => $pageId] : [];
    }

    /** Ang mga column na babasahin, kasama ang page_id kapag may column na (para sa onPage). */
    public static function withPageId(array $cols): array
    {
        return self::hasPageId() ? array_merge($cols, ['page_id']) : $cols;
    }

    /**
     * Ang mga row ng page: ayon sa page_id kapag may id ang page, at ayon sa pangalan ($byName, ang dating compare
     * ng bawat reader) ang mga row na wala pang page_id. Walang id ang page (o wala pang column): $byName lang. Ang
     * pinalitang pangalan ng page ay nakikita pa rin ang lumang row nito; ang ibang page na kapangalan ay hindi.
     */
    public function scopeOfPage(Builder $q, ?string $pageId, Closure $byName): Builder
    {
        $pageId = trim((string) $pageId);
        if ($pageId === '' || !self::hasPageId()) {
            $byName($q);
            return $q;
        }

        return $q->where(fn ($w) => $w->where('page_id', $pageId)->orWhere(function ($n) use ($byName) {
            $n->whereNull('page_id');
            $byName($n);
        }));
    }

    /**
     * Ang UPDATE sa mga row ng page bilang dalawang statement (ayon sa page_id, saka ang walang page_id ayon sa
     * pangalan), hindi iisang OR: sa MySQL ang OR sa UPDATE ay maaaring mag-lock ng lahat ng row na walang page_id.
     * $base ay gumagawa ng bagong query (source, status, …) sa bawat tawag.
     */
    public static function updateOfPage(Closure $base, ?string $pageId, Closure $byName, array $values): int
    {
        $pageId = trim((string) $pageId);
        if ($pageId === '' || !self::hasPageId()) {
            $q = $base();
            $byName($q);
            return $q->update($values);
        }

        $byId = $base()->where('page_id', $pageId)->update($values);
        $q = $base()->whereNull('page_id');
        $byName($q);

        return $byId + $q->update($values);
    }

    /**
     * Para sa reader na pumipili sa PHP: kapag may page_id ang row at ang page, dapat pareho; kung hindi, ang pageKey
     * ng pangalan (ang dating check).
     */
    public function onPage(?string $pageId, string $page): bool
    {
        $own = trim((string) ($this->attributes['page_id'] ?? ''));
        $pageId = trim((string) $pageId);
        if ($own !== '' && $pageId !== '') return $own === $pageId;

        $settings = app(PageSettings::class);

        return $settings->pageKey((string) $this->page) === $settings->pageKey($page);
    }

    protected static function booted(): void
    {
        // Ang API rows ay may naka-normalise na pangalan at unang phone para sa SQL ng psid-coverage page.
        static::saving(function (BotcakePsid $psid) {
            if ($psid->source !== 'api') return;

            $psid->name_key = mb_substr(\App\Services\BotcakeCustomerMatcher::normName($psid->fb_name), 0, 255) ?: null;
            $psid->phone_key = null;
            foreach ((array) $psid->phone_numbers as $ph) {
                $p = \App\Services\BotcakeCustomerMatcher::normPhone(is_scalar($ph) ? (string) $ph : '');
                if ($p !== '') {
                    $psid->phone_key = $p;
                    break;
                }
            }
        });
    }
}
