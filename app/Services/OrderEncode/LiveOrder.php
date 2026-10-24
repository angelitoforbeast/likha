<?php

namespace App\Services\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderIdentity;
use App\Services\OrderPhone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Live: ang macro_output row na gagawin sana ng Likha import at ng Macro import mula sa isang row ng All Orders at ng
 * DATABASE - MIRRORED, sa isang sulat. Isang row lang kada customer kada araw (PAGE + PSID; ang row ng import na walang PSID:
 * PAGE + fb_name sa parehong araw): ang bukas na row (walang STATUS) mula sa huling 24 oras ay ina-update, ang final na row
 * (PROCEED, may courier status o waybill) ay hindi na ginagalaw, at ang CANNOT PROCEED ay hindi humaharang ng bagong order.
 */
class LiveOrder
{
    /** Ang user sa HISTORICAL LOGS ng mga pagbabagong ginawa ng encode (hindi tao: hindi binibilang sa staff reports). */
    public const USER = 'Likha encode';
    public const NOT_ACCEPTED = 'second order not accepted: row ';

    /** Ang mga column na maaaring baguhin ng update, at ang edited_* flag ng staff ng bawat isa. */
    private const UPDATABLE = [
        'ADDRESS' => 'edited_address', 'PROVINCE' => 'edited_province', 'CITY' => 'edited_city', 'BARANGAY' => 'edited_barangay',
        'ITEM_NAME' => 'edited_item_name', 'COD' => 'edited_cod', 'all_user_input' => null, 'extracted_details' => null, 'CXD' => null,
        'AI ANALYZE' => null, 'APP SCRIPT CHECKER' => null,
    ];
    private const PLACES = ['PROVINCE', 'CITY', 'BARANGAY'];

    public function create(OrderEncode $o): ?MacroOutput
    {
        // Ang retry pagkatapos ng row na nagawa na: ang row na iyon, hindi bagong hanap (maaaring ibang oras ang makuha).
        if ($o->macro_output_id && ($made = MacroOutput::find($o->macro_output_id))) return $made;

        $v = $this->values($o);
        $row = MacroOutput::create(array_filter($v, fn ($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY) + [
            'TIMESTAMP' => $v['_timestamp'],
            'ts_date' => self::day($v['_timestamp']),
            'PAGE' => $v['_page'],
            'FULL NAME' => $v['_full_name'],
            'fb_name' => $v['_fb_name'],
            'PHONE NUMBER' => $v['_phone'],
            'shop_details' => $v['_shop'],
            'SHOP DETAILS' => $v['_shop'],
            'STATUS' => $v['_proceed'] ? 'PROCEED' : null,
            'botcake_psid' => $o->psid ? (string) $o->psid : null,
        ]);
        $o->update(['macro_output_id' => $row->id]);
        $this->afterImport($v['_timestamp']);

        return $row;
    }

    /**
     * Ang row ng customer na tatamaan ng order na ito.
     * @return array{final?: MacroOutput, open?: MacroOutput} final = hindi na tatanggapin; open = i-a-update; wala = bagong row
     */
    public function find(OrderEncode $o, Input $in): array
    {
        $timestamp = self::timestamp($in->get('date'));
        $at = self::time($timestamp);
        $day = self::day($timestamp);
        $page = $in->get('page');
        $fbName = $in->get('name');
        $psid = $o->psid ? (string) $o->psid : '';
        // Walang PSID at walang fb_name: walang makikilalang customer (ang blangkong kondisyon ay tutugma sa lahat ng row ng page).
        if ($psid === '' && trim($fbName) === '') return [];

        $rows =MacroOutput::query()->where('PAGE', $page)
            ->where('ts_date', '>=', Carbon::parse($day)->subDay()->toDateString())->where('ts_date', '<=', $day . ' 23:59:59')
            ->where(function ($q) use ($psid, $fbName, $day) {
                if ($psid !== '') $q->where('botcake_psid', $psid);
                // Ang row ng import (walang PSID): parehong fb_name sa parehong araw.
                if (trim($fbName) !== '') {
                    $q->orWhere(fn ($s) => $s->where(fn ($n) => $n->whereNull('botcake_psid')->orWhere('botcake_psid', ''))->where('fb_name', $fbName)->where('ts_date', '<=', $day . ' 23:59:59')->where('ts_date', '>=', $day));
                }
            })
            ->orderByDesc('id')->limit(20)->get();

        $open = null;
        foreach ($rows as $r) {
            $status = strtoupper(trim((string) $r->STATUS));
            $final = ($status !== '' && $status !== 'CANNOT PROCEED') || trim((string) $r->waybill) !== '';
            if ($final && substr((string) $r->ts_date, 0, 10) === $day) return ['final' => $r];
            if ($final || $status !== '' || $open) continue;
            $rowAt = self::time((string) $r->TIMESTAMP);
            if ($at && $rowAt && abs($at->diffInMinutes($rowAt, false)) <= 24 * 60) $open = $r;
        }

        return $open ? ['open' => $open] : [];
    }

    /**
     * Ang bukas na row ng customer ngayon (Encode order na walang Upload sa takbo): PAGE + PSID, walang STATUS at waybill, at
     * ang TIMESTAMP ay nasa loob ng 24 oras (lampas man ng hatinggabi). Ang pinakabago, o null.
     */
    public function openRow(string $page, string $psid): ?MacroOutput
    {
        if (trim($page) === '' || trim($psid) === '' || $psid === '0') return null;
        $now = now('Asia/Manila');
        $rows = MacroOutput::query()->where('PAGE', $page)->where('botcake_psid', $psid)
            ->where('ts_date', '>=', $now->copy()->subDay()->toDateString())->orderByDesc('id')->limit(20)->get();

        return $rows->first(function (MacroOutput $r) use ($now) {
            $at = self::time((string) $r->TIMESTAMP);

            return trim((string) $r->STATUS) === '' && trim((string) $r->waybill) === '' && $at && abs($now->diffInMinutes($at, false)) <= 24 * 60;
        });
    }

    /**
     * Ang re-encode ng bukas na row: ang address, item, COD, usapan at resulta lang; hindi ang field na inayos na ng staff
     * (edited_* o linya ng tao sa HISTORICAL LOGS), at kapag walang STATUS lang (isang kondisyonal na UPDATE).
     * @return bool false kapag nagka-STATUS na ang row (hindi na ginalaw)
     */
    public function update(MacroOutput $row, OrderEncode $o): bool
    {
        $v = $this->values($o);
        $staff = self::staffFields((string) $row->{'HISTORICAL LOGS'});
        $changes = [];
        $keptPlace = false;
        foreach (self::UPDATABLE as $col => $flag) {
            $new = $v[$col];
            $old = $row->{$col};
            // Ang blangkong sagot (hal. GPT timeout) ay hindi nagbubura ng may lamang column.
            if ((string) $new === (string) $old || (trim((string) $new) === '' && trim((string) $old) !== '')) continue;
            if (($flag && !empty($row->{$flag})) || in_array($col, $staff, true)) {
                if (in_array($col, self::PLACES, true)) $keptPlace = true;
                continue;
            }
            $changes[$col] = $new;
        }
        // PROCEED lang kapag ang lugar na nasa row ay ang mismong sinuri ng encode.
        if ($v['_proceed'] && !$keptPlace) $changes['STATUS'] = 'PROCEED';

        return $this->write($row, $changes);
    }

    /**
     * Encode order: ang resulta ng encode sa row ng Upload to macro: ang address, ang resulta, ang pangalan at phone
     * mula sa usapan, at PROCEED ayon sa unang encode. Hindi ang field na inayos ng staff (edited_* o linya ng tao), hindi ang
     * blangkong sagot sa may laman, at walang ginagalaw kapag may STATUS o waybill na.
     * @return bool false kapag may STATUS o waybill na ang row
     */
    public function encodeRow(MacroOutput $row, OrderEncode $o): bool
    {
        if (trim((string) $row->STATUS) !== '' || trim((string) $row->waybill) !== '') return false;
        $v = $this->values($o);
        $out = (array) ($o->outputs ?? []);
        $in = new Input((array) ($o->input['fields'] ?? []));
        $new = [
            'ADDRESS' => $v['ADDRESS'], 'PROVINCE' => $v['PROVINCE'], 'CITY' => $v['CITY'], 'BARANGAY' => $v['BARANGAY'],
            'AI ANALYZE' => $v['AI ANALYZE'], 'APP SCRIPT CHECKER' => $v['APP SCRIPT CHECKER'],
            'FULL NAME' => self::chatName((string) ($out['name'] ?? ''), (string) $row->{'FULL NAME'}),
            // Ang valid na phone ng usapan (09…): ang nakuha ng encode, kung hindi ang iisang numero sa chat o sa extracted details.
            'PHONE NUMBER' => OrderPhone::forOrder((string) ($out['phone'] ?? ''), $in->get('input'), $in->get('cxd')),
        ];
        $flags = ['FULL NAME' => 'edited_full_name', 'PHONE NUMBER' => 'edited_phone_number'] + self::UPDATABLE;
        $staff = self::staffFields((string) $row->{'HISTORICAL LOGS'});
        $changes = [];
        $keptPlace = false;
        foreach ($new as $col => $value) {
            $old = $row->{$col};
            if ((string) $value === (string) $old || (trim((string) $value) === '' && trim((string) $old) !== '')) continue;
            if ((!empty($flags[$col]) && !empty($row->{$flags[$col]})) || in_array($col, $staff, true)) {
                if (in_array($col, self::PLACES, true)) $keptPlace = true;
                continue;
            }
            $changes[$col] = $value;
        }
        if ($v['_proceed'] && !$keptPlace) $changes['STATUS'] = 'PROCEED';

        return $this->write($row, $changes);
    }

    /**
     * Ang pangalan mula sa usapan (Unicode NFC, walang sobrang espasyo), o ang dati kapag mas masama ang bago: blangko, may
     * numero, walang letra, "unknown"/"n/a", o bahagi lang ng dating pangalan.
     */
    private static function chatName(string $new, string $old): string
    {
        if (class_exists(\Normalizer::class)) $new = (string) \Normalizer::normalize($new, \Normalizer::FORM_C);
        $new = trim((string) preg_replace('/\s+/u', ' ', $new));
        $lower = mb_strtolower($new);
        $oldLower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $old)));
        $bad = $new === '' || mb_strlen($new) < 2 || mb_strlen($new) > 100 || preg_match('/\d/u', $new) || !preg_match('/\p{L}/u', $new)
            || in_array($lower, ['unknown', 'n/a', 'na', 'none', 'null', 'not provided'], true);
        if ($bad || $lower === $oldLower || ($oldLower !== '' && mb_strlen($lower) < mb_strlen($oldLower) && str_contains($oldLower, $lower))) return $old;

        return $new;
    }

    /**
     * Upload to macro (Insert): ang hilaw na row ng order, walang encode. Ang FULL NAME ay ang FB name at ang PHONE NUMBER ay ang
     * phone ng flow (hal. ang sagot ng GPT; 09… kapag valid); walang STATUS at walang address hanggang sa Encode order.
     */
    public function insertRaw(OrderEncode $o, Input $in): MacroOutput
    {
        $timestamp = self::timestamp($in->get('date'));
        $shop = $in->get('shop');
        $phone = trim($in->get('phone'));
        $price = trim(str_replace([',', '₱'], '', $in->get('price')));
        $ip = Parse::itemPrice($shop);
        $row = MacroOutput::create([
            'TIMESTAMP' => $timestamp, 'ts_date' => self::day($timestamp), 'PAGE' => $in->get('page'), 'fb_name' => $in->get('name'),
            'FULL NAME' => $in->get('name'),
            'PHONE NUMBER' => \App\Services\BotcakeCustomerMatcher::normPhone($phone) ?: ($phone !== '' ? mb_substr($phone, 0, 50) : null),
            'ITEM_NAME' => $ip['item'] ?: null,
            'COD' => preg_match('/PRICE:\s*₱\s*([0-9][\d,]*(?:\.\d{1,2})?)/iu', $shop, $m) ? str_replace(',', '', $m[1]) : ($price !== '' ? mb_substr($price, 0, 50) : null),
            'shop_details' => $shop, 'SHOP DETAILS' => $shop,
            'all_user_input' => Encoder::allUserInput($in),
            'extracted_details' => $in->get('cxd'), 'CXD' => $in->get('cxd'),
            'STATUS' => null,
            'botcake_psid' => $o->psid ? (string) $o->psid : null,
        ]);
        $o->update(['macro_output_id' => $row->id]);
        DB::afterCommit(fn () => $this->afterImport($timestamp));

        return $row;
    }

    /**
     * Upload to macro (Update): ang usapan ng bukas na row ay ang usapan ng order na ito (ang dati + ang bagong mensahe, Conversation).
     * Ang usapan at ang extracted details lang; hindi ang may linya ng tao. false kapag nagka-STATUS na ang row.
     */
    public function appendRaw(MacroOutput $row, Input $in): bool
    {
        $staff = self::staffFields((string) $row->{'HISTORICAL LOGS'});
        $changes = [];
        foreach (['all_user_input' => Encoder::allUserInput($in), 'extracted_details' => $in->get('cxd'), 'CXD' => $in->get('cxd')] as $col => $new) {
            if ((string) $new === (string) $row->{$col} || trim((string) $new) === '' || in_array($col, $staff, true)) continue;
            $changes[$col] = $new;
        }

        return $this->write($row, $changes);
    }

    /**
     * Ang mga pagbabago sa row na walang STATUS pa, sa isang kondisyonal na UPDATE, na may linya sa HISTORICAL LOGS ang bawat isa.
     * @return bool false kapag nagka-STATUS na ang row (hindi na ginalaw); true kapag walang babaguhin
     */
    private function write(MacroOutput $row, array $changes): bool
    {
        if (!$changes) return true;

        $stamp = now('Asia/Manila')->format('Y-m-d H:i:s');
        $lines = [];
        foreach ($changes as $col => $new) $lines[] = $stamp . '|' . self::USER . '|' . $col . '|' . self::logValue($row->{$col}) . '|' . self::logValue($new);
        $logs = trim((string) $row->{'HISTORICAL LOGS'});
        $changes['HISTORICAL LOGS'] = ($logs === '' ? '' : $logs . "\n") . implode("\n", $lines);

        $STATUS = DB::getQueryGrammar()->wrap('STATUS');

        return MacroOutput::query()->whereKey($row->id)
            ->where(fn ($s) => $s->whereNull('STATUS')->orWhereRaw("TRIM({$STATUS}) = ''"))
            ->update($changes) === 1;
    }

    /** Ang mga column ng row mula sa encode (pareho sa bagong row at sa update); ang may "_" ay hindi column. */
    private function values(OrderEncode $o): array
    {
        $f = (array) ($o->input['fields'] ?? []);
        $out = (array) ($o->outputs ?? []);
        $in = new Input($f);

        $timestamp = self::timestamp($in->get('date'));
        $fbName = $in->get('name');
        // Gaya ng Likha import: walang fb name, ang phone ng order; kung hindi, ang 09 sa phone column.
        $namePhone = trim($fbName) === '' ? OrderPhone::forOrder($in->get('phone'), $in->get('input'), $in->get('cxd')) : '';
        $likhaPhone = $namePhone !== '' ? $namePhone : (preg_match('/09\d{9}/', $in->get('phone'), $m) ? $m[0] : null);

        $ip = Parse::itemPrice($in->get('shop'));
        $likhaCod = preg_match('/PRICE:\s*₱\s*([0-9][\d,]*(?:\.\d{1,2})?)/iu', $in->get('shop'), $m) ? str_replace(',', '', $m[1]) : null;
        $remark = (string) ($out['remark'] ?? '');
        $final = (string) ($out['final'] ?? '');
        // Hold ng panuntunan (spec 135): hindi ✅ ang ilalagay, kung hindi ay gagawin itong PROCEED ng Validate.
        $hold = (array) ($out['hold'] ?? []);
        if ($hold) {
            $final = 'TO FIX';
            $remark = mb_substr('HOLD ' . ($hold['rule'] ?? '') . ': ' . ($hold['reason'] ?? ''), 0, 250);
        }

        return [
            '_timestamp' => $timestamp, '_page' => $in->get('page'), '_fb_name' => $fbName, '_shop' => $in->get('shop'),
            '_full_name' => trim($fbName) !== '' ? $fbName : (string) ($out['name'] ?? ''),
            '_phone' => $likhaPhone ?: (($out['phone'] ?? '') !== '' ? mb_substr((string) $out['phone'], 0, 50) : null),
            '_proceed' => (bool) ($out['proceed'] ?? false),
            'ADDRESS' => (string) ($out['address_line'] ?? ''),
            'PROVINCE' => (string) ($out['province'] ?? ''),
            'CITY' => (string) ($out['city'] ?? ''),
            'BARANGAY' => (string) ($out['barangay'] ?? ''),
            'ITEM_NAME' => ($out['item'] ?? '') !== '' ? $out['item'] : ($ip['item'] ?: null),
            'COD' => ($out['cod'] ?? '') !== '' ? $out['cod'] : $likhaCod,
            'all_user_input' => Encoder::allUserInput($in),
            'extracted_details' => $in->get('cxd'),
            'CXD' => $in->get('cxd'),
            // Ang AI ANALYZE ay ang remark ng encode; kapag blangko, ang flag ng Likha import (walang pangalan at phone).
            'AI ANALYZE' => $remark !== '' ? $remark : (trim($fbName) === '' && $namePhone === '' ? OrderIdentity::NO_NAME_NO_PHONE : null),
            'APP SCRIPT CHECKER' => $final,
        ];
    }

    /** Ang mga field na may linya ng tao (hindi ang encode) sa HISTORICAL LOGS ("ts|user|FIELD|old|new"). */
    private static function staffFields(string $logs): array
    {
        $fields = [];
        foreach (preg_split('/\r\n|\n|\r/', $logs) as $line) {
            $p = explode('|', $line);
            if (count($p) >= 5 && trim($p[1]) !== self::USER) $fields[] = trim($p[2]);
        }

        return $fields;
    }

    /** Gaya ng $safe ng MacroOutputController::updateField, at maikli (ang usapan ay mahaba). */
    private static function logValue(mixed $v): string
    {
        return mb_substr(trim(str_replace('|', '/', str_replace(["\r", "\n"], ' ', (string) $v))), 0, 200);
    }

    /** "HH:mm dd-MM-yyyy": ang mga anyo na tinatanggap ng imports; kung hindi mabasa, ang oras ngayon (Manila). */
    public static function timestamp(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{2}:\d{2} \d{2}-\d{2}-\d{4}$/', $raw)) return $raw;
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'G:i d-m-Y', 'H:i:s d-m-Y'] as $format) {
            if ($d = \DateTime::createFromFormat($format, $raw)) return $d->format('H:i d-m-Y');
        }

        return now('Asia/Manila')->format('H:i d-m-Y');
    }

    /** Ang araw ng order (Y-m-d) mula sa TIMESTAMP, gaya ng trigger ng ts_date. */
    public static function day(string $timestamp): string
    {
        return preg_match('/(\d{2})-(\d{2})-(\d{4})$/', $timestamp, $m) ? $m[3] . '-' . $m[2] . '-' . $m[1] : now('Asia/Manila')->toDateString();
    }

    /** Ang oras ng TIMESTAMP ("HH:mm dd-MM-yyyy") sa Manila, o null. */
    private static function time(string $timestamp): ?Carbon
    {
        try {
            return preg_match('/^\d{2}:\d{2} \d{2}-\d{2}-\d{4}$/', trim($timestamp)) ? Carbon::createFromFormat('H:i d-m-Y', trim($timestamp), 'Asia/Manila') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Gaya ng imports: ang main item ng petsa at ang cache ng /owner/private; hindi nagpapabagsak ng order. */
    private function afterImport(string $timestamp): void
    {
        try {
            if (preg_match('/(\d{2})-(\d{2})-(\d{4})$/', $timestamp, $dm)) {
                app(\App\Services\DailyPrimaryItemService::class)->recomputeDates([$dm[3] . '-' . $dm[2] . '-' . $dm[1]]);
            }
            \App\Http\Controllers\OwnerPrivateController::bumpCacheVersion();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
