<?php

namespace App\Services;

/**
 * Error mula sa Botcake API. $auth = tinanggihan ang token (401/403 o success:false); $reason = token_invalid,
 * rate_limited (429 pagkatapos ng retry) o error. Walang token sa mensahe.
 */
class BotcakeException extends \RuntimeException
{
    public readonly string $reason;

    public function __construct(string $message, public readonly bool $auth = false, ?string $reason = null)
    {
        parent::__construct($message);
        $this->reason = $auth ? 'token_invalid' : ($reason ?? 'error');
    }
}
