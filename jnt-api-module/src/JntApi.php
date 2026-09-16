<?php

namespace App\Services\Jnt;

use Illuminate\Support\Facades\Http;

/**
 * J&T Express PH — portable API client.
 *
 * Self-contained: config('jnt.*') lang ang dependency (walang app-specific models).
 * Lahat ng request ay form-POST na may signed `logistics_interface` (JSON) +
 * `data_digest`. Ginagawang array ang lahat ng response.
 *
 * Basic usage:
 *   $jnt = JntApi::fromConfig();
 *   $status = $jnt->status('TXLOGISTICID_123');          // ORDERQUERY (order-level status)
 *   $track  = $jnt->track('WAYBILL_BILLCODE');           // TRACKQUERY (scan timeline)
 *   $res    = $jnt->createOrder($payloadArray);          // ORDERCREATE
 *   $jnt->cancelOrder('TXLOGISTICID_123', 'reason');     // ORDERCANCEL
 *   $jnt->printWaybill('WAYBILL_BILLCODE');              // BILLQUERY (label)
 *
 * Debug: $jnt->lastRequest / $jnt->lastResponse pagkatapos ng tawag.
 */
class JntApi
{
    /** @var array<string,mixed> Huling request na ipinadala (para sa debugging). */
    public array $lastRequest = [];
    /** @var array<string,mixed> Huling raw response. */
    public array $lastResponse = [];

    public function __construct(
        protected string $baseUrl,
        protected string $eccompanyid,
        protected string $customerid,
        protected string $secret,
        protected array $endpoints = [],
        protected array $msgTypes = [],
        protected string $signMode = 'md5_hex_base64',
        protected ?string $apiKey = null,
        protected int $timeout = 30,
    ) {}

    /** Buuin mula sa config/jnt.php. */
    public static function fromConfig(): self
    {
        $creds = (array) config('jnt.credentials', []);
        $ec     = (string) ($creds['eccompanyid'] ?? '');
        $cust   = (string) ($creds['customerid'] ?? '');
        $secret = (string) ($creds['secret'] ?? '');
        $apiKey = $creds['api_key'] ?? null;
        $apiKey = (is_string($apiKey) && trim($apiKey) !== '') ? $apiKey : null;

        if ($ec === '' || $cust === '' || $secret === '') {
            throw new \RuntimeException('J&T config missing: itakda ang JNT_ECCOMPANYID, JNT_CUSTOMERID, JNT_SECRET sa .env');
        }

        return new self(
            baseUrl: rtrim((string) config('jnt.base_url'), '/'),
            eccompanyid: $ec,
            customerid: $cust,
            secret: $secret,
            endpoints: (array) config('jnt.endpoints', []),
            msgTypes: (array) config('jnt.msg_types', []),
            signMode: (string) config('jnt.signing.mode', 'md5_hex_base64'),
            apiKey: $apiKey,
            timeout: (int) config('jnt.timeout', 30),
        );
    }

    // ── STATUS (ORDERQUERY) ──────────────────────────────────────────────────

    /**
     * Kunin ang order-level status ng ISANG shipment (by txlogisticid / serialnumber).
     * Returns normalized array (order_status, times, weight, freight, receiver, raw)
     * o null kung walang order na nahanap.
     *
     * @return array<string,mixed>|null
     */
    public function status(string $txlogisticid): ?array
    {
        $map = $this->statusMany([$txlogisticid]);
        return $map[$txlogisticid] ?? null;
    }

    /**
     * Bulk: kunin ang status ng maraming txlogisticid.
     * Returns [ txlogisticid => normalizedStatusArray ] (kung ano lang ang nahanap).
     *
     * @param  string[] $txlogisticids
     * @return array<string,array<string,mixed>>
     */
    public function statusMany(array $txlogisticids): array
    {
        $out = [];
        foreach ($this->queryRaw($txlogisticids) as $serial => $order) {
            $out[$serial] = $this->normalizeStatus($order);
        }
        return $out;
    }

    /**
     * Raw ORDERQUERY: [ txlogisticid => rawOrderArray ].
     * Isa-isang query (per J&T spec: isang serialnumber kada request).
     *
     * @param  string[] $txlogisticids
     * @return array<string,array<string,mixed>>
     */
    public function queryRaw(array $txlogisticids): array
    {
        $serials = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $txlogisticids)));
        $results = [];

        foreach ($serials as $serial) {
            $json = $this->postForm(
                $this->endpoint('queryOrder'),
                $this->msgType('query', 'ORDERQUERY'),
                [
                    'command'      => '1',
                    'serialnumber' => $serial, // = txlogisticid
                ]
            );
            $order = $json['responseitems'][0]['orderList'][0] ?? null;
            if (is_array($order)) {
                $results[$serial] = $order;
            }
        }

        return $results;
    }

    /** Linisin ang raw ORDERQUERY order → consistent na fields. */
    protected function normalizeStatus(array $order): array
    {
        $receiver = (array) ($order['receiver'] ?? []);
        $norm = fn ($s) => ($s = preg_replace('/\.\d+$/', '', trim((string) $s))) !== '' ? $s : null;

        return [
            'txlogisticid'      => $order['txlogisticid'] ?? null,
            'mailno'            => $order['mailno'] ?? null,           // waybill / billcode
            'order_status'      => $order['orderStatus'] ?? null,
            'create_order_time' => $norm($order['createordertime'] ?? null),
            'send_start_time'   => $norm($order['sendstarttime'] ?? null),
            'send_end_time'     => $norm($order['sendendtime'] ?? null),
            'weight'            => isset($order['weight']) ? (float) $order['weight'] : null,
            'charge_weight'     => isset($order['chargeWeight']) ? (float) $order['chargeWeight'] : null,
            'sumfreight'        => isset($order['sumfreight']) ? (float) $order['sumfreight'] : null,
            'offer_fee'         => isset($order['offerFee']) ? (float) $order['offerFee'] : null,
            'goods_value'       => isset($order['goodsValue']) ? (float) $order['goodsValue'] : null,
            'goods_names'       => $order['goodsNames'] ?? null,
            'receiver'          => [
                'name'    => $receiver['name'] ?? null,
                'phone'   => $receiver['phone'] ?? null,
                'prov'    => $receiver['prov'] ?? null,
                'city'    => $receiver['city'] ?? null,
                'area'    => $receiver['area'] ?? null,
                'address' => trim((string) ($receiver['address'] ?? '')) ?: null,
            ],
            'raw'               => $order,
        ];
    }

    // ── TRACK (TRACKQUERY) — scan timeline ──────────────────────────────────

    /**
     * Kunin ang tracking scan events (picked up → in transit → delivered).
     * @return array<string,mixed> raw J&T track response
     */
    public function track(string $billcode, string $lang = 'en'): array
    {
        return $this->postForm(
            $this->endpoint('track'),
            $this->msgType('track', 'TRACKQUERY'),
            ['billcode' => $billcode, 'lang' => $lang]
        );
    }

    // ── CREATE / CANCEL / PRINT ─────────────────────────────────────────────

    /**
     * Gumawa ng order. Ipasa ang buong J&T order payload bilang array
     * (tingnan ang README para sa halimbawang shape). Ibinabalik ang parsed JSON.
     * @param  array<string,mixed> $orderPayload
     * @return array<string,mixed>
     */
    public function createOrder(array $orderPayload): array
    {
        // Siguraduhing kasama ang account ids sa loob ng payload.
        $orderPayload += ['eccompanyid' => $this->eccompanyid, 'customerid' => $this->customerid];
        return $this->postForm($this->endpoint('create'), $this->msgType('create', 'ORDERCREATE'), $orderPayload, false);
    }

    /** Kanselahin ang order by txlogisticid. */
    public function cancelOrder(string $txlogisticid, ?string $reason = null): array
    {
        $biz = ['txlogisticid' => $txlogisticid];
        if ($reason !== null) $biz['reason'] = $reason;
        return $this->postForm($this->endpoint('cancel'), $this->msgType('cancel', 'ORDERCANCEL'), $biz);
    }

    /** Kunin ang printable waybill/label (BILLQUERY) by billcode. */
    public function printWaybill(string $billcode): array
    {
        return $this->postForm(
            $this->endpoint('print'),
            $this->msgType('print', 'BILLQUERY'),
            ['billCode' => $billcode]
        );
    }

    // ── Core: sign + form POST ──────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $bizPayload
     * @param  bool $addIds  isama ang eccompanyid/customerid sa logistics_interface
     * @return array<string,mixed>
     */
    protected function postForm(string $endpoint, string $msgType, array $bizPayload, bool $addIds = true): array
    {
        if ($addIds) {
            $bizPayload += ['eccompanyid' => $this->eccompanyid, 'customerid' => $this->customerid];
        }

        $logistics = json_encode($bizPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($logistics === false) {
            throw new \RuntimeException('Failed to json_encode logistics_interface');
        }

        $digest = $this->sign($logistics);
        $url    = $this->baseUrl . $endpoint;

        $form = [
            'logistics_interface' => $logistics,
            'data_digest'         => $digest,
            'msg_type'            => $msgType,
            'eccompanyid'         => $this->eccompanyid,
            'customerid'          => $this->customerid,
        ];

        $headers = ['Accept' => 'application/json'];
        if (!empty($this->apiKey)) $headers['X-API-KEY'] = $this->apiKey;

        $this->lastRequest = [
            'url'      => $url,
            'msg_type' => $msgType,
            'form'     => ['data_digest' => '***', 'msg_type' => $msgType, 'logistics_interface' => $logistics],
        ];

        $res = Http::timeout($this->timeout)->withHeaders($headers)->asForm()->post($url, $form);

        if (!$res->ok()) {
            throw new \RuntimeException("J&T HTTP {$res->status()}: " . substr($res->body(), 0, 300));
        }
        $ct = (string) $res->header('content-type');
        if (stripos($ct, 'json') === false) {
            throw new \RuntimeException("J&T non-JSON (content-type={$ct}): " . substr($res->body(), 0, 300));
        }

        $json = $res->json();
        $this->lastResponse = is_array($json) ? $json : ['raw' => $res->body(), 'http_status' => $res->status()];

        return is_array($json) ? $json : $this->lastResponse;
    }

    /**
     * data_digest signing.
     *   md5_hex_base64          => base64( md5_hex(li + secret) )        [VERIFIED]
     *   md5_hex_base64_urlencode=> urlencode(base64( md5_hex(li+secret)))
     *   md5_base64_urlencode    => urlencode(base64( md5_raw(li+secret)))
     */
    protected function sign(string $logistics): string
    {
        return match ($this->signMode) {
            'md5_hex_base64_urlencode' => urlencode(base64_encode(md5($logistics . $this->secret))),
            'md5_base64_urlencode'     => urlencode(base64_encode(md5($logistics . $this->secret, true))),
            default                    => base64_encode(md5($logistics . $this->secret)), // md5_hex_base64
        };
    }

    protected function endpoint(string $key): string
    {
        $ep = $this->endpoints[$key] ?? null;
        if (!$ep) throw new \RuntimeException("J&T endpoint '{$key}' wala sa config/jnt.php");
        return str_starts_with($ep, '/') ? $ep : '/' . $ep;
    }

    protected function msgType(string $key, string $default): string
    {
        return (string) ($this->msgTypes[$key] ?? $default);
    }
}
