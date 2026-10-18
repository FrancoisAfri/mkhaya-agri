<?php

namespace App\Modules\HR\Payroll\Services\Bureau\Payouts;

/** Stitch payouts/disbursements adapter (the implemented provider). */
class StitchProvider extends HttpPayoutProvider
{
    public function provider(): string { return 'stitch'; }
    protected function healthPath(): string { return '/health'; }
    protected function batchPath(): string { return '/disbursements'; }
}
