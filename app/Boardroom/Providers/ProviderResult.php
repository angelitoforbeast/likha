<?php

namespace App\Boardroom\Providers;

/**
 * Normalized na sagot ng kahit anong provider: text, usage, finish status, at error.
 * Walang reasoning object / chain-of-thought na dinadala rito.
 */
final class ProviderResult
{
    // Normalized finish statuses
    public const COMPLETED = 'completed';
    public const TRUNCATED = 'truncated';
    public const REFUSED   = 'refused';
    public const FILTERED  = 'filtered';
    public const ERROR     = 'error';

    public bool $ok = false;
    public string $text = '';
    public string $finish = self::ERROR;
    public ?string $rawFinish = null;
    public int $tokensIn = 0;
    public int $tokensOut = 0;
    public int $tokensReasoning = 0;
    public ?string $responseId = null;
    public ?string $modelReported = null;
    public ?string $errorCode = null;
    public ?string $errorMessage = null;
    public ?int $httpStatus = null;
    public bool $retryable = false;

    /** @var array mga parameter na TOTOONG naipadala (walang secret, walang prompt text) */
    public array $sent = [];

    public static function success(string $text, string $finish, array $usage = [], array $extra = []): self
    {
        $r = new self();
        $r->ok              = true;
        $r->text            = $text;
        $r->finish          = $finish;
        $r->tokensIn        = (int) ($usage['in'] ?? 0);
        $r->tokensOut       = (int) ($usage['out'] ?? 0);
        $r->tokensReasoning = (int) ($usage['reasoning'] ?? 0);
        $r->rawFinish       = $extra['raw_finish'] ?? null;
        $r->responseId      = $extra['response_id'] ?? null;
        $r->modelReported   = $extra['model'] ?? null;
        $r->httpStatus      = $extra['http_status'] ?? 200;
        $r->sent            = $extra['sent'] ?? [];

        return $r;
    }

    public static function failure(string $code, string $message, bool $retryable, ?int $httpStatus = null, array $sent = []): self
    {
        $r = new self();
        $r->ok           = false;
        $r->finish       = self::ERROR;
        $r->errorCode    = $code;
        $r->errorMessage = $message;
        $r->retryable    = $retryable;
        $r->httpStatus   = $httpStatus;
        $r->sent         = $sent;

        return $r;
    }
}
