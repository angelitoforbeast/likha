<?php

namespace App\Services\Jnt;

use Illuminate\Support\Str;
use Carbon\Carbon;

class JntPayloadBuilder
{
    /**
     * Build a valid J&T create payload.
     * Input: associative array from macro_output (or likha_orders).
     *
     * Required fields per PH doc:
     * actiontype, environment(yes/no), eccompanyid, customerid, txlogisticid,
     * ordertype, servicetype, deliverytype, sender{}, receiver{},
     * createordertime, sendstarttime, sendendtime, paytype, weight,
     * itemsvalue(COD), totalquantity, items[], (isInsured recommended)
     */
    public static function buildCreateFromMacroOutput(array $row, array $opts = []): array
    {
        $now = Carbon::now('Asia/Manila');

        $ec   = (string) config('jnt.credentials.eccompanyid');
        $cust = (string) config('jnt.credentials.customerid');

        // Environment: doc says staging=yes, production=no(default)
        $envYesNo = (string)($opts['environment'] ?? (config('jnt.env') === 'sandbox' ? 'yes' : 'no'));

        // txlogisticid: must be your internal unique order number
        $tx = (string)($opts['txlogisticid']
            ?? ('LIKHA-' . ($row['id'] ?? Str::uuid()->toString()) . '-' . $now->format('YmdHis')));

        // Receiver RAW fields (DO NOT NORMALIZE prov/city/area/address)
        $receiverNameRaw  = (string)($row['full_name'] ?? $row['FULL_NAME'] ?? $row['consignee'] ?? '');
        $receiverPhoneRaw = (string)($row['phone_number'] ?? $row['PHONE_NUMBER'] ?? $row['consignee_phone'] ?? '');

        $provRaw = (string)($row['province'] ?? $row['PROVINCE'] ?? '');
        $cityRaw = (string)($row['city'] ?? $row['CITY'] ?? '');
        $brgyRaw = (string)($row['barangay'] ?? $row['BARANGAY'] ?? '');

        $addr1 = (string)($row['address'] ?? $row['ADDRESS'] ?? $row['consignee_address'] ?? '');
        $addrLine1 = (string)($row['address_line_1'] ?? $row['Address Line 1'] ?? $addr1);

        // COD/itemsvalue
        $cod = (string)($row['cod'] ?? $row['COD'] ?? $row['cod_amt'] ?? $row['COD Amt'] ?? '0');
        $cod = preg_replace('/[^\d.]/', '', $cod);
        if ($cod === '' || !is_numeric($cod)) $cod = '0';

        // Item name
        $itemName = (string)($row['item_name'] ?? $row['ITEM_NAME'] ?? 'Item');

        // Weight (kg)
        $weight = (string)($opts['weight'] ?? '0.5');

        // ✅ Keep receiver fields RAW (trim only)
        $prov = trim($provRaw);
        $city = trim($cityRaw);
        $brgy = trim($brgyRaw);
        $addr = trim((string)$addrLine1);

        // ✅ Phone normalize (safe; not related to B063)
        $receiverPhone = self::normalizePhone($receiverPhoneRaw);
        $receiverName  = trim($receiverNameRaw);

        // Sender from config (keep stable; you can also make these RAW if you want)
        $senderName   = (string)($opts['sender_name'] ?? config('jnt.sender.name', 'INCEPXION INC'));
        $senderPhone  = self::normalizePhone((string)($opts['sender_phone'] ?? config('jnt.sender.phone', '09170000000')));
        $senderMobile = self::normalizePhone((string)($opts['sender_mobile'] ?? config('jnt.sender.mobile', '09170000000')));

        $senderProv   = trim((string)($opts['sender_prov'] ?? config('jnt.sender.prov', 'METRO-MANILA')));
        $senderCity   = trim((string)($opts['sender_city'] ?? config('jnt.sender.city', 'TAGUIG')));
        $senderArea   = trim((string)($opts['sender_area'] ?? config('jnt.sender.area', 'BAGUMBAYAN')));
        $senderAddr   = trim((string)($opts['sender_address'] ?? config('jnt.sender.address', '')));

        $sender = [
            'name'    => $senderName !== '' ? $senderName : 'INCEPXION INC',
            'phone'   => $senderPhone !== '' ? $senderPhone : '09170000000',
            'mobile'  => $senderMobile !== '' ? $senderMobile : ($senderPhone !== '' ? $senderPhone : '09170000000'),
            'prov'    => $senderProv !== '' ? $senderProv : 'METRO-MANILA',
            'city'    => $senderCity !== '' ? $senderCity : 'TAGUIG',
            'area'    => $senderArea !== '' ? $senderArea : 'BAGUMBAYAN',
            'address' => $senderAddr !== '' ? $senderAddr : '',
        ];

        $receiver = [
            'name'    => $receiverName !== '' ? $receiverName : 'N/A',
            'phone'   => $receiverPhone !== '' ? $receiverPhone : 'N/A',
            'mobile'  => $receiverPhone !== '' ? $receiverPhone : 'N/A',

            // ✅ RAW from DB (trim only; NO hyphen conversion)
            'prov'    => $prov !== '' ? $prov : 'N/A',
            'city'    => $city !== '' ? $city : 'N/A',
            'area'    => $brgy !== '' ? $brgy : 'N/A',
            'address' => $addr !== '' ? $addr : 'N/A',
        ];

        return [
            'actiontype'      => 'add',
            'environment'     => $envYesNo,
            'eccompanyid'     => $ec,
            'customerid'      => $cust,
            'txlogisticid'    => $tx,

            'ordertype'       => '1',
            'servicetype'     => '6',
            'deliverytype'    => '1',

            'sender'          => $sender,
            'receiver'        => $receiver,

            'createordertime' => $now->format('Y-m-d H:i:s'),
            'sendstarttime'   => $now->format('Y-m-d') . ' 09:00:00',
            'sendendtime'     => $now->format('Y-m-d') . ' 18:00:00',

            'paytype'         => '1',
            'weight'          => $weight,
            'itemsvalue'      => (string)$cod,
            'totalquantity'   => '1',
            'remark'          => (string)($opts['remark'] ?? 'LIKHA'),
            'isInsured'       => '0',

            'items' => [[
                'itemname'  => $itemName !== '' ? $itemName : 'Item',
                'number'    => '1',
                'itemvalue' => (string)$cod,
                'desc'      => $itemName !== '' ? $itemName : 'Item',
            ]],
        ];
    }

    /**
     * ✅ J&T PH phone normalization:
     * - "9219723247" -> "09219723247"
     * - "639219..."  -> "0921..."
     * - keep as-is if already starts with 0 and 11 digits
     */
    protected static function normalizePhone(string $v): string
    {
        $v = trim($v);
        $digits = preg_replace('/\D+/', '', $v);

        if ($digits === '') return '';

        // 63xxxxxxxxxx -> 0xxxxxxxxxx
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        }

        // 9xxxxxxxxx -> 09xxxxxxxxx
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0' . $digits;
        }

        return $digits;
    }
}
