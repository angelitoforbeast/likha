<?php

namespace App\Support;

/**
 * Mapa ng J&T TRACKQUERY scan → from_jnts.status.
 *
 * Grounded sa opisyal na docs (ylopen.jtexpress.ph) + totoong prod scans:
 *   113 Delivered        → Delivered
 *   117 Return Delivered → Returned
 *   116 Return Register  → For Return
 *   112 On Delivery      → Delivering
 *   106 Picked Up / 109 Departure / 110 Arrival / Order Created → In Transit
 *
 * HINDI pa naka-map (sinasadya) — babad sa preview muna, huwag hulaan:
 *   118 Problematic, Delivery Fail, Picked Up Fail, at anumang di-kilalang scantype → null
 */
class JntScanStatusMapper
{
    /** scantype string → from_jnts status, o null kung hindi pa kilala. */
    public static function mapScantype(string $scantype): ?string
    {
        return match (strtolower(trim($scantype))) {
            'return delivered'                              => 'Returned',
            'return register'                               => 'For Return',
            'delivered'                                     => 'Delivered',
            'on delivery'                                   => 'Delivering',
            'picked up', 'departure', 'arrival', 'order created', 'port congested' => 'In Transit',
            default                                         => null,
        };
    }

    /**
     * Mula sa details array (latest-first) → resolved status info.
     *
     * MILESTONE-AWARE: kapag may naganap na Return Delivered / Delivered / Return
     * Register, yun ang totoong estado — HINDI ma-override ng mga sumunod na
     * transit scan (hal. Return Register tapos Departure/Arrival = return trip =
     * For Return pa rin, hindi In Transit). Kung walang milestone → latest scan.
     *
     * @param  array<int,array<string,mixed>> $details
     * @return array{status:?string, scantype:string, scantime:?string, signingtime:?string, rider_name:?string, rider_phone:?string, unmapped:bool, empty:bool}
     */
    public static function fromDetails(array $details): array
    {
        $empty = ['status'=>null,'scantype'=>'','scantime'=>null,'signingtime'=>null,'rider_name'=>null,'rider_phone'=>null,'unmapped'=>false,'empty'=>true];
        if (empty($details)) return $empty;

        // PINAKA-RECENT na milestone (details latest-first): Return Delivered / Delivered
        // / Return Register. NOTE: Problematic is NOT a milestone — failed-attempt lang;
        // per CSV ground truth, nananatiling Delivering habang nagre-retry (unless na-Return
        // Register o na-Deliver/Return). Ito ang nananaig kaysa transit scans (return trip).
        $milestoneStatus = null;
        $milestoneScan   = null;
        foreach ($details as $d) {
            $st = strtolower(trim((string) ($d['scantype'] ?? '')));
            if ($st === 'return delivered') { $milestoneStatus = 'Returned';   $milestoneScan = $d; break; }
            if ($st === 'delivered')        { $milestoneStatus = 'Delivered';  $milestoneScan = $d; break; }
            if ($st === 'return register')  { $milestoneStatus = 'For Return'; $milestoneScan = $d; break; }
        }

        if ($milestoneStatus !== null) {
            $status         = $milestoneStatus;
            $reportScantype = trim((string) ($milestoneScan['scantype'] ?? ''));
        } else {
            // Walang milestone → latest scan na HINDI Problematic (i-ignore ang Problematic).
            $status         = null;
            $reportScantype = trim((string) ($details[0]['scantype'] ?? ''));
            foreach ($details as $d) {
                $st = trim((string) ($d['scantype'] ?? ''));
                if (strtolower($st) === 'problematic') continue;
                $status         = self::mapScantype($st); // On Delivery→Delivering; transit→In Transit; else null
                $reportScantype = $st;
                break;
            }
        }

        // signingtime = scantime ng completion scan (Delivered o Return Delivered).
        $signing = null;
        if ($status === 'Delivered' || $status === 'Returned') {
            $target = $status === 'Returned' ? 'return delivered' : 'delivered';
            foreach ($details as $d) {
                if (strtolower(trim((string) ($d['scantype'] ?? ''))) === $target) {
                    $signing = trim((string) ($d['scantime'] ?? '')) ?: null;
                    break;
                }
            }
        }

        // Rider — latest "On Delivery" scan desc: "…sprinter【<CODE>_<name> : <phone>】…"
        // MAY GUARD: kailangang kumpleto ang pattern (name : phone). Hiwalay na
        // validation ang phone (PH mobile) at name (may titik, >=2 char, walang digit).
        // Kung mali/kulang → null (hindi isusulat).
        $riderName = null; $riderPhone = null;
        foreach ($details as $d) {
            if (strtolower(trim((string) ($d['scantype'] ?? ''))) !== 'on delivery') continue;
            $desc = (string) ($d['desc'] ?? '');
            if (preg_match('/sprinter【\s*(.+?)\s*:\s*([\d][\d\s\-]{7,})】/u', $desc, $m)) {
                // Phone — digits lang; dapat valid PH mobile.
                $ph = preg_replace('/\D/', '', (string) $m[2]);
                if (preg_match('/^(639\d{9}|63\d{10}|09\d{9}|9\d{9})$/', $ph)) {
                    $riderPhone = $ph;
                }
                // Name — tanggalin ang code prefix; dapat may titik, >=2 char, walang digit.
                $nm = trim((string) preg_replace('/^[A-Z0-9_]+_/', '', trim((string) $m[1])));
                if ($nm !== '' && mb_strlen($nm) >= 2 && preg_match('/\p{L}/u', $nm) && !preg_match('/\d/', $nm)) {
                    $riderName = $nm;
                }
            }
            break; // latest On Delivery lang
        }

        return [
            'status'      => $status,
            'scantype'    => $reportScantype,
            'scantime'    => trim((string) ($details[0]['scantime'] ?? '')) ?: null,
            'signingtime' => $signing,
            'rider_name'  => $riderName,
            'rider_phone' => $riderPhone,
            'unmapped'    => $status === null,
            'empty'       => false,
        ];
    }

    /**
     * Parse ng buong trackForJson response → [ billcode => detailsArray ].
     * Kaya ang DALAWANG hugis:
     *   responseitems[].tracesList[].{billcode, details}  (multi / docs)
     *   responseitems[].{billcode, details, success}      (flat / single prod)
     *
     * @param  array<string,mixed> $resp
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function parseResponse(array $resp): array
    {
        $out = [];
        foreach ((array) ($resp['responseitems'] ?? []) as $item) {
            if (!is_array($item)) continue;

            if (isset($item['tracesList']) && is_array($item['tracesList'])) {
                foreach ($item['tracesList'] as $t) {
                    if (!is_array($t)) continue;
                    $bc = trim((string) ($t['billcode'] ?? ''));
                    if ($bc !== '' && is_array($t['details'] ?? null)) {
                        $out[$bc] = $t['details'];
                    }
                }
            } elseif (isset($item['billcode'])) {
                $bc = trim((string) $item['billcode']);
                // isama lang kapag success (kung meron) at may details
                $ok = !isset($item['success']) || filter_var($item['success'], FILTER_VALIDATE_BOOLEAN) || $item['success'] === 'true';
                if ($bc !== '' && $ok && is_array($item['details'] ?? null)) {
                    $out[$bc] = $item['details'];
                }
            }
        }
        return $out;
    }
}
