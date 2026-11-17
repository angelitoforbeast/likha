<?php

namespace App\Services\MetaAds;

use RuntimeException;

/** Mali mula sa Graph API. Ang mensahe ay walang token o secret (nililinis ng Graph::clean). */
class GraphException extends RuntimeException
{
    /** Mga code ng rate limit ng Marketing API: maghintay at subukan ulit. */
    public const RATE_LIMIT_CODES = [4, 17, 613, 80000, 80003, 80004];

    /**
     * Meta answers 1 ("An unknown error occurred") and 2 ("Service temporarily unavailable") for an insights request
     * that is too heavy or hit a busy server: the same request usually works a little later or in a smaller form.
     */
    public const TRANSIENT_CODES = [1, 2];

    /** 368: the app or account is temporarily blocked for abuse; asking again within the hour only extends the block. */
    public const BLOCK_CODES = [368];

    /**
     * @param bool $isTransient Meta's own `is_transient` flag, or no answer at all (timeout, connection reset)
     * @param int $httpStatus 0 when there was no HTTP answer
     */
    public function __construct(string $message, public readonly int $graphCode = 0, public readonly bool $isTransient = false, public readonly int $httpStatus = 0)
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

    public function blocked(): bool
    {
        return in_array($this->graphCode, self::BLOCK_CODES, true);
    }

    /**
     * Worth asking again: code 1/2, Meta's is_transient flag, a server error (5xx) without a Graph code, or no answer.
     * A rate limit is not counted here: it has its own waits. Anything else (400, permission, bad field) is permanent.
     */
    public function transient(): bool
    {
        if ($this->rateLimited() || $this->tokenExpired() || $this->blocked()) return false;

        return $this->isTransient || in_array($this->graphCode, self::TRANSIENT_CODES, true)
            || ($this->graphCode === 0 && $this->httpStatus >= 500);
    }
}
