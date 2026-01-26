<?php

namespace App\Services\Jnt;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class JntClient
{
    public array $lastRequest = [];
    public array $lastResponse = [];

    public function __construct(
        protected string $baseUrl,
        protected string $eccompanyid,
        protected string $customerid,
        protected string $secret,        // signing key ("key" in doc)
        protected ?string $apiKey = null, // optional header key
        protected int $timeoutSeconds = 30,
        protected array $endpoints = []
    ) {}

    public static function fromConfig(): self
    {
        $baseUrl = rtrim((string) config('jnt.base_url'), '/');

        $creds = (array) config('jnt.credentials', []);
        $endpoints = (array) config('jnt.endpoints', []);

        $ec = (string) ($creds['eccompanyid'] ?? '');
        $cust = (string) ($creds['customerid'] ?? '');
        $secret = (string) ($creds['secret'] ?? '');
        $apiKey = $creds['api_key'] ?? null;

        if ($ec === '' || $cust === '' || $secret === '') {
            throw new \RuntimeException('J&T config missing. Check JNT_ECCOMPANYID, JNT_CUSTOMERID, JNT_SECRET in .env');
        }

        // normalize empty string -> null
        $apiKey = is_string($apiKey) && trim($apiKey) === '' ? null : $apiKey;

        return new self(
            baseUrl: $baseUrl,
            eccompanyid: $ec,
            customerid: $cust,
            secret: $secret,
            apiKey: $apiKey,
            timeoutSeconds: (int) (config('jnt.timeout', 30)),
            endpoints: $endpoints,
        );
    }

    public function createOrder(array $bizPayload): array
    {
        $msgType = (string) config('jnt.msg_types.create', 'ORDERCREATE');
        $endpoint = $this->endpoint('create');

        return $this->postJnt($endpoint, $msgType, $bizPayload);
    }

    public function cancelOrder(array $bizPayload): array
    {
        $msgType = (string) config('jnt.msg_types.cancel', 'ORDERCANCEL');
        $endpoint = $this->endpoint('cancel');

        return $this->postJnt($endpoint, $msgType, $bizPayload);
    }

    public function queryOrder(array $bizPayload): array
    {
        $msgType = (string) config('jnt.msg_types.query', 'ORDERQUERY');
        $endpoint = $this->endpoint('queryOrder');

        return $this->postJnt($endpoint, $msgType, $bizPayload);
    }

    public function trackForJson(string $billcode, string $lang = 'en'): array
    {
        // per your doc table: TRACKQUERY
        $msgType = (string) config('jnt.msg_types.track', 'TRACKQUERY');
        $endpoint = $this->endpoint('trackForJson');

        $bizPayload = [
            'billcode' => $billcode,
            'lang'     => $lang,
        ];

        return $this->postJnt($endpoint, $msgType, $bizPayload);
    }

    protected function postJnt(string $endpoint, string $msgType, array $bizPayload): array
    {
        // Business payload often expects these inside logistics_interface as well
        $biz = array_merge($bizPayload, [
            'eccompanyid' => $this->eccompanyid,
            'customerid'  => $this->customerid,
        ]);

        $logisticsInterface = json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($logisticsInterface === false) {
            throw new \RuntimeException('Failed to JSON encode logistics_interface');
        }

        $dataDigest = $this->sign($logisticsInterface);

        // Protocol params (as form fields)
        $form = [
            'logistics_interface' => $logisticsInterface,
            'data_digest'         => $dataDigest,
            'msg_type'            => $msgType,
            'eccompanyid'         => $this->eccompanyid,
            // some gateways tolerate/expect customerid also at protocol-level
            'customerid'          => $this->customerid,
        ];

        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Accept' => 'application/json',
        ];

        // Only attach header if you actually have a separate header key.
        if (!empty($this->apiKey)) {
            $headers['X-API-KEY'] = $this->apiKey;
        }

        $this->lastRequest = [
            'url' => $url,
            'headers' => $this->redactHeaders($headers),
            'form' => $this->redactForm($form),
        ];

        $resp = $this->http()
            ->asForm()
            ->withHeaders($headers)
            ->post($url, $form);

        $decoded = $resp->json();

        if (!is_array($decoded)) {
            $decoded = [
                'http_status' => $resp->status(),
                'raw' => $resp->body(),
            ];
        }

        $this->lastResponse = $decoded;

        return $decoded;
    }

    /**
     * Signing per your doc:
     * data_digest = Base64( md5_hex(logistics_interface + key) )
     *
     * Note: form encoding already URL-encodes values. If you want explicit urlencode,
     * use md5_hex_base64_urlencode.
     */
    protected function sign(string $logisticsInterface): string
    {
        $mode = (string) config('jnt.signing.mode', 'md5_hex_base64');

        return match ($mode) {
            'md5_hex_base64' => base64_encode(md5($logisticsInterface . $this->secret)),
            'md5_hex_base64_urlencode' => urlencode(base64_encode(md5($logisticsInterface . $this->secret))),
            'md5_base64_urlencode' => urlencode(base64_encode(md5($logisticsInterface . $this->secret, true))),
            default => base64_encode(md5($logisticsInterface . $this->secret)),
        };
    }

    protected function endpoint(string $key): string
    {
        $ep = $this->endpoints[$key] ?? null;
        if (!$ep) {
            throw new \RuntimeException("J&T endpoint '{$key}' is not configured in config/jnt.php");
        }
        return Str::startsWith($ep, '/') ? $ep : '/' . $ep;
    }

    protected function http(): PendingRequest
    {
        return Http::timeout($this->timeoutSeconds);
    }

    protected function redactHeaders(array $headers): array
    {
        $out = $headers;
        foreach ($out as $k => $v) {
            $lk = strtolower($k);
            if (str_contains($lk, 'key') || str_contains($lk, 'token') || str_contains($lk, 'secret')) {
                $out[$k] = '***REDACTED***';
            }
        }
        return $out;
    }

    protected function redactForm(array $form): array
    {
        $out = $form;
        if (isset($out['data_digest'])) {
            $out['data_digest'] = '***REDACTED***';
        }
        return $out;
    }
}
