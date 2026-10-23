<?php

namespace App\Services\OrderEncode;

use RuntimeException;
use Throwable;

/**
 * Ang 1.0 OUTPUT (output-1.0-LIKHA11_1.gs) para sa isang row ng DATABASE: ang phone check, R, S, T/U/V, ang mga pag-ayos ng GPT,
 * W at ang pangalan/address line. Ang row ay mga cell na A..W (gaya ng sheet); ang bawat sulat ay dumadaan sa Text::sheetValue
 * dahil ang Sheets ay ginagawang numero ang text na numero. Hindi dinala: ang oras, ang sleep, ang pagpili ng even row at ang
 * pag-ulit sa row na may S na (isang beses lang pinoproseso ang bawat order).
 */
final class Checker
{
    public bool $phoneOk = true;

    /** @param array<string, string> $cells A..N galing sa encoder; ang iba ay blangko */
    public function __construct(public array $cells, private Locations $loc, private Gpt $gpt, private Steps $steps, private ?Rules $rules = null)
    {
    }

    private function get(string $col): string
    {
        return Text::jsTrim($this->cells[$col] ?? '');
    }

    private function put(string $col, string $value): void
    {
        $this->cells[$col] = Text::sheetValue($value);
    }

    /** checkSingleRow_RowByRowFix_: ang `finally` ay laging tumatakbo, ang exception ay umaakyat pagkatapos nito. */
    public function run(): void
    {
        $k = $this->get('K');
        try {
            $this->body($k);
        } finally {
            $this->finalizeNameAddr($k);
        }
    }

    private function body(string $k): void
    {
        $page = Text::lower($this->get('H'));
        $price = Text::jsTrim(preg_replace('/[₱,]/u', '', $this->get('I')));
        $qty = $this->get('J');

        $msgM = $this->get('M');
        $msgCheck = Text::jsTrim(Text::lower($msgM));

        $province = $this->get('E');
        $cityRaw = $this->get('F');
        $barangay = $this->get('G');
        $cityForCheck = Text::normalizeCityForCheck($cityRaw, $province);

        // PHONE CHECK: 10 digits (ang numero sa sheet ay nawawalan ng 0 sa harap).
        $this->phoneOk = strlen(preg_replace('/\D/', '', $this->get('C'))) === 10;

        // PAGE/PRICE/QTY -> R (M). Ang script ay bumabasa sa lumang column: page = H (item), price = I, qty = J (page).
        if ($this->get('R') === '') {
            if (!str_contains($msgCheck, 'page:')) {
                $this->put('R', '✅');
            } else {
                $score = 0;
                if ($page !== '' && (str_contains($msgCheck, $page) || str_contains($msgCheck, "page: {$page}"))) $score += 1;
                if (preg_match('/price:\s*₱?\s*([\d,.]+)/iu', $msgCheck, $m) && str_replace(',', '', Text::jsTrim($m[1])) === $price) $score += 2;
                if (preg_match('/quantity:\s*(\d+)/i', $msgCheck, $m) && Text::jsTrim($m[1]) === $qty) $score += 4;

                $this->put('R', [7 => '✅', 6 => 'Page Name', 5 => 'Quantity and Price', 4 => 'Page Name and Price', 3 => 'Quantity', 2 => 'Page Name and Quantity', 1 => 'Price and Quantity'][$score] ?? '❌');
            }
        }

        // ADDRESS CHECK -> S (M lang). Ang may laman na N ay Full Address agad.
        if ($this->get('S') === '') {
            $this->put('S', $this->get('N') !== '' ? 'Full Address' : $this->classify($barangay, $cityForCheck, $province, $msgCheck));
            $this->suggestions($this->get('S'), $cityRaw, $province, true);
        }

        // GPT FIXES (K lang) ayon sa kasalukuyang S.
        // Tumama ang note: ang lugar ay galing na sa natutunang address, kaya walang pag-ayos ng GPT (ang verify sa ibaba ay tumatakbo pa).
        $sNow = $this->rules?->noteHit ? '' : Text::lower($this->get('S'));

        if ($sNow === 'barangay') {
            $list = $this->get('T');
            if ($k === '') {
                $this->log('brgy_fix', 'SKIP:K_EMPTY_BRGY', $province, $cityRaw, $barangay, $list, $barangay);
            } elseif ($list !== '') {
                $before = $this->get('G');
                $this->brgyFix($province, $cityRaw, $barangay, $k, $list);
                $after = $this->get('G');
                if ($after !== '' && $after !== $before) $this->recompute($msgM);
            }
        } elseif ($sNow === 'city') {
            $cityList = $this->get('U');
            if ($cityList === '') {
                $cityList = $this->loc->cityList($province);
                $this->put('U', $cityList);
            }
            if ($cityList === '') return;
            if ($k === '') {
                $this->log('brgy_fix', 'SKIP:K_EMPTY_CITYONLY', $province, $cityRaw, $barangay, $cityList, $cityRaw);

                return;
            }
            $cityBefore = $this->get('F');
            $this->cityFix($province, $cityBefore, $k, $cityList);
            $cityAfter = $this->get('F');
            if ($cityAfter === '' || !Text::cityInList($cityAfter, $cityList)) {
                $this->log('brgy_fix', 'CITY_NOT_RESOLVED_STOP_CITYONLY', $province, $cityBefore, $barangay, $cityList, $cityAfter ?: $cityBefore);

                return;
            }
            $brgyListNew = $this->loc->barangayList($cityAfter, $province);
            $this->put('T', $brgyListNew);
            if ($brgyListNew === '') {
                $this->log('brgy_fix', 'BRGY_LIST_EMPTY_AFTER_CITYONLY', $province, $cityAfter, $barangay, '', $barangay);

                return;
            }
            $brgyBefore = $this->get('G');
            if (!Text::brgyInList($brgyBefore, $brgyListNew)) $this->brgyFix($province, $cityAfter, $brgyBefore, $k, $brgyListNew);
            $this->recompute($msgM);
        } elseif ($sNow === 'city and barangay') {
            $cityList = $this->get('U');
            if ($cityList === '') {
                $cityList = $this->loc->cityList($province);
                $this->put('U', $cityList);
            }
            if ($cityList === '') return;
            if ($k === '') {
                $this->log('brgy_fix', 'SKIP:K_EMPTY_CITYBRGY', $province, $cityRaw, $barangay, $cityList, $barangay);

                return;
            }
            $cityBefore = $this->get('F');
            $this->cityFix($province, $cityBefore, $k, $cityList);
            $cityAfter = $this->get('F');
            if ($cityAfter === '' || !Text::cityInList($cityAfter, $cityList)) {
                $this->log('brgy_fix', 'CITY_NOT_RESOLVED_SKIP_BRGY', $province, $cityBefore, $barangay, $cityList, $cityAfter ?: $cityBefore);

                return;
            }
            $brgyList2 = $this->loc->barangayList($cityAfter, $province);
            $this->put('T', $brgyList2);
            if ($brgyList2 === '') {
                $this->log('brgy_fix', 'BRGY_LIST_EMPTY_AFTER_CITY', $province, $cityAfter, $barangay, '', $barangay);

                return;
            }
            $this->brgyFix($province, $cityAfter, $barangay, $k, $brgyList2);
            $this->recompute($msgM);
        } elseif ($sNow === 'full address') {
            $provList = $this->get('V');
            if ($provList === '') {
                $provList = $this->loc->provListCsv;
                $this->put('V', $provList);
            }
            if ($provList === '') return;
            if ($k === '') {
                $this->log('prov_fix', 'SKIP:K_EMPTY_FULLADDR', $province, $cityRaw, $barangay, $provList, $province);

                return;
            }
            $provBefore = $this->get('E');
            $this->provFix($provBefore, $k, $provList);
            $provAfter = $this->get('E');
            if ($provAfter === '' || !Text::provInList($provAfter, $provList)) {
                $this->log('prov_fix', 'PROV_NOT_RESOLVED_STOP', $provBefore, 'PROV', 'PROV', $provList, $provAfter ?: $provBefore);

                return;
            }
            $cityList2 = $this->loc->cityList($provAfter);
            $this->put('U', $cityList2);
            if ($cityList2 === '') {
                $this->log('city_fix', 'CITY_LIST_EMPTY_AFTER_PROV', $provAfter, 'CITY', 'CITY', '', '');

                return;
            }
            $cityBefore2 = $this->get('F');
            $this->cityFix($provAfter, $cityBefore2, $k, $cityList2);
            $cityAfter2 = $this->get('F');
            if ($cityAfter2 === '' || !Text::cityInList($cityAfter2, $cityList2)) {
                $this->log('city_fix', 'CITY_NOT_RESOLVED_STOP', $provAfter, $cityBefore2, $barangay, $cityList2, $cityAfter2 ?: $cityBefore2);

                return;
            }
            $brgyList3 = $this->loc->barangayList($cityAfter2, $provAfter);
            $this->put('T', $brgyList3);
            if ($brgyList3 === '') {
                $this->log('brgy_fix', 'BRGY_LIST_EMPTY_AFTER_CITY_FULLADDR', $provAfter, $cityAfter2, $barangay, '', $barangay);

                return;
            }
            $this->brgyFix($provAfter, $cityAfter2, $barangay, $k, $brgyList3);
            $this->recompute($msgM);
        }

        // K-based verification -> W
        if ($this->get('S') !== '✅') {
            $this->verify($k);
        } else {
            $this->put('W', '');
        }
    }

    /** Ang klase ng S mula sa tatlong fuzzy match. */
    private function classify(string $barangay, string $cityForCheck, string $province, string $msgCheck): string
    {
        $bm = Text::isFuzzyMatch(Text::cleanBrgyForMatch($barangay), $msgCheck);
        $cm = Text::isFuzzyMatch($cityForCheck, $msgCheck);
        if (Text::normProv($province) === 'manila') {
            $pm = Text::isFuzzyMatch('manila', $msgCheck) || Text::isFuzzyMatch('metro manila', $msgCheck)
                || Text::isFuzzyMatch('metro-manila', $msgCheck) || Text::isFuzzyMatch('ncr', $msgCheck);
        } else {
            $pm = Text::isFuzzyMatch($province, $msgCheck);
        }

        if ($bm && $cm && $pm) return '✅';
        if (!$bm && $cm && $pm) return 'Barangay';
        if ($bm && !$cm && $pm) return 'City';
        if (!$bm && !$cm && $pm) return 'City and Barangay';
        if ($bm && $cm && !$pm) return 'Province';

        return 'Full Address';
    }

    /**
     * Ang T/U/V ng isang S. $first: unang compute (binubura ang iba); recompute: walang binubura.
     */
    private function suggestions(string $s, string $city, string $province, bool $first): void
    {
        $blank = $first ? '' : null;
        $new = ['T' => $blank, 'U' => $blank, 'V' => $blank];
        if ($s === 'Barangay') {
            $new['T'] = $this->loc->barangayList($city, $province);
        } elseif ($s === 'City' || $s === 'City and Barangay') {
            $new['U'] = $this->loc->cityList($province);
        } elseif ($s === 'Full Address') {
            $new['V'] = $this->loc->provListCsv;
        }
        foreach ($new as $col => $val) {
            if ($val !== null) $this->put($col, $val);
        }
    }

    /** recomputeSAndSuggestions_: bagong S mula sa kasalukuyang E/F/G; walang binubura sa T/U/V. */
    private function recompute(string $msgM): void
    {
        $msgCheck = Text::jsTrim(Text::lower($msgM));
        $prov = $this->get('E');
        $city = $this->get('F');
        $sNew = $this->classify($this->get('G'), Text::normalizeCityForCheck($city, $prov), $prov, $msgCheck);
        $this->put('S', $sNew);
        $this->suggestions($sNew, $city, $prov, false);
    }

    // ---- GPT: province / city / barangay ---------------------------------------------------------------------------

    private function provFix(string $provOld, string $k, string $provList): void
    {
        $k = Text::jsTrim($k);
        $provList = Text::jsTrim($provList);
        if ($k === '') {
            $this->log('prov_fix', 'SKIP:K_EMPTY_PROV', $provOld, 'PROV_OLD', 'PROV_OLD', $provList, $provOld);

            return;
        }
        if ($provList === '') {
            $this->log('prov_fix', 'SKIP:PROV_LIST_EMPTY', $provOld, 'PROV_OLD', 'PROV_OLD', $provList, $provOld);

            return;
        }
        $this->gpt->requireKey();
        $prompt = $k . "\n\n"
            . "Task: correct the PROVINCE only.\n"
            . "Rules:\n"
            . "- Choose ONLY from the allowed PROVINCE list below.\n"
            . "- If the message explicitly mentions NCR/Metro Manila, pick the NCR/Metro Manila option in the list.\n"
            . "- Do NOT keep the current province unless the message supports it.\n\n"
            . 'Current Province: ' . $provOld . "\n\n"
            . "Allowed PROVINCE list (pick ONLY from this list):\n"
            . $provList . "\n\n" . $this->examples()
            . "Return STRICT JSON only: {\"province\":\"...\"}\n";
        $system = implode(' ', ['You validate Philippine province names.', 'Choose ONLY from the provided province list.',
            'Return STRICT JSON only: {"province":"..."}.', 'If truly cannot determine, return UNKNOWN.']);

        $raw = '';
        try {
            $raw = $this->gpt->strictJson($system, $prompt);
            $newProv = $this->parseFix($raw)['province'];
        } catch (Throwable $e) {
            $this->log('prov_fix', 'ERR_PROV:' . $e->getMessage(), $provOld, 'PROV_OLD', 'PROV_OLD', $provList, $provOld, $raw !== '' ? $raw : $e->getMessage(), true);

            return;
        }

        $status = 'PROV_NO_CHANGE';
        if ($newProv !== '' && $newProv !== 'UNKNOWN') {
            $canon = Text::canonicalizeProvince($newProv, $provList);
            if ($canon !== '' && $canon !== $provOld && Text::provInList($canon, $provList)) {
                $this->put('E', $canon);
                $status = 'WROTE_PROV';
            } elseif ($canon !== '' && !Text::provInList($canon, $provList)) {
                $status = 'PROV_NOT_IN_LIST';
            }
        }
        $this->log('prov_fix', $status, $provOld, 'PROV_OLD', 'PROV_OLD', $provList, $newProv ?: $provOld, $raw, true);
    }

    private function cityFix(string $prov, string $cityOld, string $k, string $cityList): void
    {
        $k = Text::jsTrim($k);
        $cityList = Text::jsTrim($cityList);
        if ($k === '') {
            $this->log('city_fix', 'SKIP:K_EMPTY_CITY', $prov, $cityOld, 'CITY_OLD', $cityList, $cityOld);

            return;
        }
        if ($cityList === '') {
            $this->log('city_fix', 'SKIP:CITY_LIST_EMPTY', $prov, $cityOld, 'CITY_OLD', $cityList, $cityOld);

            return;
        }
        // R1: ang isinulat na city ng customer na nasa city list ng province ay hindi pinapalitan ng GPT.
        if ($this->rules && ($kept = $this->rules->writtenCity($cityList, $prov)) !== null) {
            if ($kept !== $this->get('F')) $this->put('F', $kept);
            $this->log('city_fix', 'R1_WRITTEN_CITY_KEPT', $prov, $cityOld, 'CITY_OLD', $cityList, $kept, '', false, ['rule' => 'R1']);

            return;
        }
        $this->gpt->requireKey();
        $prompt = $k . "\n\n"
            . "Task: correct the CITY only.\n"
            . "Rules:\n"
            . "- Choose ONLY from the allowed CITY list below.\n"
            . "- If the message explicitly mentions a city/municipality, you MUST pick that city if it exists in the allowed list.\n"
            . "- Do NOT keep the current city unless the message supports it.\n"
            . "- If the message contains a 'City:' field, prioritize that value.\n\n"
            . 'Province: ' . $prov . "\n"
            . 'Current City: ' . $cityOld . "\n\n"
            . "Allowed CITY list (pick ONLY from this list):\n"
            . $cityList . "\n\n" . $this->examples()
            . "Return STRICT JSON only: {\"city\":\"...\",\"province\":\"...\"}\n";
        $system = implode(' ', ['You validate Philippine cities/municipalities.', 'Choose ONLY from the provided city list.',
            'Return STRICT JSON only: {"city":"...","province":"..."}.', 'If truly cannot determine, return UNKNOWN.']);

        $raw = '';
        try {
            $raw = $this->gpt->strictJson($system, $prompt);
            $newCity = $this->parseFix($raw)['city'];
        } catch (Throwable $e) {
            $this->log('city_fix', 'ERR_CITY:' . $e->getMessage(), $prov, $cityOld, 'CITY_OLD', $cityList, $cityOld, $raw !== '' ? $raw : $e->getMessage(), true);

            return;
        }

        $status = 'CITY_NO_CHANGE';
        if ($newCity !== '' && $newCity !== 'UNKNOWN' && $newCity !== $cityOld && Text::cityInList($newCity, $cityList)) {
            $this->put('F', $this->rules ? Rules::canonical($cityList, $newCity, fn ($a, $b) => Text::normPlace($a) === Text::normPlace($b)) : Text::foldN($newCity)); // R4: ang label ng J&T
            $status = 'WROTE_CITY';
        } elseif ($newCity !== '' && $newCity !== 'UNKNOWN' && !Text::cityInList($newCity, $cityList)) {
            $status = 'CITY_NOT_IN_LIST';
        }
        $this->log('city_fix', $status, $prov, $cityOld, 'CITY_OLD', $cityList, $newCity ?: $cityOld, $raw, true);
    }

    private function brgyFix(string $prov, string $city, string $brgy, string $k, string $brgyList): void
    {
        $k = Text::jsTrim($k);
        if ($k === '') {
            $this->log('brgy_fix', 'SKIP:K_EMPTY_BRGY', $prov, $city, $brgy, $brgyList, $brgy);

            return;
        }
        $brgyList = Text::jsTrim($brgyList);
        if ($brgyList === '') {
            $this->log('brgy_fix', 'SKIP:BRGY_LIST_EMPTY', $prov, $city, $brgy, $brgyList, $brgy);

            return;
        }
        // R1: ang isinulat na barangay ng customer na nasa barangay list ng city ay hindi pinapalitan ng GPT.
        if ($this->rules && ($kept = $this->rules->writtenBarangay($brgyList)) !== null) {
            if ($kept !== $this->get('G')) $this->put('G', $kept);
            $this->log('brgy_fix', 'R1_WRITTEN_BARANGAY_KEPT', $prov, $city, $brgy, $brgyList, $kept, '', false, ['rule' => 'R1']);
            $this->recompute($this->get('M'));

            return;
        }
        $this->gpt->requireKey();
        $prompt = $k . "\n\n"
            . "We need to correct BARANGAY only.\n"
            . 'Province: ' . $prov . "\n"
            . 'City: ' . $city . "\n"
            . 'Current Barangay: ' . $brgy . "\n\n"
            . "Allowed BARANGAY list (pick ONLY from this list):\n"
            . $brgyList . "\n\n" . $this->examples()
            . "Return STRICT JSON only: {\"barangay\":\"...\",\"city\":\"...\",\"province\":\"...\"}\n";
        $system = implode(' ', ['You validate Philippine address barangays.',
            'Return STRICT JSON only: {"barangay":"...","city":"...","province":"..."}.',
            'If a barangay list is provided, choose ONLY from that list.', 'If truly cannot determine, return UNKNOWN.']);

        $raw = '';
        try {
            $raw = $this->gpt->strictJson($system, $prompt);
            $parsed = $this->parseFix($raw);
        } catch (Throwable $e) {
            $this->log('brgy_fix', 'ERR_BRGY:' . $e->getMessage(), $prov, $city, $brgy, $brgyList, $brgy, $raw !== '' ? $raw : $e->getMessage(), true);

            return;
        }

        $newBrgy = $brgy;
        if ($parsed['barangay'] !== '' && $parsed['barangay'] !== 'UNKNOWN') $newBrgy = Text::jsTrim($parsed['barangay']);

        $status = 'NO_CHANGE';
        if ($newBrgy !== '' && $newBrgy !== $brgy) {
            if (Text::brgyInList($newBrgy, $brgyList)) {
                $this->put('G', $this->rules ? Rules::canonical($brgyList, $newBrgy, fn ($a, $b) => Text::brgyKey($a) === Text::brgyKey($b)) : Text::foldN($newBrgy)); // R4: ang label ng J&T
                $status = 'WROTE_BRGY';
            } else {
                $status = 'BRGY_NOT_IN_LIST';
            }
        }
        $this->log('brgy_fix', $status, $prov, $city, $brgy, $brgyList, $newBrgy, $raw, true);
    }

    /** BRGYFIX_parseJSON_: buong JSON, o ang unang {...} sa text; nagtatapon kapag sira ang JSON na iyon. @return array{barangay: string, city: string, province: string} */
    private function parseFix(string $raw): array
    {
        $out = ['barangay' => '', 'city' => '', 'province' => ''];
        $obj = self::json(Text::jsTrim($raw));
        if ($obj === false) return $out;
        foreach ($out as $key => $_) $out[$key] = self::str($obj[$key] ?? '');

        return $out;
    }

    /**
     * @return array<string, mixed>|false  array kapag may nahanap na JSON; false kapag walang {...}.
     * @throws RuntimeException kapag ang nahanap na {...} ay hindi JSON
     */
    private static function json(string $s)
    {
        $whole = json_decode($s, true);
        if (json_last_error() === JSON_ERROR_NONE) return is_array($whole) ? $whole : [];
        if (preg_match('/\{.*\}/s', $s, $m)) {
            $obj = json_decode($m[0], true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('Unexpected token in JSON');

            return is_array($obj) ? $obj : [];
        }

        return false;
    }

    private static function str($v): string
    {
        return is_scalar($v) ? Text::jsTrim((string) $v) : '';
    }

    /** JS !!value */
    private static function truthy($v): bool
    {
        if (is_string($v)) return $v !== '';
        if (is_float($v) || is_int($v)) return $v != 0;

        return (bool) $v;
    }

    // ---- GPT: verify -> W ------------------------------------------------------------------------------------------

    private function verify(string $k): void
    {
        $prov = $this->get('E');
        $city = $this->get('F');
        $brgy = $this->get('G');

        if ($k === '') {
            $this->put('W', '');
            $this->log('verify_w', 'SKIP:K_EMPTY_VERIFYK', $prov, $city, $brgy, '', '');

            return;
        }
        $this->gpt->requireKey();
        $system = implode("\n", ['You are a strict-but-practical Philippine address verifier.',
            'You will receive RAW_CUSTOMER_CHAT and CURRENT_PROVINCE/CITY/BARANGAY.', '',
            'Output STRICT JSON only with booleans:', '{"province_ok":true/false,"city_ok":true/false,"barangay_ok":true/false}']);
        $prompt = "RAW_CUSTOMER_CHAT:\n<<<\n" . $k . "\n>>>\n\n"
            . 'CURRENT_PROVINCE: ' . $prov . "\n"
            . 'CURRENT_CITY: ' . $city . "\n"
            . 'CURRENT_BARANGAY: ' . $brgy . "\n\n"
            . "Return STRICT JSON only:\n"
            . "{\"province_ok\":true/false,\"city_ok\":true/false,\"barangay_ok\":true/false}\n";

        $raw = '';
        try {
            $raw = $this->gpt->strictJson($system, $prompt);
            $obj = self::json(Text::jsTrim($raw));
            $obj = $obj === false ? [] : $obj;
            $ok = array_map(fn ($key) => self::truthy($obj[$key] ?? null), ['province_ok', 'city_ok', 'barangay_ok']);
        } catch (Throwable $e) {
            $this->put('W', 'Full Address');
            $this->log('verify_w', 'ERR_VERIFYK:' . $e->getMessage(), $prov, $city, $brgy, '', '', $raw !== '' ? $raw : $e->getMessage(), true);

            return;
        }
        [$pOk, $cOk, $bOk] = $ok;

        // IMPLIED CITY PATCH
        if ($pOk && $bOk && !$cOk) {
            $cands = $this->loc->inferCities($prov, $brgy);
            if (count($cands) === 1 && Text::normPlace($cands[0]) === Text::normPlace($city)) $cOk = true;
        }

        $w = self::computeW($pOk, $cOk, $bOk);
        $detail = ['province_ok' => $pOk, 'city_ok' => $cOk, 'barangay_ok' => $bOk];
        if ($w === 'Province' && $this->rules && $this->rules->provinceContradiction($prov) !== null) {
            // R2: iba ang province sa isinulat ng customer, kaya hindi ito ginagawang ✅.
            $this->put('W', 'Province');
            $this->log('verify_w', 'WROTE_W_PROVINCE_HELD', $prov, $city, $brgy, '', '', $raw, true, $detail + ['rule' => 'R2']);
        } elseif ($w === 'Province') {
            // Isinusulat ang "Province", saka ginagawang ✅ pagkaraan ng isang segundo (ang script).
            $this->put('W', '✅');
            $this->log('verify_w', 'WROTE_W_PROVINCE_THEN_CHECKMARK', $prov, $city, $brgy, '', '', $raw, true, $detail);
        } else {
            $this->put('W', $w);
            $this->log('verify_w', 'WROTE_W', $prov, $city, $brgy, '', '', $raw, true, $detail);
        }
    }

    public static function computeW(bool $p, bool $c, bool $b): string
    {
        if ($p && $c && $b) return '✅';
        if (!$b && $c && $p) return 'Barangay';
        if ($b && !$c && $p) return 'City';
        if ($b && $c && !$p) return 'Province';
        if (!$b && !$c && $p) return 'City and Barangay';
        if (!$p && !$c && $b) return 'Province and City';
        if (!$p && $c && !$b) return 'Province and Barangay';

        return 'Full Address';
    }

    // ---- Pangalan at Address Line 1 (FINALIZE_NameAddr_) ------------------------------------------------------------

    private function finalizeNameAddr(string $k): void
    {
        try {
            if ($k === '') {
                $this->steps->add('name_addr', 'SKIP:K_EMPTY_NAMEADDR');

                return;
            }
            $nameOld = $this->get('B');
            $addrOld = $this->get('D');
            $nameFilled = $nameOld !== '' && Text::lower($nameOld) !== 'unknown';
            $addrFilled = $addrOld !== '' && Text::lower($addrOld) !== 'unknown';
            if ($nameFilled && $addrFilled) {
                $this->steps->add('name_addr', 'SKIP:ALREADY_FILLED');

                return;
            }
            $prov = $this->get('E');
            $city = $this->get('F');
            $brgy = $this->get('G');

            $this->gpt->requireKey();
            $system = implode("\n", [
                'You extract and normalize Philippine customer name and Address Line 1 from messy chat logs.', '',
                'You will receive RAW_CUSTOMER_CHAT and CURRENT values.', '',
                'Output STRICT JSON only:', '{"full_name":"...","address_line1":"..."}', '',
                'Rules for full_name:',
                "- Choose the customer's real name if clearly stated (often after 'Name:' or in order confirmation).",
                '- Ignore page/persona names (e.g., seller, admin) unless the customer is clearly that person.',
                '- Remove emojis and extra punctuation; keep proper spacing/casing.', '',
                'Rules for address_line1:',
                '- This is Address Line 1 ONLY: house/lot/block, street, purok/sitio, subdivision, building, landmark, etc.',
                '- DO NOT include Barangay/Brgy, City/Municipality, or Province in address_line1.',
                "- If chat has only 'Brgy, City, Province' and no other details, return empty string for address_line1.",
                "- Prefer details near address lines, or after words like 'address', 'landmark', 'purok', 'street', 'subd', 'blk', 'lot'.",
                '- If there are conflicting address details, pick the most complete and most recent one.', '',
                'Return JSON only. No extra text.',
            ]);
            $prompt = "RAW_CUSTOMER_CHAT:\n<<<\n" . $k . "\n>>>\n\n"
                . 'CURRENT_FULL_NAME: ' . $nameOld . "\n"
                . 'CURRENT_ADDRESS_LINE1: ' . $addrOld . "\n"
                . 'CURRENT_PROVINCE: ' . $prov . "\n"
                . 'CURRENT_CITY: ' . $city . "\n"
                . 'CURRENT_BARANGAY: ' . $brgy . "\n\n"
                . "Task:\n"
                . "1) Extract best FULL NAME.\n"
                . "2) Extract best ADDRESS LINE 1 (exclude barangay/city/province).\n\n"
                . "Return STRICT JSON only:\n"
                . "{\"full_name\":\"...\",\"address_line1\":\"...\"}\n";

            $raw = '';
            try {
                $raw = $this->gpt->strictJson($system, $prompt);
                $obj = self::json(Text::jsTrim($raw));
                $obj = $obj === false ? [] : $obj;
                $newName = self::str($obj['full_name'] ?? '');
                $newAddr = self::str($obj['address_line1'] ?? '');
            } catch (Throwable $e) {
                $this->steps->add('name_addr', 'ERR_NAMEADDR:' . $e->getMessage(), $this->gpt->meta() + ['raw' => $raw !== '' ? $raw : $e->getMessage()]);

                return;
            }

            $newAddr = self::stripLocations($newAddr, $brgy, $city, $prov);
            $newName = Text::jsTrim(Text::collapse($newName));
            $newAddr = Text::jsTrim(preg_replace('/^,|,$/', '', Text::jsTrim(preg_replace('/\s*,\s*/', ', ', Text::collapse($newAddr)))));

            $wrote = false;
            if (!$nameFilled && $newName !== '' && Text::lower($newName) !== 'unknown' && $newName !== $nameOld && preg_match('/[a-zA-ZñÑ]/u', $newName)) {
                $this->put('B', $newName);
                $wrote = true;
            }
            if (!$addrFilled && $newAddr !== '' && Text::lower($newAddr) !== 'unknown' && $newAddr !== $addrOld) {
                $this->put('D', $newAddr);
                $wrote = true;
            }
            $this->steps->add('name_addr', $wrote ? 'WROTE_NAMEADDR' : 'NO_CHANGE', $this->gpt->meta() + ['raw' => $raw]);
        } catch (Throwable $e) {
            $this->steps->add('name_addr', 'ERR_NAMEADDR_FATAL:' . $e->getMessage());
        }
    }

    /** NAMEADDR_stripLocations_ */
    public static function stripLocations(string $addr, string $brgy, string $city, string $prov): string
    {
        $out = $addr;
        foreach ([$brgy, $city, $prov] as $p) {
            $p = Text::jsTrim($p);
            if ($p === '') continue;
            $out = preg_replace('/\b' . preg_quote($p, '/') . '\b/i', ' ', $out) ?? $out;
        }
        $out = preg_replace('/\b(barangay|brgy)\b/i', ' ', $out);
        $out = preg_replace('/\b(city|city of|municipality)\b/i', ' ', $out);
        $out = preg_replace('/\b(province|prov)\b/i', ' ', $out);
        $out = Text::collapse($out);
        $out = preg_replace('/\s*,\s*/', ', ', $out);

        return Text::jsTrim(preg_replace('/^,|,$/', '', $out));
    }

    // ---- talaan -----------------------------------------------------------------------------------------------------

    /** Ang "Past corrections" ng mga natutunang address para sa prompt (walang laman sa sheet mode o kapag walang approved na note). */
    private function examples(): string
    {
        return $this->rules?->examplesBlock() ?? '';
    }

    /** BRGYFIX_log_ / VERIFYK_log_: $gpt = may tawag sa GPT ang hakbang na ito. */
    private function log(string $step, string $status, string $prov, string $city, string $old, string $list, string $new, string $raw = '', bool $gpt = false, array $more = []): void
    {
        $listLen = $list === '' ? 0 : count(array_filter(array_map(fn ($s) => Text::jsTrim($s), explode(',', $list)), fn ($s) => $s !== ''));
        $extra = ($gpt ? $this->gpt->meta() : [])
            + ['province' => $prov, 'city' => $city, 'old' => $old, 'new' => $new, 'list_len' => $listLen] + $more;
        if ($raw !== '') $extra['raw'] = $raw;
        $this->steps->add($step, $status, $extra);
    }
}
