<?php

namespace App\Services\OrderEncode;

use App\Services\Chatbot\ChatbotSheet;

/**
 * Ang pagbasa ng order encode sa Google Sheets (ang DATABASE row at ang MIRRORED O/P ng compare), gamit ang parehong
 * Google client ng chatbot. Hiwalay na class para mapalitan ng fake sa tests: walang tawag sa Google mula sa test.
 */
class Sheets extends ChatbotSheet
{
    /**
     * Maraming range sa isang tawag. Ang bawat range na walang laman ay [].
     * @param array<int, string> $ranges
     * @return array<string, array<int, array<int, string>>> range => mga row
     */
    public function batchValues(string $spreadsheetId, array $ranges): array
    {
        $out = [];
        foreach (array_chunk(array_values($ranges), 100) as $chunk) {
            $res = $this->service()->spreadsheets_values->batchGet($spreadsheetId, ['ranges' => $chunk]);
            foreach ($res->getValueRanges() as $i => $vr) {
                $out[$chunk[$i]] = array_map(fn ($r) => array_map('strval', $r), (array) $vr->getValues());
            }
        }

        return $out;
    }
}
