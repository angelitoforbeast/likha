<?php

namespace App\Services\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderIdentity;
use App\Services\OrderPhone;
use Throwable;

/**
 * Live: ang macro_output row na gagawin sana ng Likha import at ng Macro import mula sa isang row ng All Orders at ng
 * DATABASE - MIRRORED, sa isang sulat. Ang protected fields ng Macro import (FULL NAME, PHONE NUMBER, ADDRESS, PROVINCE,
 * CITY, BARANGAY, STATUS) ay sinusunod: ang unang may laman (ang galing sa chat) ang nananatili. Walang duplicate kailanman.
 */
class LiveOrder
{
    public function create(OrderEncode $o): ?MacroOutput
    {
        $f = (array) ($o->input['fields'] ?? []);
        $out = (array) ($o->outputs ?? []);
        $in = new Input($f);

        $timestamp = self::timestamp($in->get('date'));
        $page = $in->get('page');
        $fbName = $in->get('name');
        // Gaya ng Likha import: walang fb name, ang phone ng order; kung hindi, ang 09 sa phone column.
        $namePhone = trim($fbName) === '' ? OrderPhone::forOrder($in->get('phone'), $in->get('input'), $in->get('cxd')) : '';
        $likhaPhone = $namePhone !== '' ? $namePhone : (preg_match('/09\d{9}/', $in->get('phone'), $m) ? $m[0] : null);
        $allUserInput = Encoder::allUserInput($in);

        $existing = OrderIdentity::find($timestamp, $page, $fbName, $namePhone, $allUserInput);
        if ($existing) {
            $o->update(['macro_output_id' => $existing->id]);

            return $existing;
        }

        $ip = Parse::itemPrice($in->get('shop'));
        $likhaCod = preg_match('/PRICE:\s*₱\s*([0-9][\d,]*(?:\.\d{1,2})?)/iu', $in->get('shop'), $m) ? str_replace(',', '', $m[1]) : null;
        $remark = (string) ($out['remark'] ?? '');
        $final = (string) ($out['final'] ?? '');

        $row = MacroOutput::create([
            'TIMESTAMP' => $timestamp,
            'PAGE' => $page,
            'FULL NAME' => trim($fbName) !== '' ? $fbName : (string) ($out['name'] ?? ''),
            'fb_name' => $fbName,
            'PHONE NUMBER' => $likhaPhone ?: (($out['phone'] ?? '') !== '' ? mb_substr((string) $out['phone'], 0, 50) : null),
            'ADDRESS' => (string) ($out['address_line'] ?? ''),
            'PROVINCE' => (string) ($out['province'] ?? ''),
            'CITY' => (string) ($out['city'] ?? ''),
            'BARANGAY' => (string) ($out['barangay'] ?? ''),
            'ITEM_NAME' => ($out['item'] ?? '') !== '' ? $out['item'] : ($ip['item'] ?: null),
            'COD' => ($out['cod'] ?? '') !== '' ? $out['cod'] : $likhaCod,
            'all_user_input' => $allUserInput,
            'shop_details' => $in->get('shop'),
            'SHOP DETAILS' => $in->get('shop'),
            'extracted_details' => $in->get('cxd'),
            'CXD' => $in->get('cxd'),
            // Ang AI ANALYZE ay ang remark ng encode; kapag blangko, ang flag ng Likha import (walang pangalan at phone).
            'AI ANALYZE' => $remark !== '' ? $remark : (trim($fbName) === '' && $namePhone === '' ? OrderIdentity::NO_NAME_NO_PHONE : null),
            'APP SCRIPT CHECKER' => $final,
            'STATUS' => ($out['proceed'] ?? false) ? 'PROCEED' : null,
            'botcake_psid' => $o->psid ? (string) $o->psid : null,
        ]);
        $o->update(['macro_output_id' => $row->id]);
        $this->afterImport($timestamp);

        return $row;
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
