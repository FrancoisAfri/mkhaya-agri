<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

/** Where approved project time goes. Local: stays in PM. Integrated: Payroll/Attendance. */
interface TimeSink
{
    /**
     * @param  array<string,mixed>  $entry
     * @return string  the receiving system's reference
     */
    public function post(array $entry): string;

    public function feedsPayroll(): bool;
}
