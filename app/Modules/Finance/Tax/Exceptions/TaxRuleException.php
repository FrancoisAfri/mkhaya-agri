<?php

namespace App\Modules\Finance\Tax\Exceptions;

/** A tax rule operation that the controls refuse; the message is safe to show the user. */
class TaxRuleException extends \RuntimeException
{
}
