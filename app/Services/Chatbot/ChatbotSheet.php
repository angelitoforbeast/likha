<?php

namespace App\Services\Chatbot;

use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Illuminate\Support\Facades\Cache;

/**
 * Ang Google Sheet append ng chatbot, gamit ang umiiral na Google client ng Likha (storage/app/credentials.json, gaya
 * ng BotcakePsidSheet). Hiwalay para mapalitan ng fake sa tests: walang tawag sa Google mula sa test.
 */
class ChatbotSheet
{
    /** Ang service account na kailangang Editor ng sheet (ipinapakita sa editor kapag tumanggi ang Google). */
    public const SHARE_EMAIL = 'gsheet-to-database@banded-arch-432107-c9.iam.gserviceaccount.com';
    public const CACHE_SECONDS = 300; // quota ng Google API
    public const MAX_TEXT = 200;      // pangalan, tab at header
    public const MAX_HEADERS = 100;
    public const MAX_TABS = 200;

    private ?Sheets $service = null;

    protected function service(): Sheets
    {
        if ($this->service) return $this->service;

        $client = new GoogleClient();
        $client->setApplicationName('Likha Chatbot');
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        return $this->service = new Sheets($client);
    }

    /**
     * Isang row sa dulo ng tab. USER_ENTERED, kaya ang cell na may variable ay may apostrophe na kapag mukhang formula
     * (ChatbotVars::sheetCell). Nagtatapon kapag tumanggi ang Google. Walang return type: ang mga lumang fake (void)
     * ay tugma pa rin.
     * @param array<int, string> $row
     * @return ?string ang updatedRange (hal. 'All Orders'!A123:J123)
     */
    public function append(string $spreadsheetId, string $tab, array $row)
    {
        $res = $this->service()->spreadsheets_values->append($spreadsheetId, self::quote($tab) . '!A1',
            new ValueRange(['values' => [array_values($row)]]),
            ['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS']);

        return $res->getUpdates()?->getUpdatedRange();
    }

    /**
     * Ang pangalan at mga tab (ayon sa ayos sa sheet), naka-cache 5 minuto; $refresh ay hindi gumagamit ng cache.
     * Ang pagtanggi ng Google ay itinatapon at hindi naka-cache.
     * @return array{title: string, tabs: array<int, array{title: string, gid: int}>}
     */
    public function info(string $spreadsheetId, bool $refresh = false): array
    {
        $key = 'chatbot-sheet:info:' . $spreadsheetId;
        if (!$refresh && is_array($hit = Cache::get($key))) return $hit;

        $raw = $this->fetchInfo($spreadsheetId);
        $tabs = collect($raw['tabs'] ?? [])->sortBy('index')->take(self::MAX_TABS)
            ->map(fn ($t) => ['title' => mb_substr((string) $t['title'], 0, self::MAX_TEXT), 'gid' => (int) $t['gid']])->values()->all();
        $info = ['title' => mb_substr((string) ($raw['title'] ?? ''), 0, self::MAX_TEXT), 'tabs' => $tabs];
        Cache::put($key, $info, self::CACHE_SECONDS);

        return $info;
    }

    /**
     * Ang row 1 ng tab (hanggang 100 column, bawat isa hanggang 200 letra), naka-cache 5 minuto kada tab.
     * @return array<int, string>
     */
    public function headers(string $spreadsheetId, string $tab, bool $refresh = false): array
    {
        $key = 'chatbot-sheet:headers:' . $spreadsheetId . ':' . sha1($tab);
        if (!$refresh && is_array($hit = Cache::get($key))) return $hit;

        $headers = array_map(fn ($h) => mb_substr(is_scalar($h) ? (string) $h : '', 0, self::MAX_TEXT),
            array_slice(array_values($this->fetchHeaders($spreadsheetId, $tab)), 0, self::MAX_HEADERS));
        Cache::put($key, $headers, self::CACHE_SECONDS);

        return $headers;
    }

    /** @return array{title: string, tabs: array<int, array{title: string, gid: int, index: int}>} */
    protected function fetchInfo(string $spreadsheetId): array
    {
        $s = $this->service()->spreadsheets->get($spreadsheetId, ['fields' => 'properties.title,sheets.properties(title,sheetId,index)']);

        return ['title' => (string) $s->getProperties()?->getTitle(), 'tabs' => array_map(fn ($sh) => [
            'title' => (string) $sh->getProperties()->getTitle(), 'gid' => (int) $sh->getProperties()->getSheetId(), 'index' => (int) $sh->getProperties()->getIndex(),
        ], $s->getSheets() ?? [])];
    }

    /** @return array<int, mixed> */
    protected function fetchHeaders(string $spreadsheetId, string $tab): array
    {
        return (array) ($this->service()->spreadsheets_values->get($spreadsheetId, self::quote($tab) . '!1:1')->getValues()[0] ?? []);
    }

    /**
     * Ang link sa row na naisulat: https://docs.google.com/spreadsheets/d/<id>/edit#gid=<gid>&range=A123. Null kapag
     * walang range o gid (ang mga row bago nito) o kapag hindi ito mukhang ID at cell.
     */
    public static function rowLink(array $content): ?string
    {
        $id = (string) ($content['sheet'] ?? '');
        $range = (string) ($content['range'] ?? '');
        $gid = $content['gid'] ?? null;
        if (!preg_match('/^[a-zA-Z0-9_-]{20,}$/', $id) || !is_int($gid) || $gid < 0) return null;
        $cell = explode(':', substr($range, (int) strrpos($range, '!') + (str_contains($range, '!') ? 1 : 0)))[0];
        if (!preg_match('/^[A-Z]{1,3}[0-9]{1,7}$/', $cell)) return null;

        return 'https://docs.google.com/spreadsheets/d/' . $id . '/edit#gid=' . $gid . '&range=' . $cell;
    }

    private static function quote(string $tab): string
    {
        return "'" . str_replace("'", "''", $tab) . "'";
    }
}
