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
            'picked up', 'departure', 'arrival', 'order created' => 'In Transit',
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
     * @return array{status:?string, scantype:string, scantime:?string, signingtime:?string, unmapped:bool, empty:bool}
     */
    public static function fromDetails(array $details): array
    {
        if (empty($details)) {
            return ['status' => null, 'scantype' => '', 'scantime' => null, 'signingtime' => null, 'unmapped' => false, 'empty' => true];
        }

        // Hanapin ang PINAKA-RECENT na milestone (details ay latest-first).
        $milestoneStatus = null;
        $milestoneScan   = null;
        foreach ($details as $d) {
            $st = strtolower(trim((string) ($d['scantype'] ?? '')));
            if ($st === 'return delivered') { $milestoneStatus = 'Returned';   $milestoneScan = $d; break; }
            if ($st === 'delivered')        { $milestoneStatus = 'Delivered';  $milestoneScan = $d; break; }
            if ($st === 'return register')  { $milestoneStatus = 'For Return'; $milestoneScan = $d; break; }
        }

        $latest         = $details[0];
        $latestScantype = trim((string) ($latest['scantype'] ?? ''));

        if ($milestoneStatus !== null) {
            $status         = $milestoneStatus;
            $reportScantype = trim((string) ($milestoneScan['scantype'] ?? $latestScantype));
        } else {
            $status         = self::mapScantype($latestScantype); // On Delivery→Delivering; Picked Up/Departure/Arrival→In Transit; else null
            $reportScantype = $latestScantype;
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

        return [
            'status'      => $status,
            'scantype'    => $reportScantype,
            'scantime'    => trim((string) ($latest['scantime'] ?? '')) ?: null,
            'signingtime' => $signing,
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
