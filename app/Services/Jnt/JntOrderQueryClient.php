<?php

namespace App\Services\Jnt;

use Illuminate\Support\Facades\Http;

class JntOrderQueryClient
{
    protected string $baseUrl;
    protected string $eccompanyid;
    protected string $customerid;
    protected string $secret;
    protected string $endpointQueryOrder;
    protected string $msgTypeOrderQuery;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('jnt.base_url'), '/');

        $this->eccompanyid = (string) data_get(config('jnt.credentials'), 'eccompanyid');
        $this->customerid  = (string) data_get(config('jnt.credentials'), 'customerid');
        $this->secret      = (string) data_get(config('jnt.credentials'), 'secret');

        $this->endpointQueryOrder = (string) data_get(config('jnt.endpoints'), 'queryOrder', '/api/order/queryOrder');
        $this->msgTypeOrderQuery  = (string) data_get(config('jnt.msg_types'), 'query', 'ORDERQUERY');

        if ($this->baseUrl === '' || $this->eccompanyid === '' || $this->customerid === '' || $this->secret === '') {
            throw new \RuntimeException('JNT config missing: base_url / eccompanyid / customerid / secret');
        }
    }

    /**
     * ORDERQUERY: Query order details by txlogisticid (serialnumber).
     * Returns: [ txlogisticid => orderArray ]
     */
    public function queryOrders(array $serialNumbers): array
    {
        $serialNumbers = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $serialNumbers)));
        $results = [];

        $url = $this->baseUrl . $this->endpointQueryOrder;

        foreach ($serialNumbers as $serial) {
            $logistics = json_encode([
                'eccompanyid'  => $this->eccompanyid,
                'customerid'   => $this->customerid,
                'command'      => '1',
                'serialnumber' => $serial, // txlogisticid
            ], JSON_UNESCAPED_UNICODE);

            $digest = $this->signDigest($logistics);

            $res = Http::timeout((int) config('jnt.timeout', 30))
                ->asForm()
                ->post($url, [
                    'logistics_interface' => $logistics,
                    'data_digest'         => $digest,
                    'msg_type'            => $this->msgTypeOrderQuery, // ORDERQUERY
                    'eccompanyid'         => $this->eccompanyid,
                ]);

            // ✅ Robust guards (avoid silent HTML/non-json surprises)
            if (!$res->ok()) {
                throw new \RuntimeException("J&T HTTP {$res->status()}: " . substr($res->body(), 0, 300));
            }

            $contentType = (string) $res->header('content-type');
            if (stripos($contentType, 'application/json') === false) {
                throw new \RuntimeException("J&T returned non-JSON (content-type={$contentType}): " . substr($res->body(), 0, 300));
            }

            $json = $res->json();

            $order = $json['responseitems'][0]['orderList'][0] ?? null;
            if (is_array($order)) {
                $results[$serial] = $order;
            }
        }

        return $results;
    }

    private function signDigest(string $logisticsInterfaceJson): string
    {
        // VERIFIED method:
        // md5hex = md5(logistics_interface + secret) as lowercase hex
        // data_digest = base64( UTF-8 bytes of md5hex ) => PHP: base64_encode(hexstring)
        $md5hex = md5($logisticsInterfaceJson . $this->secret);
        return base64_encode($md5hex);
    }
}
