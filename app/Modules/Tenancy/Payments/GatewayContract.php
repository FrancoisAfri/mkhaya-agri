<?php

namespace App\Modules\Tenancy\Payments;

use App\Modules\Tenancy\Models\Payment;
use App\Modules\Tenancy\Models\PaymentGateway;
use Illuminate\Http\Request;

/**
 * A payment gateway adapter. redirect() returns the auto-submit form data that
 * sends the payer to the gateway; verifyNotify() validates the gateway's
 * server-to-server callback and returns our payment reference when the payment is
 * confirmed paid (else null).
 */
interface GatewayContract
{
    /** @return array{url:string, method:string, fields:array<string,string>} */
    public function redirect(Payment $payment, string $returnUrl, string $cancelUrl, string $notifyUrl): array;

    /** Returns the our_ref when the callback confirms a completed payment, else null. */
    public function verifyNotify(Request $request, PaymentGateway $gateway): ?string;

    /**
     * What the gateway says was actually paid, in rand, or null when it did not say.
     *
     * A verified "paid" used to apply the whole effect — a subscription activated, a storage
     * top-up granted — without anybody comparing the sum to what was owed. Each gateway reports
     * the figure in its own field and its own units (PayGate in cents), so the reading of it
     * belongs with the adapter that knows the format.
     */
    public function paidAmount(Request $request): ?float;
}
