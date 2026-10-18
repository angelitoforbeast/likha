<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Services\BotcakeChatGuard;
use App\Services\PageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * /api/botcake/chat: ang JSON API block ng Botcake (sa Default reply flow ng page) ay nagpapasa ng mensahe ng customer.
 * JSON, form o query params; ang pangalan ng param ay case-insensitive at may mga alias (para sa lumang block). Ang
 * harang ay sa BotcakeChatGuard (IP, key kada page, PSID); ang tanging tawag sa Botcake ay ang isang beses na pagsuri ng
 * bagong PSID (5 s). Walang ipinapadala pabalik sa customer.
 */
class BotcakeChatController extends Controller
{
    /** Ang field => ang mga pangalang tinatanggap (lowercase), una ang pangunahin. */
    private const FIELDS = [
        'page_id'    => ['page_id'],
        'psid'       => ['psid', 'user.id', 'user_id', 'fb_id', 'id'], // sa GET/form ay user_id ang user.id (PHP)
        'name'       => ['name', 'full_name', 'user.full_name', 'user_full_name'],
        'message'    => ['message', 'last_text_input', 'text', 'last_input', 'last_user_input'],
        'message_id' => ['message_id'],
    ];

    private const MAX = ['page_id' => 64, 'psid' => 64, 'name' => 255, 'message' => 5000, 'message_id' => 191];

    /** Ang payload ay may hangganan: hanggang 50 param, bawat value hanggang 2000 letra. */
    private const PAYLOAD_KEYS = 50;
    private const PAYLOAD_VALUE = 2000;

    /** Gaano katagal (segundo) itinuturing na ulit ang parehong page + PSID + mensahe. */
    private const DUP_SECONDS = 10;

    /** Pinakamalaking body na binabasa (bytes); ang lampas ay 413. */
    private const MAX_BODY = 65536;

    /** Ang mga field na may sariling column at hindi inuulit sa payload. */
    private const COLUMN_FIELDS = ['page_id', 'psid', 'name', 'message'];

    /**
     * Ang pagkakasunod: (1) IP ng Botcake (log: itinatala at binibilang; enforce: 403); (2) ang header key ay kilala (ang
     * chat.key ng isang page, o ang shared habang naka-on), bago basahin ang body; (3) body hanggang 64 KB (413); (4) ang
     * key mula sa param kung walang header; (5) ang page ay atin (page_id o pangalan), kung hindi 401; (6) ang key ay sa
     * page na iyon (hash_equals), kung hindi 401; (7) ang PSID ay nasa page (botcake_psids o Botcake), kung hindi:
     * {"ok":true} at walang iniimbak. Ang bawat pagtanggi ay binibilang (BotcakeChatGuard).
     */
    public function receive(Request $request, BotcakeChatGuard $guard): JsonResponse
    {
        $refuse = function (string $reason, int $status = 401) use ($guard) {
            $guard->count($reason);
            return response()->json(['ok' => false], $status);
        };

        // (1) Ang IP: ip() (ayon sa trusted proxies) at ang REMOTE_ADDR, para makita sa log kung alin ang totoo.
        $ip = (string) $request->ip();
        if (!$guard->ipListed($ip)) {
            Log::warning('botcake chat: IP not on the list', ['ip' => $ip, 'remote_addr' => (string) $request->server('REMOTE_ADDR'), 'mode' => $guard->enforcesIp() ? 'enforce' : 'log']);
            if ($guard->enforcesIp()) return $refuse('bad_ip', 403);
            $guard->count('bad_ip');
        }

        // (2) Ang header key muna, bago basahin ang body.
        $given = (string) $request->header('X-Chat-Key');
        if ($given !== '' && !$guard->knownKey($given)) return $refuse('bad_key');

        // (3) Ang body na lampas 64 KB ay hindi binabasa (ang isang mensahe ng customer ay mas maliit nang malayo).
        if ((int) $request->server('CONTENT_LENGTH') > self::MAX_BODY || strlen((string) $request->getContent()) > self::MAX_BODY) {
            return response()->json(['ok' => false], 413);
        }

        $data = $request->all();
        // Ang JSON na hindi application/json ang Content-Type (hal. text/plain) ay binabasa rin; maaaring nandito ang key.
        if (!$request->isJson() && is_array($raw = json_decode((string) $request->getContent(), true))) $data = $raw + $data;

        // (4) Walang header: ang param na "key" (anuman ang laki ng titik), para sa lumang GET block.
        if ($given === '') {
            foreach ($data as $k => $v) {
                if (mb_strtolower(trim((string) $k)) === 'key' && is_string($v)) {
                    $given = $v;
                    break;
                }
            }
            if (!$guard->knownKey($given)) return $refuse('bad_key');
        }

        $values = [];
        $byName = [];
        foreach ($data as $k => $v) {
            $name = mb_strtolower(trim((string) $k));
            if ($name === 'key') continue; // ang key ay hindi kailanman iniimbak
            $s = $this->text($v);
            $byName[$name] ??= $s;
            $values[] = [$k, $name, $s];
        }

        $f = $this->fields($byName, $used);
        // Walang PSID at walang mensahe (hal. ang Send ng Botcake na walang laman): sumasagot, walang iniimbak.
        if ($f['psid'] === null && $f['message'] === null) return response()->json(['ok' => true]);

        // (5) Ang page: page_id ({{page_id}} ng Botcake, o fixed text sa lumang block) o page (pangalan). Atin lang.
        [$f['page_id'], $pageName] = $this->page($f['page_id'], trim((string) ($byName['page'] ?? '')));
        if ($pageName === null || $f['page_id'] === null) return $refuse('unknown_page');

        // (6) Ang key ay dapat sa page na ito.
        if (!$guard->keyFitsPage($pageName, $given)) return $refuse('bad_key');

        // (7) Ang PSID ay dapat nasa page: ang key na kinopya sa ibang Botcake account ay may PSID na wala sa atin.
        if ($f['psid'] === null || $guard->checkPsid($pageName, $f['page_id'], $f['psid'], $f['name']) === 'not_on_page') {
            $guard->count('psid_not_found');
            return response()->json(['ok' => true]);
        }

        // Ang payload: ang ibang param lang; ang page_id, psid, name at message ay nasa sariling column na (hindi inuulit).
        $payload = [];
        foreach ($values as [$k, $name, $s]) {
            if (isset($used[$name]) || count($payload) >= self::PAYLOAD_KEYS) continue;
            $payload[$this->text(mb_substr((string) $k, 0, 100))] = $s === null ? null : mb_substr($s, 0, self::PAYLOAD_VALUE);
        }

        if (!$this->isDuplicate($f)) {
            BotcakeChat::create($f + ['page_name' => $pageName, 'payload' => $payload, 'received_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /** Ang value bilang wastong UTF-8 na string: ang array ay JSON; ang sirang byte ay pinapalitan (hindi 500 sa pag-imbak). */
    private function text(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return mb_scrub($s, 'UTF-8');
    }

    /**
     * @param array<string, ?string> $byName lowercase na pangalan => value
     * @param array<string, true>|null $used ang mga pangalan (lowercase) ng COLUMN_FIELDS na nasa column na: ang
     *        ginamit, at ang alias na walang laman
     * @return array<string, ?string> ang mga kilalang field (may alias), pinaikli ayon sa column
     */
    private function fields(array $byName, ?array &$used = null): array
    {
        $out = [];
        $used = [];
        foreach (self::FIELDS as $field => $names) {
            $out[$field] = null;
            $column = in_array($field, self::COLUMN_FIELDS, true);
            foreach ($names as $name) {
                if (!array_key_exists($name, $byName)) continue;
                $v = trim((string) $byName[$name]);
                if ($column && ($v === '' || $out[$field] === null)) $used[$name] = true;
                if ($v !== '' && $out[$field] === null) $out[$field] = mb_substr($v, 0, self::MAX[$field]);
            }
        }

        return $out;
    }

    private function isDuplicate(array $f): bool
    {
        if ($f['message_id'] !== null && BotcakeChat::where('message_id', $f['message_id'])->exists()) return true;

        // Eksaktong text, sa PHP: sa MySQL ay case-insensitive ang "=" (ang "ok" at "OK" ay magkaibang mensahe).
        return BotcakeChat::where('page_id', $f['page_id'])->where('psid', $f['psid'])
            ->where('received_at', '>=', now()->subSeconds(self::DUP_SECONDS))->pluck('message')
            ->contains(fn ($m) => $m === $f['message']);
    }

    /**
     * Ang page: ayon sa botcake.page_id sa page_settings, at kung hindi tugma, ayon sa pangalan ng page (trim at
     * case-insensitive, gaya ng page_settings), saka kinukuha ang botcake.page_id nito. Walang tugma: [ang page_id, null].
     * @return array{0: ?string, 1: ?string} [page_id, page_name]
     */
    private function page(?string $pageId, string $page): array
    {
        $map = BotcakeChat::pageMap(); // naka-cache: walang query kada mensahe
        if ($pageId !== null && isset($map[$pageId])) return [$pageId, $map[$pageId]];
        if ($page === '') return [$pageId, null];

        $settings = app(PageSettings::class);
        $key = $settings->pageKey($page);
        foreach ($map as $id => $name) {
            if ($settings->pageKey($name) === $key) return [mb_substr((string) $id, 0, self::MAX['page_id']), $name];
        }
        // Ang page na nasa page_settings pero walang botcake.page_id.
        $name = $settings->pageQuery(mb_substr($page, 0, 255))->where('active', true)->orderByDesc('id')->value('page_name');
        if ($name === null) return [$pageId, null];
        $id = trim((string) $settings->get($name, 'botcake', 'page_id'));

        return [$id !== '' ? mb_substr($id, 0, self::MAX['page_id']) : $pageId, $name];
    }
}
