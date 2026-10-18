<?php

namespace App\Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a company has used its entire monthly AI-token allowance and the
 * platform enforcement mode blocks further AI usage until it tops up.
 */
class AiQuotaExceededException extends RuntimeException
{
    public function __construct(string $message = 'Your monthly AI token allowance is exhausted. Request or buy more to continue.')
    {
        parent::__construct($message);
    }
}
