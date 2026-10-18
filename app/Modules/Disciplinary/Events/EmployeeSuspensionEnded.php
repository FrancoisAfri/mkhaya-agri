<?php

namespace App\Modules\Disciplinary\Events;

use App\Modules\Disciplinary\Models\DisciplinarySuspension;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an employee suspension ends.
 * Payroll module should listen to this event to reverse any salary deductions.
 */
class EmployeeSuspensionEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DisciplinarySuspension $suspension,
        public readonly string $actualReturnDate,
    ) {}
}
