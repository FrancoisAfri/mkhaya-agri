<?php

namespace App\Modules\HR\Payroll\Services\Bureau\Payouts;

use RuntimeException;

/** Resolves a payout provider adapter by name. */
class PayoutProviderManager
{
    public function adapter(string $provider): DisbursementProvider
    {
        return match ($provider) {
            'stitch' => new StitchProvider(),
            'ozow'   => new OzowProvider(),
            'peach'  => new PeachProvider(),
            default  => throw new RuntimeException("Unknown payout provider [{$provider}]."),
        };
    }
}
