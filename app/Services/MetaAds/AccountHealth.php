<?php

namespace App\Services\MetaAds;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Spec 284: the money side and health of every Meta ad account the site's connections can read (balance owed, lifetime
 * spend, spend cap, payment method, status). Read-only toward Meta.
 *
 * One app_settings row (KEY) is both the cache and the last good read, so no table is needed: JSON {attempted_at,
 * read_at, rows: {account id: row}, notes, errors}. A row keeps its own read_at, so when a connection cannot be read
 * (rate limit, API blocked) its accounts keep the last good numbers with their time. No token and no raw Graph body is
 * stored.
 */
class AccountHealth
{
    public const KEY = 'meta_ads.account_health';
    /** A read (good or failed) younger than this is reused, so a blocked API is not hit on every page load. */
    public const FRESH_MINUTES = 10;
    /** The web request must not wait on Graph's rate-limit back-off: one try, this many seconds per call. */
    public const TIMEOUT = 20;
    /** Long enough for every connection's call plus one retry each. */
    public const LOCK_SECONDS = 180;
    public const FIELDS = 'account_id,name,account_status,disable_reason,currency,balance,amount_spent,spend_cap';
    /** Needs MANAGE on the ad account; refused for some tokens, so it is requested separately on retry. */
    public const FUNDING = 'funding_source_details';
    /** Spend cap used at or above this percent is a warning (amber; red from 100). */
    public const CAP_WARN = 90;

    /** Meta's currency table: these have offset 1 (the API amount is already in whole units); every other one is 100. */
    public const ZERO_OFFSET = ['CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'JPY', 'KRW', 'PYG', 'TWD', 'VND'];

    public const STATUS = [
        1 => 'Active', 2 => 'Disabled', 3 => 'Unsettled', 7 => 'Pending risk review', 8 => 'Pending settlement',
        9 => 'In grace period', 100 => 'Pending closure', 101 => 'Closed', 201 => 'Any active', 202 => 'Any closed',
    ];
    /** Red warning: delivery stops or is about to (a failed payment usually shows as grace period or unsettled). */
    public const RED_STATUS = [2, 3, 9];

    public const DISABLE_REASON = [
        1 => 'Ads integrity policy', 2 => 'Ads IP review', 3 => 'Payment risk', 4 => 'Gray account shut down',
        5 => 'Ads AFC review', 6 => 'Business integrity review', 7 => 'Permanently closed', 8 => 'Unused reseller account',
        9 => 'Unused account', 10 => 'Umbrella ad account', 11 => 'Business Manager integrity policy',
        12 => 'Misrepresented ad account', 13 => 'Legal entity de-shared', 14 => 'Thread review', 15 => 'Compromised ad account',
    ];

    public function __construct(private Connections $connections, private Graph $graph)
    {
    }

    /** The stored read, or null when there is none. Never calls Meta. */
    public static function stored(): ?array
    {
        try {
            $v = json_decode((string) AppSetting::get(self::KEY), true);
        } catch (Throwable) {
            return null; // no app_settings table yet
        }

        return is_array($v) ? $v : null;
    }

    public static function fresh(?array $stored): bool
    {
        $at = (int) ($stored['attempted_at'] ?? 0);

        return $at > 0 && $at > now()->subMinutes(self::FRESH_MINUTES)->timestamp;
    }

    /** For the Ad Accounts page: the stored read when fresh, else one read now. No connection = no call. */
    public function forPage(): array
    {
        $stored = self::stored();
        if (!$this->connections->all()) return ['rows' => [], 'notes' => [], 'errors' => [], 'none' => true];
        if (self::fresh($stored)) return $stored;

        return $this->refresh()['data'];
    }

    /** Read only when the stored read is not fresh (the hourly schedule). */
    public function refreshIfStale(): ?array
    {
        if (!$this->connections->all() || self::fresh(self::stored())) return null;

        return $this->refresh()['data'];
    }

    /**
     * Read Meta now, under a lock so concurrent viewers make one read; a caller that does not get the lock gets the
     * stored read. @return array{data: array, ran: bool}
     */
    public function refresh(): array
    {
        $lock = Cache::lock(self::KEY, self::LOCK_SECONDS);
        if (!$lock->get()) return ['data' => self::stored() ?? ['rows' => [], 'notes' => [], 'errors' => []], 'ran' => false];
        try {
            return ['data' => $this->read(), 'ran' => true];
        } finally {
            $lock->release();
        }
    }

    private function read(): array
    {
        $old    = self::stored()['rows'] ?? [];
        $rows   = $notes = $errors = [];
        $unread = []; // account id => why it was not read through that connection
        $now    = now()->toIso8601String();

        foreach ($this->connections->all() as $c) {
            $who   = (string) ($c['fb_user_name'] ?? ('user ' . $c['user_id']));
            $ids   = array_values(array_filter(array_map(fn ($a) => (string) ($a['id'] ?? ''), $c['ad_accounts'] ?? [])));
            $token = $this->connections->token($c);
            if (($c['status'] ?? 'ok') === 'expired' || $token === null) {
                foreach ($ids as $id) $unread[$id][] = "Token expired: {$who} (Reconnect)";
                continue;
            }

            try {
                [$list, $refused] = $this->fetch($token);
            } catch (GraphException $e) {
                if ($e->tokenExpired()) {
                    $this->connections->update($c['ref'], ['status' => 'expired', 'last_error' => $e->getMessage()]);
                    foreach ($ids as $id) $unread[$id][] = "Token expired: {$who} (Reconnect)";
                } else {
                    $errors[] = "{$who}: " . $e->getMessage();
                    foreach ($ids as $id) $unread[$id][] = "Not read through {$who}: " . $e->getMessage();
                }
                continue;
            } catch (Throwable $e) {
                Log::error('MetaAds account health: read failed', ['user' => $c['user_id'], 'error' => Graph::clean($e->getMessage())]);
                $errors[] = "{$who}: read failed";
                foreach ($ids as $id) $unread[$id][] = "Not read through {$who}: read failed";
                continue;
            }
            if ($refused !== null) $notes[] = "Payment method not available for {$who}'s accounts: {$refused}";

            foreach ($list as $a) {
                $id = (string) ($a['account_id'] ?? preg_replace('/^act_/', '', (string) ($a['id'] ?? '')));
                if ($id === '') continue;
                if (isset($rows[$id])) {
                    if (!in_array($who, $rows[$id]['who'], true)) $rows[$id]['who'][] = $who;
                    // A second connection that may see the payment method fills it in.
                    if ($rows[$id]['payment'] === null && ($p = self::payment($a)) !== null) $rows[$id]['payment'] = $p;
                    continue;
                }
                $rows[$id] = self::row($id, $a, $who, $refused !== null, $now);
                foreach (self::missing($a, $refused !== null) as $f) $notes[] = "Not returned by Meta: {$f}";
            }
        }

        // Accounts known to a connection that was not read now: the last good row with its own time, or a bare row.
        $known = $this->connections->accounts();
        foreach ($unread as $id => $why) {
            $id = (string) $id;
            if (isset($rows[$id])) continue;
            $base = $old[$id] ?? self::row($id, ['name' => $known[$id]['name'] ?? ''], '', false, null);
            $rows[$id] = ['who' => $known[$id]['who'] ?? $base['who'] ?? [], 'problem' => implode('; ', array_unique($why))] + $base;
        }

        $names = Connections::names(array_map(fn ($r) => $r['meta_name'] ?? '', $rows));
        foreach ($rows as $id => $r) $rows[$id]['name'] = $names[$id] ?? (string) $id;
        uasort($rows, fn ($a, $b) => [strtolower($a['name']), $a['id']] <=> [strtolower($b['name']), $b['id']]);

        $readAny = (bool) array_filter($rows, fn ($r) => ($r['problem'] ?? null) === null);
        $data = [
            'attempted_at' => now()->timestamp,
            'read_at'      => $readAny ? $now : (self::stored()['read_at'] ?? null),
            'rows'         => $rows,
            'notes'        => array_values(array_unique($notes)),
            'errors'       => $errors,
        ];
        AppSetting::set(self::KEY, json_encode($data));

        return $data;
    }

    /**
     * One call per connection. When it is refused for a reason other than the token or a rate limit (typically the
     * payment method needing MANAGE), the same call is made once more without funding_source_details.
     * @return array{0: list<array>, 1: ?string} the accounts, and why the payment method was dropped (null = it was asked)
     * @throws GraphException
     */
    private function fetch(string $token): array
    {
        $q = ['limit' => 200];
        try {
            return [$this->graph->all('me/adaccounts', $q + ['fields' => self::FIELDS . ',' . self::FUNDING], $token, 1, self::TIMEOUT), null];
        } catch (GraphException $e) {
            if ($e->tokenExpired() || $e->rateLimited()) throw $e;

            return [$this->graph->all('me/adaccounts', $q + ['fields' => self::FIELDS], $token, 1, self::TIMEOUT), $e->getMessage()];
        }
    }

    /** The stored row: Meta's values as given (amounts stay in minor units), checked for shape. */
    private static function row(string $id, array $a, string $who, bool $fundingRefused, ?string $readAt): array
    {
        $int = fn ($v) => is_numeric($v) ? (int) $v : null;
        $minor = fn ($v) => is_scalar($v) && preg_match('/^-?\d{1,18}$/', trim((string) $v)) ? trim((string) $v) : null;
        $currency = is_string($a['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', $a['currency']) ? $a['currency'] : null;

        return [
            'id'             => $id,
            'meta_name'      => is_scalar($a['name'] ?? null) ? mb_substr(trim((string) $a['name']), 0, 200) : '',
            'name'           => '',
            'who'            => $who === '' ? [] : [$who],
            'status'         => $int($a['account_status'] ?? null),
            'disable_reason' => $int($a['disable_reason'] ?? null),
            'currency'       => $currency,
            'balance'        => $minor($a['balance'] ?? null),
            'amount_spent'   => $minor($a['amount_spent'] ?? null),
            'spend_cap'      => $minor($a['spend_cap'] ?? null),
            'payment'        => self::payment($a),
            'funding_refused'=> $fundingRefused,
            'read_at'        => $readAt,
            'problem'        => null,
        ];
    }

    private static function payment(array $a): ?string
    {
        $s = $a['funding_source_details']['display_string'] ?? null;

        return is_scalar($s) && trim((string) $s) !== '' ? mb_substr(trim((string) $s), 0, 100) : null;
    }

    /** @return list<string> the asked fields Meta left out of this account (shown "not available"). */
    private static function missing(array $a, bool $fundingRefused): array
    {
        $labels = ['account_status' => 'status', 'currency' => 'currency', 'balance' => 'balance', 'amount_spent' => 'amount spent',
            'spend_cap' => 'spend cap'];
        $out = [];
        foreach ($labels as $f => $label) if (!array_key_exists($f, $a)) $out[] = $label;
        if (!$fundingRefused && empty($a['funding_source_details']['display_string'])) $out[] = 'payment method';

        return $out;
    }

    // ---- shown on the pages ----

    /** Minor units (Meta's string) → "1,234.56" in the account currency, without floats. null when not given. */
    public static function money(?string $minor, ?string $currency): ?string
    {
        if ($minor === null || $currency === null || !preg_match('/^(-?)(\d+)$/', $minor, $m)) return null;
        [$sign, $digits] = [$m[1], ltrim($m[2], '0') ?: '0'];
        if (in_array($currency, self::ZERO_OFFSET, true)) {
            [$whole, $cents] = [$digits, '00'];
        } else {
            $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
            [$whole, $cents] = [substr($digits, 0, -2), substr($digits, -2)];
        }
        $whole = strrev(implode(',', str_split(strrev($whole), 3)));
        if ($sign === '-' && trim($whole . $cents, '0,') === '') $sign = '';

        return $sign . $whole . '.' . $cents;
    }

    /**
     * Everything the page and the warning need for one stored row.
     * @return array{status: string, reason: ?string, balance: ?string, spent: ?string, cap: ?string, remaining: ?string,
     *   pct: ?float, no_limit: bool, level: ?string, warning: ?string}
     */
    public static function present(array $r): array
    {
        $cur    = $r['currency'] ?? null;
        $status = $r['status'] ?? null;
        $cap    = $r['spend_cap'] ?? null;
        $spent  = $r['amount_spent'] ?? null;
        $noLimit = $cap !== null && (int) $cap <= 0;
        $pct = $remaining = null;
        if ($cap !== null && !$noLimit && $spent !== null) {
            // Rounded down, so 89.99% shows 89.9 and is not a 90% warning.
            $pct = floor(max(0, (int) $spent) / (int) $cap * 1000) / 10;
            $remaining = self::money((string) max(0, (int) $cap - (int) $spent), $cur);
        }

        $level = $warning = null;
        if (in_array($status, self::RED_STATUS, true)) {
            [$level, $warning] = ['red', self::STATUS[$status]];
        } elseif ($pct !== null && $pct >= self::CAP_WARN) {
            [$level, $warning] = [$pct >= 100 ? 'red' : 'amber', 'spend cap ' . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '% used'];
        }

        return [
            'status'    => $status === null ? 'Not available' : (self::STATUS[$status] ?? "Unknown ({$status})"),
            'reason'    => ($r['disable_reason'] ?? 0) > 0 ? (self::DISABLE_REASON[$r['disable_reason']] ?? "Unknown ({$r['disable_reason']})") : null,
            'balance'   => self::money($r['balance'] ?? null, $cur),
            'spent'     => self::money($spent, $cur),
            'cap'       => $noLimit ? null : self::money($cap, $cur),
            'remaining' => $remaining,
            'pct'       => $pct,
            'no_limit'  => $noLimit,
            'level'     => $level,
            'warning'   => $warning,
        ];
    }

    /** @return list<array{id: string, name: string, level: string, warning: string}> red first */
    public static function warnings(?array $stored): array
    {
        $out = [];
        foreach ($stored['rows'] ?? [] as $r) {
            $p = self::present($r);
            if ($p['level'] !== null) $out[] = ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'level' => $p['level'], 'warning' => $p['warning']];
        }
        usort($out, fn ($a, $b) => ($a['level'] === 'red' ? 0 : 1) <=> ($b['level'] === 'red' ? 0 : 1));

        return $out;
    }
}
