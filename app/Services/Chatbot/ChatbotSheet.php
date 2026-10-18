<?php

namespace App\Services\Chatbot;

use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;

/**
 * Ang Google Sheet append ng chatbot, gamit ang umiiral na Google client ng Likha (storage/app/credentials.json, gaya
 * ng BotcakePsidSheet). Hiwalay para mapalitan ng fake sa tests: walang tawag sa Google mula sa test.
 */
class ChatbotSheet
{
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
     * (ChatbotVars::sheetCell). Nagtatapon kapag tumanggi ang Google.
     * @param array<int, string> $row
     */
    public function append(string $spreadsheetId, string $tab, array $row): void
    {
        $this->service()->spreadsheets_values->append($spreadsheetId, "'" . str_replace("'", "''", $tab) . "'!A1",
            new ValueRange(['values' => [array_values($row)]]),
            ['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS']);
    }
}
