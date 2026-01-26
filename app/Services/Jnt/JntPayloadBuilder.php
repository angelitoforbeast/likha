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

        $ec = config('jnt.credentials.eccompanyid');
        $cust = config('jnt.credentials.customerid');

        // Environment: doc says staging=yes, production=no(default)
        $envYesNo = (string)($opts['environment'] ?? (config('jnt.env') === 'sandbox' ? 'yes' : 'no'));

        // txlogisticid: must be your internal unique order number
        // Use macro_output id if available
        $tx = $opts['txlogisticid']
            ?? ('LIKHA-' . ($row['id'] ?? Str::uuid()->toString()) . '-' . $now->format('YmdHis'));

        // Receiver fields (adjust keys to your actual macro_output columns)
        $receiverName  = (string)($row['full_name'] ?? $row['FULL_NAME'] ?? $row['consignee'] ?? '');
        $receiverPhone = (string)($row['phone_number'] ?? $row['PHONE_NUMBER'] ?? $row['consignee_phone'] ?? '');

        $prov = (string)($row['province'] ?? $row['PROVINCE'] ?? '');
        $city = (string)($row['city'] ?? $row['CITY'] ?? '');
        $brgy = (string)($row['barangay'] ?? $row['BARANGAY'] ?? '');

        $addr1 = (string)($row['address'] ?? $row['ADDRESS'] ?? $row['consignee_address'] ?? '');
        // If you already have "Address Line 1" in your system, prefer it:
        $addrLine1 = (string)($row['address_line_1'] ?? $row['Address Line 1'] ?? $addr1);

        // COD/itemsvalue
        $cod = (string)($row['cod'] ?? $row['COD'] ?? $row['cod_amt'] ?? $row['COD Amt'] ?? '0');
        $cod = preg_replace('/[^\d.]/', '', $cod);
        if ($cod === '' || !is_numeric($cod)) $cod = '0';

        // Item name
        $itemName = (string)($row['item_name'] ?? $row['ITEM_NAME'] ?? 'Item');

        // Weight (kg) – default safe
        $weight = (string)($opts['weight'] ?? '0.5');

        // Sender: ideally from your company config, not from row
        $sender = [
            'name'    => (string)($opts['sender_name'] ?? config('jnt.sender.name', 'INCEPXION')),
            'phone'   => (string)($opts['sender_phone'] ?? config('jnt.sender.phone', '09170000000')),
            'mobile'  => (string)($opts['sender_mobile'] ?? config('jnt.sender.mobile', '09170000000')),
            'prov'    => (string)($opts['sender_prov'] ?? config('jnt.sender.prov', 'METRO-MANILA')),
            'city'    => (string)($opts['sender_city'] ?? config('jnt.sender.city', 'TAGUIG')),
            'area'    => (string)($opts['sender_area'] ?? config('jnt.sender.area', 'BAGUMBAYAN')),
            'address' => (string)($opts['sender_address'] ?? config('jnt.sender.address', '')),
        ];

        $receiver = [
            'name'    => $receiverName ?: 'N/A',
            'phone'   => $receiverPhone ?: 'N/A',
            'mobile'  => $receiverPhone ?: 'N/A',
            'prov'    => self::normalizeProv($prov),
            'city'    => self::normalizeCity($city),
            'area'    => self::normalizeArea($brgy),
            'address' => trim($addrLine1) ?: 'N/A',
        ];

        $payload = [
            'actiontype'     => 'add',
            'environment'    => $envYesNo,
            'eccompanyid'    => $ec,
            'customerid'     => $cust,
            'txlogisticid'   => $tx,

            'ordertype'      => '1',
            'servicetype'    => '6',
            'deliverytype'   => '1',

            'sender'         => $sender,
            'receiver'       => $receiver,

            'createordertime'=> $now->format('Y-m-d H:i:s'),
            'sendstarttime'  => $now->format('Y-m-d') . ' 09:00:00',
            'sendendtime'    => $now->format('Y-m-d') . ' 18:00:00',

            'paytype'        => '1',
            'weight'         => $weight,
            'itemsvalue'     => (string)$cod,
            'totalquantity'  => '1',
            'remark'         => (string)($opts['remark'] ?? 'LIKHA'),

            // recommended add
            'isInsured'      => '0',

            'items' => [[
                'itemname'  => $itemName ?: 'Item',
                'number'    => '1',
                'itemvalue' => (string)$cod,
                'desc'      => $itemName ?: 'Item',
            ]],
        ];

        return $payload;
    }

    // Keep these simple; later you can enforce your exact PH normalization rules
    protected static function normalizeProv(string $v): string
    {
        $v = strtoupper(trim($v));
        $v = str_replace(['.', ','], '', $v);
        $v = preg_replace('/\s+/', '-', $v);
        // common: Metro Manila format
        if (in_array($v, ['NCR','METRO-MANILA','METROMANILA','MANILA'], true)) return 'METRO-MANILA';
        return $v ?: 'N/A';
    }

    protected static function normalizeCity(string $v): string
    {
        $v = strtoupper(trim($v));
        $v = str_replace(['.', ','], '', $v);
        $v = preg_replace('/\s+/', '-', $v);
        return $v ?: 'N/A';
    }

    protected static function normalizeArea(string $v): string
    {
        $v = strtoupper(trim($v));
        $v = str_replace(['.', ','], '', $v);
        $v = preg_replace('/\s+/', '-', $v);
        return $v ?: 'N/A';
    }
}
