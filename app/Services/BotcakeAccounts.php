<?php

namespace App\Services;

use App\Models\PageSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Botcake Accounts (spec 272): one Botcake account-level Access Token per row set, kept in page_settings of the reserved
 * page "_all" (ChatblastSetupCheck::ALL_PAGE, the same place as the AI key pool), provider botcake_accounts, so every
 * change has the usual history (who and when) and no new table is needed. Each account <id> (8 hex) has:
 * - a<id>.token: the Access Token, encrypted (is_secret). On Remove the value of every row of it, history included, is
 *   nulled: the secret is gone, not only hidden.
 * - a<id>.info: JSON {label, user_id (Botcake's own user id from the JWT), hash (HMAC of the token, to recognise the same
 *   token when the JWT has no user id), added_by, added_at, updated_by, updated_at}.
 * - a<id>.check: JSON {ok, at, reason, pages}: the last check of the token against Botcake.
 * No method here returns the account token to a caller outside the server, and nothing here logs it: the token is only
 * sent to botcake.io in the user-access-token header. Botcake's answers are data: they are only matched by page id and
 * shown escaped.
 */
class BotcakeAccounts
{
    public const PAGE = ChatblastSetupCheck::ALL_PAGE;

    public const PROVIDER = 'botcake_accounts';

    public const BASE = BotcakeClient::BASE;

    public const MAX_LABEL = 80;

    public const MAX_TOKEN = 4000;

    /** GET /pages answers 25 per call; 100 calls (2,500 pages) is far above any account and stops a cursor that never ends. */
    public const MAX_PAGE_CALLS = 100;

    public const REFUSED = 'Botcake did not accept this token.';

    public function __construct(private PageSettings $settings) {}

    /** Anything that looks like a JWT (eyJ…) is replaced, so a token pasted into or echoed by a message never reaches a log or a screen. */
    public static function redact(string $text, ?string $token = null): string
    {
        if ($token !== null && $token !== '') {
            $text = str_replace($token, '[redacted]', $text);
        }

        return (string) preg_replace('/eyJ[A-Za-z0-9_\-]*(\.[A-Za-z0-9_\-]*){0,2}/', '[redacted]', $text);
    }

    private static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    /** Botcake's own user id from the JWT payload (only used to recognise the same account added twice), or null. */
    public static function botcakeUserId(#[\SensitiveParameter] string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/').str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $payload = is_string($json) ? json_decode($json, true) : null;
        $id = is_array($payload) ? ($payload['user_id'] ?? null) : null;

        return is_scalar($id) && preg_match('/^[A-Za-z0-9\-]{1,64}$/', (string) $id) ? (string) $id : null;
    }

    public static function labelError(mixed $label): ?string
    {
        if (! is_string($label) || trim($label) === '') {
            return 'Give the account a label (e.g. "Marketing A – Botcake").';
        }
        if (! mb_check_encoding($label, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $label)) {
            return 'Label: one line, no control characters.';
        }
        if (mb_strlen(trim($label)) > self::MAX_LABEL) {
            return 'Label: '.self::MAX_LABEL.' characters at most.';
        }

        return null;
    }

    public static function tokenError(#[\SensitiveParameter] mixed $token): ?string
    {
        if (! is_string($token) || trim($token) === '') {
            return 'Paste the Access Token.';
        }
        $token = trim($token);
        if (preg_match('/\s/', $token)) {
            return 'Paste the token on one line, without spaces.';
        }
        if (strlen($token) > self::MAX_TOKEN) {
            return 'This is too long to be an Access Token.';
        }
        if (preg_match('/[^\x21-\x7E]/', $token)) {
            return 'The token has characters that a token cannot have.';
        }
        if (strlen($token) < 20) {
            return 'This is too short to be an Access Token.';
        }

        return null;
    }

    // ---- Botcake calls (no retry: a person is waiting, and a 4xx is not retried) ----

    private static function request(#[\SensitiveParameter] string $token)
    {
        return Http::withHeaders(['user-access-token' => $token])->acceptJson()->connectTimeout(10)->timeout(30);
    }

    /**
     * Why a Botcake answer is not usable: network, rate limit, Botcake error, or the token refused (Botcake's message
     * when it has one). @return array{kind: string, reason: string}
     */
    private static function failure(?Response $r, #[\SensitiveParameter] string $token): array
    {
        if ($r === null) {
            return ['kind' => 'network', 'reason' => 'Could not reach Botcake (network error or timeout). Try again.'];
        }
        $status = $r->status();
        if ($status === 429) {
            return ['kind' => 'rate_limited', 'reason' => 'Botcake is limiting requests right now (HTTP 429). Try again in a minute.'];
        }
        if ($status >= 500) {
            return ['kind' => 'error', 'reason' => 'Botcake had an error (HTTP '.$status.'). Try again later.'];
        }
        $message = mb_substr(self::redact(BotcakeClient::messageText($r->json()), $token), 0, 300);

        return ['kind' => 'refused', 'reason' => $message !== '' ? 'Botcake: '.$message : self::REFUSED];
    }

    /**
     * POST /integration_page/list_access_token: the current page token of every page of the account.
     *
     * @return array{ok: bool, kind: ?string, reason: ?string, tokens: array<string, string>} page id => page token
     */
    public function pageTokens(#[\SensitiveParameter] string $token): array
    {
        try {
            $r = self::request($token)->send('POST', self::BASE.'/integration_page/list_access_token');
        } catch (ConnectionException) {
            $r = null;
        }
        $body = $r?->json();
        if ($r === null || $r->status() !== 200 || ! is_array($body) || ! array_is_list($body)) {
            return ['ok' => false, 'tokens' => []] + self::failure($r, $token);
        }

        $tokens = [];
        foreach ($body as $row) {
            if (! is_array($row) || ! is_scalar($row['page_id'] ?? null) || ! is_string($row['public_token'] ?? null)) {
                continue;
            }
            $id = trim((string) $row['page_id']);
            if ($id !== '' && trim($row['public_token']) !== '') {
                $tokens[$id] = trim($row['public_token']);
            }
        }

        return ['ok' => true, 'kind' => null, 'reason' => null, 'tokens' => $tokens];
    }

    /**
     * GET /pages, following meta.next_cursor until null.
     *
     * @return array{ok: bool, kind: ?string, reason: ?string, pages: array<int, array{name: string, page_id: string, platform: string, role: string}>}
     */
    public function pages(#[\SensitiveParameter] string $token): array
    {
        $pages = [];
        $cursor = null;
        $seen = [];
        for ($i = 0; $i < self::MAX_PAGE_CALLS; $i++) {
            try {
                $r = self::request($token)->get(self::BASE.'/pages', $cursor !== null ? ['cursor' => $cursor] : []);
            } catch (ConnectionException) {
                $r = null;
            }
            $body = $r?->json();
            if ($r === null || $r->status() !== 200 || ! is_array($body) || ($body['success'] ?? null) !== true || ! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
                return ['ok' => false, 'pages' => []] + self::failure($r, $token);
            }
            foreach ($body['data'] as $p) {
                if (! is_array($p) || ! is_scalar($p['page_id'] ?? null) || trim((string) $p['page_id']) === '') {
                    continue;
                }
                $pages[] = [
                    'name' => mb_substr(is_string($p['name'] ?? null) ? trim($p['name']) : '', 0, 255),
                    'page_id' => trim((string) $p['page_id']),
                    'platform' => mb_substr(is_string($p['platform'] ?? null) ? $p['platform'] : '', 0, 40),
                    'role' => mb_substr(is_string($p['role_in_page'] ?? null) ? $p['role_in_page'] : '', 0, 40),
                ];
            }
            $next = $body['meta']['next_cursor'] ?? null;
            if (! is_scalar($next) || (string) $next === '' || isset($seen[(string) $next])) {
                break;
            }
            $seen[$cursor = (string) $next] = true;
        }

        return ['ok' => true, 'kind' => null, 'reason' => null, 'pages' => $pages];
    }

    // ---- Stored accounts ----

    /**
     * The saved accounts, oldest first. token = '' when it cannot be decrypted (the app key changed).
     *
     * @return array<string, array{id: string, label: string, user_id: ?string, hash: string, added_by: ?int, added_at: ?string, updated_by: ?int, updated_at: ?string, check: array, token: string}>
     */
    private function stored(): array
    {
        $raw = $this->settings->all(self::PAGE, self::PROVIDER);
        $out = [];
        foreach ($raw as $k => $v) {
            if (! preg_match('/^a([a-f0-9]{8})\.info$/', $k, $m)) {
                continue;
            }
            $info = json_decode($v, true);
            if (! is_array($info)) {
                continue;
            }
            $id = $m[1];
            $check = json_decode((string) ($raw['a'.$id.'.check'] ?? ''), true);
            $out[$id] = [
                'id' => $id, 'label' => (string) ($info['label'] ?? ''), 'user_id' => isset($info['user_id']) ? (string) $info['user_id'] : null,
                'hash' => (string) ($info['hash'] ?? ''), 'added_by' => isset($info['added_by']) ? (int) $info['added_by'] : null,
                'added_at' => $info['added_at'] ?? null, 'updated_by' => isset($info['updated_by']) ? (int) $info['updated_by'] : null,
                'updated_at' => $info['updated_at'] ?? null, 'check' => is_array($check) ? $check : [], 'token' => (string) ($raw['a'.$id.'.token'] ?? ''),
            ];
        }
        uasort($out, fn ($a, $b) => strcmp((string) $a['added_at'], (string) $b['added_at']));

        return $out;
    }

    /**
     * The saved accounts for the screen: everything but the token (and its hash).
     *
     * @return array<string, array{id: string, label: string, user_id: ?string, added_by: ?int, added_at: ?string, updated_by: ?int, updated_at: ?string, check: array, readable: bool}>
     */
    public function all(): array
    {
        return array_map(fn ($a) => array_diff_key($a, ['token' => 1, 'hash' => 1]) + ['readable' => $a['token'] !== ''], $this->stored());
    }

    public function find(string $id): ?array
    {
        return preg_match('/^[a-f0-9]{8}$/', $id) ? ($this->all()[$id] ?? null) : null;
    }

    /** The account's token for the calls, or '' when it cannot be decrypted; null = no such account. */
    private function tokenOf(string $id): ?string
    {
        return preg_match('/^[a-f0-9]{8}$/', $id) ? ($this->stored()[$id]['token'] ?? null) : null;
    }

    private function write(string $id, string $part, array $value, ?int $userId): void
    {
        $this->settings->set(self::PAGE, self::PROVIDER, 'a'.$id.'.'.$part, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false, $userId, true);
    }

    /** Record a check: OK with the page count, or failed with the reason (the last page count stays). */
    private function recordCheck(string $id, array $res, ?int $userId, ?array $previous = null): void
    {
        $this->write($id, 'check', [
            'ok' => $res['ok'], 'at' => now()->toIso8601String(), 'reason' => $res['ok'] ? null : $res['reason'],
            'pages' => $res['ok'] ? count($res['tokens']) : ($previous['pages'] ?? null),
        ], $userId);
    }

    /**
     * Add an account (S1). Botcake must accept the token on both calls before anything is saved. A token of a Botcake user
     * (or, without a user id, the same token) already saved replaces that account's token: status "updated".
     *
     * @return array{error: ?string, status: ?string, id: ?string, pages: int}
     */
    public function add(mixed $label, #[\SensitiveParameter] mixed $token, ?int $userId): array
    {
        $fail = fn (string $e) => ['error' => $e, 'status' => null, 'id' => null, 'pages' => 0];
        if (($e = self::labelError($label)) !== null) {
            return $fail($e);
        }
        if (($e = self::tokenError($token)) !== null) {
            return $fail($e);
        }
        $label = trim((string) $label);
        $token = trim((string) $token);

        $tokens = $this->pageTokens($token);
        if (! $tokens['ok']) {
            return $fail($tokens['reason']);
        }
        $pages = $this->pages($token);
        if (! $pages['ok']) {
            return $fail($pages['reason']);
        }

        $botcakeUser = self::botcakeUserId($token);
        $hash = self::hash($token);
        $same = null;
        foreach ($this->stored() as $a) {
            if (($botcakeUser !== null && $a['user_id'] === $botcakeUser) || ($a['hash'] !== '' && hash_equals($a['hash'], $hash))) {
                $same = $a;
                break;
            }
        }
        $id = $same['id'] ?? bin2hex(random_bytes(4));
        $now = now()->toIso8601String();
        $info = $same
            ? ['label' => $label, 'user_id' => $botcakeUser ?? $same['user_id'], 'hash' => $hash, 'added_by' => $same['added_by'], 'added_at' => $same['added_at'], 'updated_by' => $userId, 'updated_at' => $now]
            : ['label' => $label, 'user_id' => $botcakeUser, 'hash' => $hash, 'added_by' => $userId, 'added_at' => $now, 'updated_by' => null, 'updated_at' => null];

        DB::transaction(function () use ($id, $token, $info, $tokens, $userId) {
            $this->settings->set(self::PAGE, self::PROVIDER, 'a'.$id.'.token', $token, true, $userId, true);
            $this->write($id, 'info', $info, $userId);
            $this->recordCheck($id, $tokens, $userId);
        });
        Log::info('BotcakeAccounts: '.($same ? 'updated' : 'added'), ['account' => $id, 'pages' => count($tokens['tokens']), 'by' => $userId]);

        return ['error' => null, 'status' => $same ? 'updated' : 'added', 'id' => $id, 'pages' => count($tokens['tokens'])];
    }

    /** Remove (S3): every active row of the account is closed (history kept) and the token's value is nulled in every row. */
    public function remove(string $id, ?int $userId): ?string
    {
        if ($this->find($id) === null) {
            return 'This account is not saved.';
        }
        DB::transaction(function () use ($id, $userId) {
            foreach ($this->settings->pageQuery(self::PAGE)->where('provider', self::PROVIDER)->where('key', 'like', 'a'.$id.'.%')->where('active', true)->get() as $row) {
                $this->settings->deactivate($row, $userId);
            }
            $this->settings->pageQuery(self::PAGE)->where('provider', self::PROVIDER)->where('key', 'a'.$id.'.token')->update(['value' => null, 'updated_at' => now()]);
        });
        PageSettings::forget(self::PAGE);
        Log::info('BotcakeAccounts: removed', ['account' => $id, 'by' => $userId]);

        return null;
    }

    /**
     * The Likha pages with a Botcake page id: page id => [Likha page name => Likha token ('' = none)].
     *
     * @return array<string, array<string, string>>
     */
    private function likhaPages(): array
    {
        $rows = PageSetting::where('provider', 'botcake')->where('key', 'page_id')->where('active', true)
            ->where('page_name', '!=', self::PAGE)->orderBy('id')->get(['page_name', 'value']);
        $this->settings->preload($rows->pluck('page_name')->all());
        $out = [];
        foreach ($rows as $r) {
            $pid = trim((string) $r->value);
            if ($pid === '') {
                continue;
            }
            $out[$pid][(string) $r->page_name] = trim((string) $this->settings->get((string) $r->page_name, 'botcake', 'token'));
        }

        return $out;
    }

    /** match | differs | missing (Likha has no token) | no_botcake_token (Botcake gave none for the page). */
    private static function compare(string $likha, ?string $botcake): string
    {
        if ($botcake === null || $botcake === '') {
            return 'no_botcake_token';
        }
        if ($likha === '') {
            return 'missing';
        }

        return hash_equals($botcake, $likha) ? 'match' : 'differs';
    }

    /**
     * View pages (S4): every page of the account from GET /pages with its Likha status, computed here (no token leaves the
     * server). Sorted: differs and missing first, then matches, then not in Likha.
     *
     * @return array{error: ?string, rows: array<int, array{name: string, page_id: string, role: string, likha: ?string, status: string}>, counts: array<string, int>}
     */
    public function pageList(string $id, ?int $userId = null): array
    {
        $account = $this->find($id);
        $token = $this->tokenOf($id);
        if ($account === null || $token === null) {
            return ['error' => 'This account is not saved.', 'rows' => [], 'counts' => []];
        }
        if ($token === '') {
            return ['error' => 'This token cannot be decrypted (the app key changed). Remove the account and add it again.', 'rows' => [], 'counts' => []];
        }

        $tokens = $this->pageTokens($token);
        if (! $tokens['ok']) {
            if ($tokens['kind'] === 'refused') {
                $this->recordCheck($id, $tokens, $userId, $account['check']);
            }

            return ['error' => $tokens['reason'], 'rows' => [], 'counts' => []];
        }
        $pages = $this->pages($token);
        if (! $pages['ok']) {
            return ['error' => $pages['reason'], 'rows' => [], 'counts' => []];
        }

        $likha = $this->likhaPages();
        $rows = [];
        foreach ($pages['pages'] as $p) {
            $base = ['name' => $p['name'], 'page_id' => $p['page_id'], 'role' => $p['role']];
            if (! isset($likha[$p['page_id']])) {
                $rows[] = $base + ['likha' => null, 'status' => 'none'];

                continue;
            }
            foreach ($likha[$p['page_id']] as $name => $likhaToken) {
                $rows[] = $base + ['likha' => $name, 'status' => self::compare($likhaToken, $tokens['tokens'][$p['page_id']] ?? null)];
            }
        }
        $rank = ['differs' => 0, 'missing' => 0, 'no_botcake_token' => 1, 'match' => 2, 'none' => 3];
        usort($rows, fn ($a, $b) => [$rank[$a['status']], mb_strtolower($a['likha'] ?? $a['name'])] <=> [$rank[$b['status']], mb_strtolower($b['likha'] ?? $b['name'])]);
        $counts = array_fill_keys(array_keys($rank), 0);
        foreach ($rows as $r) {
            $counts[$r['status']]++;
        }

        return ['error' => null, 'rows' => $rows, 'counts' => $counts + ['pages' => count($pages['pages'])]];
    }

    /**
     * Update tokens in Likha (S5): writes the Botcake page token into botcake.token of every Likha page whose Botcake page
     * id is in this account and whose token differs or is missing. Never adds a page, never touches another setting.
     * Botcake failure = nothing written.
     *
     * @return array{error: ?string, updated: array<int, string>}
     */
    public function updateTokens(string $id, ?int $userId): array
    {
        $account = $this->find($id);
        $token = $this->tokenOf($id);
        if ($account === null || $token === null) {
            return ['error' => 'This account is not saved.', 'updated' => []];
        }
        if ($token === '') {
            return ['error' => 'This token cannot be decrypted (the app key changed). Remove the account and add it again.', 'updated' => []];
        }

        $tokens = $this->pageTokens($token);
        if (! $tokens['ok']) {
            if ($tokens['kind'] === 'refused') {
                $this->recordCheck($id, $tokens, $userId, $account['check']);
            }

            return ['error' => $tokens['reason'], 'updated' => []];
        }

        $todo = [];
        foreach ($this->likhaPages() as $pid => $pages) {
            $botcake = $tokens['tokens'][$pid] ?? null;
            foreach ($pages as $name => $likhaToken) {
                if (in_array(self::compare($likhaToken, $botcake), ['differs', 'missing'], true)) {
                    $todo[$name] = $botcake;
                }
            }
        }
        $source = mb_substr('botcake account: '.$account['label'], 0, 200);
        DB::transaction(function () use ($todo, $userId, $source) {
            foreach ($todo as $name => $pageToken) {
                $this->settings->set((string) $name, 'botcake', 'token', $pageToken, true, $userId, false, $source);
            }
        });
        $names = array_map('strval', array_keys($todo));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        Log::info('BotcakeAccounts: page tokens updated', ['account' => $id, 'pages' => $names, 'by' => $userId]);

        return ['error' => null, 'updated' => $names];
    }

    /**
     * The daily check (S7): list_access_token for every account, recording OK (with the page count) or failed with the
     * reason. Writes no page token. @return array{ok: int, failed: int}
     */
    public function checkAll(): array
    {
        $counts = ['ok' => 0, 'failed' => 0];
        foreach ($this->stored() as $a) {
            $res = $a['token'] === ''
                ? ['ok' => false, 'kind' => 'unreadable', 'reason' => 'The token cannot be decrypted (the app key changed).', 'tokens' => []]
                : $this->pageTokens($a['token']);
            $this->recordCheck($a['id'], $res, null, $a['check']);
            $counts[$res['ok'] ? 'ok' : 'failed']++;
            if (! $res['ok']) {
                Log::warning('BotcakeAccounts: check failed', ['account' => $a['id'], 'reason' => self::redact((string) $res['reason'], $a['token'])]);
            }
        }

        return $counts;
    }
}
