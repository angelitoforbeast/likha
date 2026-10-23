<?php

namespace App\Services\OrderEncode;

use Throwable;

/**
 * Isang order mula umpisa hanggang dulo, sa ayos ng dalawang script: auto-encode.gs (ENCODER: hakbang 2 hanggang 7) at saka
 * ang 1.0 OUTPUT (Checker) sa row ng DATABASE. Walang sinusulat sa labas: ibinabalik lang ang resulta.
 */
final class Encoder
{
    public function __construct(private Macro $macro, private Locations $loc, private Gpt $gpt, private Steps $steps, private ?Rules $rules = null)
    {
    }

    /**
     * @return array{outputs: array<string, mixed>, db: array<string, string>, error: ?string}
     */
    public function run(Input $in): array
    {
        $date = $in->get('date');
        $page = $in->get('page');
        $name = $in->get('name');
        $phone = $in->get('phone');
        $input = $in->get('input');
        $shop = $in->get('shop');
        $cxd = $in->get('cxd');

        // STEP 2: extractAddressPartsRow2
        $parts = Parse::addressParts($cxd);
        $cell = ['J' => $parts['name'], 'K' => $parts['phone'], 'L' => '', 'M' => $parts['province'], 'N' => $parts['city'], 'O' => $parts['barangay']];
        $this->steps->add('parse_parts', 'OK', ['name' => $parts['name'] !== '', 'phone' => $parts['phone'] !== '', 'province' => $parts['province'],
            'city' => $parts['city'], 'barangay' => $parts['barangay']]);

        $error = null;
        try {
            // STEP 3: extractAddressLine1WithLandmark
            $raw = Text::jsTrim($cxd);
            if ($raw === '' || !$this->gpt->hasKey()) {
                $this->steps->add('address_line1', 'SKIP:' . ($raw === '' ? 'H_EMPTY' : 'NO_KEY'));
            } else {
                $prompt = "Extract Address Line 1 including landmark. Remove name, phone number, barangay, city, province, zip code, and instructions. Do not explain. Return only the address line.\n\nInput:\n" . $raw;
                try {
                    $cell['L'] = $this->gpt->extract('You are a helpful assistant that extracts Address Line 1 including the landmark only.', $prompt);
                    $this->steps->add('address_line1', $cell['L'] !== '' ? 'WROTE_ADDRESS_LINE1' : 'EMPTY_RESPONSE', $this->gpt->meta() + $this->rawIfEmpty($cell['L']));
                } catch (Throwable $e) {
                    $this->steps->add('address_line1', 'ERR_ADDRESS_LINE1:' . $e->getMessage(), $this->gpt->meta());
                }
            }

            // STEP 4: extractDetails
            $ip = Parse::itemPrice($shop);
            $cell['U'] = $ip['item'];
            $cell['V'] = $ip['price'];
            $this->steps->add('item_price', 'OK', ['item' => $ip['item'], 'price' => $ip['price']]);

            // STEP 5: checkMissingFieldsAndAutoFill
            $this->fillMissing($cell, Text::jsTrim($name) . ' ' . Text::jsTrim($phone) . ' ' . Text::jsTrim($input));

            // STEP 6: Macro
            $this->rules?->begin($parts);
            $m = $this->macro->run($cell['M'], $cell['N'], $cell['O'], null); // R3 (unahin ang isinulat sa unang candidate) ay tinanggal: nalilito kapag napagpalit ang mga field
            $this->steps->add('macro', $m === null ? 'SKIP:NO_PLACE' : 'OK', $m === null ? [] : [
                'p1' => $m['p1'], 'points' => $m['points'], 'candidates' => $m['candidates'], 'remark' => $m['remark'], 'icon' => $m['icon']]);
            $m ??= ['remark' => '', 'icon' => '', 'province' => '', 'city' => '', 'barangay' => ''];

            // Natutunang address: kapag may approved na note ang eksaktong isinulat, ang lugar ay galing doon at walang remark.
            if (($note = $this->rules?->lookupNote()) !== null) {
                $m = ['remark' => '', 'icon' => '✅', 'province' => $note->province, 'city' => $note->city, 'barangay' => $note->barangay] + $m;
                $this->steps->add('note_hit', 'NOTE_HIT', ['note' => $note->id, 'key' => $note->written_key, 'province' => $note->province, 'city' => $note->city, 'barangay' => $note->barangay]);
            }
        } catch (Throwable $e) {
            $error = $this->steps->redact($e->getMessage());
            $m = ['remark' => '', 'icon' => '', 'province' => '', 'city' => '', 'barangay' => ''];
        }

        // STEP 7: storeAndTransferEncoderValues (A..N ng DATABASE)
        $db = array_fill_keys(range('A', 'W'), '');
        $write = [
            'A' => $date, 'B' => $cell['J'], 'C' => $cell['K'], 'D' => $cell['L'], 'E' => $m['province'], 'F' => $m['city'], 'G' => $m['barangay'],
            'H' => $cell['U'] ?? '', 'I' => $cell['V'] ?? '', 'J' => $page,
            'K' => self::allUserInput($in), 'L' => $shop, 'M' => $cxd, 'N' => $m['remark'],
        ];
        foreach ($write as $col => $v) $db[$col] = Text::sheetValue($v);

        // 1.0 OUTPUT
        $checker = new Checker($db, $this->loc, $this->gpt, $this->steps, $this->rules);
        try {
            $checker->run();
        } catch (Throwable $e) {
            $error ??= $this->steps->redact($e->getMessage());
        }
        $db = $checker->cells;

        $w = Text::jsTrim($db['W']);
        $s = Text::jsTrim($db['S']);
        $final = $w !== '' ? $w : $s;

        $outputs = [
            'name' => $db['B'], 'phone' => $cell['K'] ?? '', 'address_line' => $db['D'], 'province' => $db['E'], 'city' => $db['F'], 'barangay' => $db['G'],
            'item' => $db['H'], 'cod' => $db['I'], 'remark' => $db['N'], 'icon' => $m['icon'],
            'r' => $db['R'], 's' => $db['S'], 't' => $db['T'], 'u' => $db['U'], 'v' => $db['V'], 'w' => $db['W'],
            'final' => $final, 'proceed' => $final === '✅', 'candidates' => $m['candidates'] ?? null, 'phone_ok' => $checker->phoneOk,
        ];
        if ($this->rules) {
            // R6: ang mas buong label ng parehong barangay, o hold kapag may ibang barangay/city na nabanggit sa chat (hindi sa note hit).
            $hold = null;
            if ($outputs['proceed'] && !$this->rules->noteHit) {
                $amb = $this->rules->ambiguity($db['E'], $db['F'], $db['G'], Text::jsTrim($input) !== '' ? $input : $cxd);
                if (isset($amb['barangay'])) {
                    $this->steps->add('rule_label', 'R6_LONGER_LABEL', ['old' => $db['G'], 'new' => $amb['barangay']]);
                    $db['G'] = $outputs['barangay'] = $amb['barangay'];
                }
                if (isset($amb['hold'])) $hold = ['rule' => 'R6', 'reason' => $amb['hold']];
            }
            // R2 at R4: ang ✅ ay hold kapag iba ang province sa isinulat o ang kombinasyon ay wala sa J&T file.
            if (!$hold) $hold = $outputs['proceed'] ? $this->rules->checkFinal($db['E'], $db['F'], $db['G']) : null;
            if ($final === 'Province' && !$this->rules->noteHit && ($why = $this->rules->provinceContradiction($db['E'])) !== null) $hold = ['rule' => 'R2', 'reason' => $why]; // ang checker ay hindi ginawang ✅
            if ($hold) {
                $outputs['proceed'] = false;
                $this->steps->add('rule_hold', 'HOLD:' . $hold['rule'], ['rule' => $hold['rule'], 'reason' => $hold['reason']]);
            }
            $outputs += ['rules' => Rules::NAME, 'hold' => $hold, 'note_hit' => $this->rules->noteHit];
        }

        return ['outputs' => $outputs, 'db' => $db, 'error' => $error];
    }

    /** Ang K ng DATABASE (VALL_USER_INPUT ng storeAndTransferEncoderValues): ang buong usapan na binabasa ng GPT. */
    public static function allUserInput(Input $in): string
    {
        return 'FB NAME: ' . $in->get('name') . "
PHONE NUMBER: " . $in->get('phone') . "
" . $in->get('input') . "
" . $in->get('shop');
    }

    /** checkMissingFieldsAndAutoFill: ang blangkong J..O ay itinatanong sa GPT, isa-isa, at isinusulat kahit blangko ang sagot. */
    private function fillMissing(array &$cell, string $conversation): void
    {
        // Ang script ay tinitingnan kung walang laman ang pinagsamang text; ang tatlong blangko ay "  " pa rin, kaya hindi ito nilalaktawan.
        if ($conversation === '' || !$this->gpt->hasKey()) {
            $this->steps->add('fill_missing', 'SKIP:' . ($conversation === '' ? 'NO_CONVERSATION' : 'NO_KEY'));

            return;
        }
        $labels = ['J' => 'Name', 'K' => 'Phone Number', 'L' => 'Address Line 1', 'M' => 'Province', 'N' => 'City', 'O' => 'Barangay'];
        $missing = array_keys(array_filter($labels, fn ($l, $c) => Text::jsTrim($cell[$c]) === '', ARRAY_FILTER_USE_BOTH));
        if (!$missing) {
            $this->steps->add('fill_missing', 'ALL_FILLED');

            return;
        }
        foreach ($missing as $c) {
            $label = $labels[$c];
            $prompt = "\"{$conversation}\"\n\nExtract only the {$label}. Answer with the exact {$label} only. No explanation.";
            try {
                $out = $this->gpt->extract('You are a helpful assistant that extracts missing shipping details only.', $prompt);
                $cell[$c] = $out;
                $this->steps->add('fill_' . strtolower(str_replace(' ', '_', $label)), $out !== '' ? 'WROTE_' . strtoupper(str_replace(' ', '_', $label)) : 'EMPTY_RESPONSE',
                    $this->gpt->meta() + ['raw' => $out !== '' ? $out : ($this->gpt->last['raw'] ?? '')]);
            } catch (Throwable $e) {
                $this->steps->add('fill_' . strtolower(str_replace(' ', '_', $label)), 'ERR:' . $e->getMessage(), $this->gpt->meta());
            }
        }
    }

    /** @return array<string, string> ang sagot ng OpenAI kapag walang nakuhang text (para makita kung bakit). */
    private function rawIfEmpty(string $text): array
    {
        return $text === '' ? ['raw' => (string) ($this->gpt->last['raw'] ?? '')] : [];
    }
}
