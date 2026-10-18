<?php

namespace App\Modules\HR\Payroll\Services\Bureau\Payouts;

/** Ozow disbursements adapter (align paths/shape with Ozow's API spec). */
class OzowProvider extends HttpPayoutProvider
{
    public function provider(): string { return 'ozow'; }
    protected function healthPath(): string { return '/api/health'; }
    protected function batchPath(): string { return '/api/disbursements'; }
}
