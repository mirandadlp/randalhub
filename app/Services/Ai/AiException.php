<?php

namespace App\Services\Ai;

class AiException extends \RuntimeException
{
    /** @param string $kind not_configured | budget | refusal | api | parse | disabled */
    public function __construct(string $message, public string $kind = 'api', public bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
