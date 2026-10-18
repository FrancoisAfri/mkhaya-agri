<?php

namespace App\Modules\Finance\Tax\Exceptions;

/**
 * A tax control refused an action: a locked period, segregation of duties, a missing reason.
 * The message is written for the user; it never carries a stack trace or internal detail (§24).
 */
class TaxControlException extends \RuntimeException
{
}
