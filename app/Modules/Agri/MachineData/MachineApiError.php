<?php

namespace App\Modules\Agri\MachineData;

use RuntimeException;

/** A machine platform refused a call; the message is the platform's own, for the sync log. */
class MachineApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
