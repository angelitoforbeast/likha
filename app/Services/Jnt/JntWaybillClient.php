<?php

namespace App\Services\Jnt;

use Illuminate\Support\Facades\Http;

class JntWaybillClient
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
     *
     * Accepts array of txlogisticid OR mailno values.
     * If you only have mailno, this may not work unless J&T allows querying by mailno as serialnumber.
     * Best: store txlogisticid and pass it here.
     */
    public function queryOrders(array $serialNumbers): array
    {
        $serialNumbers = array_values(array_filter(array_map(fn($v) => trim((string)$v), $serialNumbers)));
        $results = [];

        foreach (array_chunk($serialNumbers, 20) as $chunk) {

            foreach ($chunk as $serial) {
                $logistics = json_encode([
                    'eccompanyid'  => $this->eccompanyid,
                    'customerid'   => $this->customerid,
                    'command'      => '1',
                    'serialnumber' => $serial, // this is txlogisticid in your proven test
                ], JSON_UNESCAPED_UNICODE);

                $digest = $this->signDigest($logistics);

                $url = $this->baseUrl . $this->endpointQueryOrder;

                $res = Http::timeout((int) config('jnt.timeout', 30))
                    ->asForm()
                    ->post($url, [
                        'logistics_interface' => $logistics,
                        'data_digest'         => $digest,
                        'msg_type'            => $this->msgTypeOrderQuery, // ORDERQUERY
                        'eccompanyid'         => $this->eccompanyid,
                    ])
                    ->json();

                // expected shape: responseitems[0].orderList[0]
                $order = $res['responseitems'][0]['orderList'][0] ?? null;
                if (is_array($order)) {
                    // key by serial so frontend can map
                    $results[$serial] = $order;
                }
            }
        }

        return $results;
    }

    private function signDigest(string $logisticsInterfaceJson): string
    {
        // Your verified method:
        // md5hex = md5(logistics_interface + key) as hex lowercase
        // data_digest = base64( UTF-8 bytes of md5hex )
        $md5hex = md5($logisticsInterfaceJson . $this->secret);
        return base64_encode($md5hex);
    }
}
