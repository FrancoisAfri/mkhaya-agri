<?php

namespace App\Modules\Disciplinary\Events;

use App\Modules\Disciplinary\Models\DisciplinarySuspension;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an employee suspension is issued.
 * Payroll module should listen to this event to apply salary deductions for unpaid suspensions.
 */
class EmployeeSuspended
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DisciplinarySuspension $suspension,
    ) {}

    public function isUnpaid(): bool
    {
        return $this->suspension->suspension_type === 'unpaid';
    }

    public function isPrecautionary(): bool
    {
        return $this->suspension->suspension_type === 'precautionary';
    }
}
