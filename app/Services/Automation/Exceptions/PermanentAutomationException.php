<?php

namespace App\Services\Automation\Exceptions;

use RuntimeException;

class PermanentAutomationException extends RuntimeException
{
    public function __construct(string $message, public string $errorCode = 'permanent_failure')
    {
        parent::__construct($message);
    }
}
