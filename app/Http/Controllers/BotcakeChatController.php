<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/botcake/chat: ang JSON API block ng Botcake (sa Default reply flow ng page) ay nagpapasa ng mensahe ng customer.
 * JSON, form o query params; ang pangalan ng param ay case-insensitive at may mga alias, dahil hindi pa tiyak ang mga
 * variable ng Botcake. Iniimbak lang: walang tawag sa Botcake o J&T, at walang ipinapadala pabalik sa customer.
 */
class BotcakeChatController extends Controller
{
    /** Ang field => ang mga pangalang tinatanggap (lowercase), una ang pangunahin. */
    private const FIELDS = [
        'page_id'    => ['page_id'],
        'psid'       => ['psid', 'user_id', 'fb_id'],
        'name'       => ['name', 'full_name'],
        'message'    => ['message', 'text', 'last_input', 'last_user_input'],
        'message_id' => ['message_id'],
    ];

    private const MAX = ['page_id' => 64, 'psid' => 64, 'name' => 255, 'message' => 5000, 'message_id' => 191];

    /** Ang payload ay may hangganan: hanggang 50 param, bawat value hanggang 2000 letra. */
    private const PAYLOAD_KEYS = 50;
    private const PAYLOAD_VALUE = 2000;

    /** Gaano katagal (segundo) itinuturing na ulit ang parehong page + PSID + mensahe. */
    private const DUP_SECONDS = 10;

    public function receive(Request $request): JsonResponse
    {
        $data = $request->all();
        // Ang key muna (header, o param na "key" anuman ang laki ng titik), bago basahin ang iba ng body.
        $given = (string) $request->header('X-Chat-Key');
        if ($given === '') {
            foreach ($data as $k => $v) {
                if (mb_strtolower(trim((string) $k)) === 'key' && is_string($v)) {
                    $given = $v;
                    break;
                }
            }
        }
        $expected = (string) config('services.botcake.chat_key');
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) return response()->json(['ok' => false], 401);

        // Ang JSON na hindi application/json ang Content-Type (hal. text/plain) ay binabasa rin.
        if (!$request->isJson() && is_array($raw = json_decode((string) $request->getContent(), true))) $data = $raw + $data;

        $payload = [];
        $byName = [];
        foreach ($data as $k => $v) {
            $name = mb_strtolower(trim((string) $k));
            if ($name === 'key') continue; // ang key ay hindi kailanman iniimbak
            $s = $this->text($v);
            $byName[$name] ??= $s;
            if (count($payload) < self::PAYLOAD_KEYS) $payload[$this->text(mb_substr((string) $k, 0, 100))] = $s === null ? null : mb_substr($s, 0, self::PAYLOAD_VALUE);
        }

        $f = $this->fields($byName);
        // Walang PSID at walang mensahe (hal. ang Send ng Botcake na walang laman): sumasagot, walang iniimbak.
        if ($f['psid'] === null && $f['message'] === null) return response()->json(['ok' => true]);

        if (!$this->isDuplicate($f)) {
            BotcakeChat::create($f + ['page_name' => $this->pageName($f['page_id']), 'payload' => $payload, 'received_at' => now()]);
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
     * @return array<string, ?string> ang mga kilalang field (may alias), pinaikli ayon sa column
     */
    private function fields(array $byName): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $names) {
            $out[$field] = null;
            foreach ($names as $name) {
                $v = trim((string) ($byName[$name] ?? ''));
                if ($v !== '') {
                    $out[$field] = mb_substr($v, 0, self::MAX[$field]);
                    break;
                }
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

    /** Ang page na may botcake.page_id na ito sa page_settings (null kapag wala). */
    private function pageName(?string $pageId): ?string
    {
        if ($pageId === null) return null;

        return PageSetting::where('provider', 'botcake')->where('key', 'page_id')->where('active', true)
            ->where('value', $pageId)->orderByDesc('id')->value('page_name');
    }
}
