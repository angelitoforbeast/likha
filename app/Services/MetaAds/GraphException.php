<?php

namespace App\Services\MetaAds;

use RuntimeException;

/** Mali mula sa Graph API. Ang mensahe ay walang token o secret (nililinis ng Graph::clean). */
class GraphException extends RuntimeException
{
    /** Mga code ng rate limit ng Marketing API: maghintay at subukan ulit. */
    public const RATE_LIMIT_CODES = [4, 17, 613, 80000, 80003, 80004];

    public function __construct(string $message, public readonly int $graphCode = 0)
    {
        parent::__construct($message);
    }

    /** 190 = expired o binawing token: kailangang mag-Reconnect. */
    public function tokenExpired(): bool
    {
        return $this->graphCode === 190;
    }

    public function rateLimited(): bool
    {
        return in_array($this->graphCode, self::RATE_LIMIT_CODES, true);
    }
}
