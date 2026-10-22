<?php

namespace App\Modules\Agri\Accounting;

use RuntimeException;

/** A provider refused a call; the message is the provider's own, for the sync log. */
class AccountingApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
