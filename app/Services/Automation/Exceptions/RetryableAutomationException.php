<?php

namespace App\Services\Automation\Exceptions;

use RuntimeException;
use Throwable;

class RetryableAutomationException extends RuntimeException
{
    public function __construct(string $message, public string $errorCode = 'retryable_failure', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
