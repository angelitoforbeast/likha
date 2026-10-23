<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\LocationStore;
use App\Services\OrderEncode\Sheets;

/** Ang Google sheet ng order encode sa test: ang mga range ay galing sa $data, bawat tawag ay naitatala. Walang tawag sa Google. */
class FakeSheets extends Sheets
{
    /** @var array<string, array<int, array<int, string>>> "<id>|<range>" => mga row */
    public array $data = [];
    /** @var array<int, array<int, string>>|null ang header ng bawat tab */
    public ?array $headerRow = null;
    /** @var array<int, string> */
    public array $calls = [];
    public bool $fail = false;

    /** @var array<int, array{0: string, 1: string, 2: array}> ang mga row na isinulat sa sheet */
    public array $appended = [];

    public function append(string $spreadsheetId, string $tab, array $row)
    {
        if ($this->fail) throw new \RuntimeException('Google said no');
        $this->appended[] = [$spreadsheetId, $tab, $row];

        return "'" . $tab . "'!A9:H9";
    }

    public function put(string $id, string $range, array $rows): void
    {
        $this->data[$id . '|' . $range] = $rows;
    }

    public function values(string $spreadsheetId, string $range): array
    {
        $this->calls[] = 'values ' . $range;
        if ($this->fail) throw new \RuntimeException('Google said no');

        return $this->data[$spreadsheetId . '|' . $range] ?? [];
    }

    public function batchValues(string $spreadsheetId, array $ranges): array
    {
        $this->calls[] = 'batch ' . count($ranges);
        if ($this->fail) throw new \RuntimeException('Google said no');
        $out = [];
        foreach ($ranges as $r) $out[$r] = $this->data[$spreadsheetId . '|' . $r] ?? [];

        return $out;
    }

    public function headers(string $spreadsheetId, string $tab, bool $refresh = false): array
    {
        if ($this->headerRow === null) throw new \RuntimeException('no header');

        return $this->headerRow;
    }
}
