<?php

namespace App\Modules\Tenancy\Payments;

use App\Modules\Tenancy\Models\PaymentGateway;
use InvalidArgumentException;

class GatewayFactory
{
    public static function make(PaymentGateway $gw): GatewayContract
    {
        return match ($gw->code) {
            'payfast' => new PayFastGateway($gw),
            'paygate' => new PayGateGateway($gw),
            'ozow' => new OzowGateway($gw),
            default => throw new InvalidArgumentException("Unknown gateway: {$gw->code}"),
        };
    }
}
