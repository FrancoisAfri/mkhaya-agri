<?php

namespace App\Modules\Budget\Exceptions;

use App\Modules\Budget\Models\Budget;
use RuntimeException;

/**
 * A budget refused an encumbrance.
 *
 * Thrown rather than returned so it cannot be ignored by a caller who only checked the happy path.
 * Procurement reserves funds from several places, and a boolean somebody forgot to test is how
 * money gets committed against a budget that had already been stopped.
 */
class BudgetBlockedException extends RuntimeException
{
    public function __construct(
        public readonly Budget $budget,
        public readonly string $reason,
        public readonly ?string $severity = 'block',
    ) {
        parent::__construct($reason);
    }
}
