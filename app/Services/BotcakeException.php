<?php

namespace App\Services;

/** Error mula sa Botcake API. $auth = tinanggihan ang token (401/403 o success:false). Walang token sa mensahe. */
class BotcakeException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $auth = false)
    {
        parent::__construct($message);
    }
}
