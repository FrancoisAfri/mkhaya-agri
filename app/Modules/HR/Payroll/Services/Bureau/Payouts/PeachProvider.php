<?php

namespace App\Modules\HR\Payroll\Services\Bureau\Payouts;

/** Peach Payments disbursements adapter (align paths/shape with Peach's API spec). */
class PeachProvider extends HttpPayoutProvider
{
    public function provider(): string { return 'peach'; }
    protected function healthPath(): string { return '/v1/health'; }
    protected function batchPath(): string { return '/v1/payouts/batch'; }
}
