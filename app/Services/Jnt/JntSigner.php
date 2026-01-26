<?php

namespace App\Services\Jnt;

class JntSigner
{
    /**
     * Common J&T-style digest:
     * data_digest = base64_encode(md5(logistics_interface + api_key, true))
     *
     * If your docs specify a different concatenation/order, change it here only.
     */
    public static function makeDigest(string $logisticsInterfaceJson, string $apiKey): string
    {
        $raw = md5($logisticsInterfaceJson . $apiKey, true);
        return base64_encode($raw);
    }
}
