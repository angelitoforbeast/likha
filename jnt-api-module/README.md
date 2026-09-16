# J&T Express PH — Portable API Module

Self-contained na J&T API client para sa **kahit anong Laravel app**. Kaya nito ang:

| Aksyon | Method | msg_type |
|---|---|---|
| Status ng order (order-level) | `status()` / `statusMany()` | `ORDERQUERY` |
| Tracking scan timeline | `track()` | `TRACKQUERY` |
| Gumawa ng order | `createOrder()` | `ORDERCREATE` |
| Kanselahin ang order | `cancelOrder()` | `ORDERCANCEL` |
| Print waybill / label | `printWaybill()` | `BILLQUERY` |

> Mahalaga: **hindi mo pwedeng i-set/baguhin ang delivery status sa J&T** — sila ang may-ari niyan. Ang kaya: gumawa/kanselahin ng order, at **basahin/i-sync** ang status (query + track). Kaya ang "update status" sa system mo = **i-pull mula J&T** (tingnan ang Status Sync sa baba). Pull-based ito — walang webhook (kung gusto mo ng real-time push, tanungin ang J&T kung may status-callback ang account mo).

---

## 1. Install (sa target website)

1. Kopyahin ang mga file:
   - `config/jnt.php` → `config/jnt.php`
   - `src/JntApi.php` → `app/Services/Jnt/JntApi.php` (namespace: `App\Services\Jnt`; palitan kung iba)
   - (opsyonal) `examples/SyncJntStatus.php` → `app/Console/Commands/SyncJntStatus.php`

2. Idagdag sa `.env` (galing sa J&T account mo — sandbox muna):
   ```env
   JNT_ENV=sandbox
   JNT_BASE_URL=https://jtapi.jtexpress.ph/jts-phl-order-api
   JNT_ECCOMPANYID=your_eccompanyid
   JNT_CUSTOMERID=your_customerid
   JNT_SECRET=your_signing_key
   # JNT_SIGN_MODE=md5_hex_base64   # default; palitan lang kung tumanggi ang endpoint
   ```

3. `php artisan config:clear`

Walang ibang dependency — Laravel's built-in `Http` client lang.

---

## 2. Usage

```php
use App\Services\Jnt\JntApi;

$jnt = JntApi::fromConfig();
```

### Status ng shipment (ORDERQUERY)
```php
// Isa
$s = $jnt->status('TXLOGISTICID_123');
// => ['order_status' => 'Delivered', 'mailno' => 'WAYBILL...', 'send_end_time' => '...',
//     'weight' => 0.5, 'sumfreight' => 85.0, 'receiver' => [...], 'raw' => [...]]  o null

echo $s['order_status'] ?? 'wala pa';

// Marami (bulk)
$map = $jnt->statusMany(['TX_1', 'TX_2', 'TX_3']);
foreach ($map as $tx => $info) {
    echo "$tx => {$info['order_status']}\n";
}
```

### Tracking timeline (TRACKQUERY)
```php
$track = $jnt->track('WAYBILL_BILLCODE');   // billcode = mailno / waybill number
// hanapin ang scan events sa $track (structure per J&T account)
```

### Create order (ORDERCREATE)
```php
$res = $jnt->createOrder([
    'txlogisticid' => 'YOUR_UNIQUE_ORDER_ID',   // ikaw ang gagawa nito (idempotency key)
    'ordertype'    => '1',
    'servicetype'  => '1',
    'sender' => [
        'name' => 'Warehouse', 'mobile' => '09170000000',
        'prov' => 'METRO-MANILA', 'city' => 'TAGUIG', 'area' => 'BAGUMBAYAN',
        'address' => 'Full sender address',
    ],
    'receiver' => [
        'name' => 'Juan Dela Cruz', 'mobile' => '09171234567',
        'prov' => 'METRO-MANILA', 'city' => 'MAKATI', 'area' => 'POBLACION',
        'address' => 'Full receiver address',
    ],
    'items' => [
        ['itemname' => 'Product A', 'number' => 1, 'itemvalue' => 499],
    ],
    'totalquantity' => 1,
    'itemsvalue'    => 499,
    'goodsType'     => 'ITN1',
    'paytype'       => 'PP_CASH',   // o COD ayon sa setup mo
    // ... idagdag ang iba pang required fields per J&T PH spec mo
]);
// Ang waybill/billcode ay nasa $res (per account response shape) → i-save mo.
```
> Ang eksaktong required fields ng create payload ay depende sa J&T PH account mo. Kunin sa J&T docs/onboarding ang kumpletong field list. Ang client ay nagsa-sign at nagpapadala lang ng array na ibinigay mo.

### Cancel / Print
```php
$jnt->cancelOrder('TXLOGISTICID_123', 'Customer cancelled');
$label = $jnt->printWaybill('WAYBILL_BILLCODE');   // BILLQUERY → label data/URL
```

### Debugging
```php
$jnt->status('TX_1');
dd($jnt->lastRequest, $jnt->lastResponse);
```

---

## 3. Status Sync (ang "update status ng shipments")

Pattern: kunin ang mga `txlogisticid` mula sa DB mo → `statusMany()` in chunks → i-update ang records mo. Halimbawa (assume may `shipments` table ka na may `txlogisticid` + `order_status`):

```php
use App\Services\Jnt\JntApi;
use Illuminate\Support\Facades\DB;

$jnt = JntApi::fromConfig();

$serials = DB::table('shipments')->whereNotNull('txlogisticid')
    ->pluck('txlogisticid')->all();

foreach (array_chunk($serials, 10) as $chunk) {
    foreach ($jnt->statusMany($chunk) as $tx => $info) {
        DB::table('shipments')->where('txlogisticid', $tx)->update([
            'order_status'   => $info['order_status'],
            'send_end_time'  => $info['send_end_time'],
            'sumfreight'     => $info['sumfreight'],
            'orderquery_raw' => json_encode($info['raw']),
            'updated_at'     => now(),
        ]);
    }
}
```

Ang `examples/SyncJntStatus.php` ay ready-to-use na artisan command version nito
(`php artisan jnt:sync-status`). Palitan lang ang table/column names ayon sa schema mo.
Para tuloy-tuloy, i-schedule sa `app/Console/Kernel.php` (hal. kada oras).

---

## 4. Signing (kung magka-problema)

Default: `md5_hex_base64` = `base64( md5_hex(logistics_interface + secret) )` — ito ang
verified working para sa create / query / print / status-sync.

Kung may endpoint na sumagot ng "sign error", subukan sa `.env`:
```env
JNT_SIGN_MODE=md5_base64_urlencode
```
(o `md5_hex_base64_urlencode`). I-`config:clear` pagkatapos.

## 5. Sandbox → Production
Palitan lang ang `JNT_BASE_URL` + credentials sa prod values ng account mo, at
`JNT_ENV=production`. Test muna lahat sa sandbox.
