<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\ValueRange;

/**
 * Manipis na wrapper ng Google Sheets para sa Botcake PSID sheet (A page, B fb name, C PSID, D date, J status).
 * Hiwalay ito para mapalitan ng fake sa tests: walang tawag sa Google mula sa test.
 */
class BotcakePsidSheet
{
    private const READ_CHUNK = 5000;
    private const WRITE_CHUNK = 500;

    private ?Sheets $service = null;

    protected function service(): Sheets
    {
        if ($this->service) return $this->service;

        $client = new GoogleClient();
        $client->setApplicationName('Laravel Botcake PSID Import');
        $client->setScopes([Sheets::SPREADSHEETS]); // read/write
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        return $this->service = new Sheets($client);
    }

    /**
     * Lahat ng row mula row 2 (laktaw ang header), A..J, naka-pad sa 10 columns.
     * @return array<int, array<int, string>> row number => values
     */
    public function readRows(string $spreadsheetId, string $sheetName): array
    {
        $out = [];
        $start = 2;

        while (true) {
            $end  = $start + self::READ_CHUNK - 1;
            $resp = $this->service()->spreadsheets_values->get($spreadsheetId, $sheetName . '!A' . $start . ':J' . $end);
            $rows = $resp->getValues() ?? [];

            foreach ($rows as $i => $row) {
                $out[$start + $i] = array_map(fn ($v) => (string) $v, array_pad($row ?? [], 10, ''));
            }

            // Ang Sheets API ay hindi nagbabalik ng mga blank na row sa dulo: kulang sa chunk = dulo na ng sheet.
            if (count($rows) < self::READ_CHUNK) break;
            $start += self::READ_CHUNK;
        }

        return $out;
    }

    /** @param array<int, string> $statuses row number => text para sa column J */
    public function writeStatuses(string $spreadsheetId, string $sheetName, array $statuses): void
    {
        foreach (array_chunk($statuses, self::WRITE_CHUNK, true) as $chunk) {
            $data = [];
            foreach ($chunk as $rowNo => $status) {
                $data[] = new ValueRange([
                    'range'  => $sheetName . '!J' . $rowNo,
                    'values' => [[$status]],
                ]);
            }

            $this->service()->spreadsheets_values->batchUpdate($spreadsheetId, new BatchUpdateValuesRequest([
                'valueInputOption' => 'RAW',
                'data'             => $data,
            ]));
        }
    }

    /**
     * Totoong pagbura ng mga row (deleteDimension). Ang ranges ay [start, end] (1-based, kasama ang dulo),
     * naka-ayos mula ibaba pataas: sunod-sunod na ina-apply ng Sheets ang requests, kaya hindi gumagalaw
     * ang row numbers ng mga range na nasa itaas pa.
     * @param array<int, array{0:int,1:int}> $ranges
     */
    public function deleteRowRanges(string $spreadsheetId, string $sheetName, array $ranges): void
    {
        if (empty($ranges)) return;

        $sheetId = $this->sheetIdByTitle($spreadsheetId, $sheetName);
        if ($sheetId === null) {
            throw new \RuntimeException('Sheet tab not found: ' . $sheetName);
        }

        foreach (array_chunk($ranges, self::WRITE_CHUNK) as $chunk) {
            $requests = [];
            foreach ($chunk as [$start, $end]) {
                $requests[] = new Sheets\Request([
                    'deleteDimension' => [
                        'range' => [
                            'sheetId'    => $sheetId,
                            'dimension'  => 'ROWS',
                            'startIndex' => $start - 1,
                            'endIndex'   => $end,
                        ],
                    ],
                ]);
            }

            $this->service()->spreadsheets->batchUpdate($spreadsheetId, new BatchUpdateSpreadsheetRequest([
                'requests' => $requests,
            ]));
        }
    }

    protected function sheetIdByTitle(string $spreadsheetId, string $sheetName): ?int
    {
        $title = trim($sheetName);
        if (strlen($title) >= 2 && $title[0] === "'" && substr($title, -1) === "'") {
            $title = str_replace("''", "'", substr($title, 1, -1));
        }

        $meta = $this->service()->spreadsheets->get($spreadsheetId, ['fields' => 'sheets.properties']);
        foreach ($meta->getSheets() as $sheet) {
            $props = $sheet->getProperties();
            if ($props->getTitle() === $title) {
                return (int) $props->getSheetId();
            }
        }

        return null;
    }

    /**
     * Spreadsheet id at pangalan ng tab mula sa setting (URL na may /d/<id>, range na gaya ng "PSID!A:J").
     * @return array{0:string,1:string}|null
     */
    public static function target(\App\Models\BotcakePsidSetting $setting): ?array
    {
        if (!preg_match('/\/d\/([a-zA-Z0-9-_]+)/', (string) $setting->sheet_url, $m)) {
            return null;
        }

        $range = (string) $setting->sheet_range;
        $sheetName = strpos($range, '!') !== false ? explode('!', $range)[0] : $range;

        return [$m[1], $sheetName];
    }
}
