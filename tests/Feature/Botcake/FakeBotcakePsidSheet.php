<?php

namespace Tests\Feature\Botcake;

use App\Services\BotcakePsidSheet;

/** In-memory na sheet: walang tawag sa Google. Row 1 ang header. */
class FakeBotcakePsidSheet extends BotcakePsidSheet
{
    /** @var array<string, array<int, array<int, string>>> "spreadsheetId|sheet" => row number => A..J */
    public array $sheets = [];

    /** @var array<int, array{0:int,1:int}> lahat ng range na binura, sa pagkakasunod */
    public array $deletedRanges = [];

    public int $deleteCalls = 0;

    public function put(string $spreadsheetId, string $sheetName, array $rows): void
    {
        $data = [1 => array_pad(['PAGE', 'NAME', 'PSID', 'DATE'], 10, '')];
        $n = 2;
        foreach ($rows as $row) {
            $data[$n++] = array_pad($row, 10, '');
        }
        $this->sheets[$spreadsheetId . '|' . $sheetName] = $data;
    }

    /** @return array<int, array<int, string>> */
    public function rows(string $spreadsheetId, string $sheetName): array
    {
        return $this->sheets[$spreadsheetId . '|' . $sheetName] ?? [];
    }

    public function readRows(string $spreadsheetId, string $sheetName): array
    {
        $rows = $this->rows($spreadsheetId, $sheetName);
        unset($rows[1]);
        return $rows;
    }

    public function writeStatuses(string $spreadsheetId, string $sheetName, array $statuses): void
    {
        foreach ($statuses as $rowNo => $status) {
            $this->sheets[$spreadsheetId . '|' . $sheetName][$rowNo][9] = $status;
        }
    }

    public function deleteRowRanges(string $spreadsheetId, string $sheetName, array $ranges): void
    {
        if (empty($ranges)) return;
        $this->deleteCalls++;

        $key = $spreadsheetId . '|' . $sheetName;
        foreach ($ranges as [$start, $end]) {
            $this->deletedRanges[] = [$start, $end];
            $rows = $this->sheets[$key];
            $kept = [];
            foreach ($rows as $no => $row) {
                if ($no < $start || $no > $end) $kept[] = $row;
            }
            $this->sheets[$key] = array_combine(range(1, count($kept)), $kept);
        }
    }
}
