<?php

namespace App\Modules\Finance\Accounting\Exceptions;

use RuntimeException;

/**
 * Raised when a financial event cannot be posted (unbalanced, closed period,
 * unknown/non-postable account, etc.).
 */
class PostingException extends RuntimeException
{
}
