<?php

namespace App\Modules\Tenancy\Payments;

use App\Modules\Tenancy\Models\Payment;
use App\Modules\Tenancy\Models\PaymentGateway;
use Illuminate\Http\Request;

/**
 * Ozow (South Africa) — Instant EFT. Redirect checkout with a SHA512 hash of the
 * request fields (+ private key). The notify/callback posts the same fields plus a
 * Hash and Status (Complete) which we re-hash to verify.
 */
class OzowGateway implements GatewayContract
{
    public function __construct(private readonly PaymentGateway $gw) {}

    public function redirect(Payment $payment, string $returnUrl, string $cancelUrl, string $notifyUrl): array
    {
        $fields = [
            'SiteCode' => (string) $this->gw->cred('site_code'),
            'CountryCode' => 'ZA',
            'CurrencyCode' => 'ZAR',
            'Amount' => number_format((float) $payment->amount, 2, '.', ''),
            'TransactionReference' => $payment->our_ref,
            'BankReference' => 'MkhayaHub',
            'IsTest' => $this->gw->mode === 'live' ? 'false' : 'true',
            'SuccessUrl' => $returnUrl,
            'CancelUrl' => $cancelUrl,
            'ErrorUrl' => $cancelUrl,
            'NotifyUrl' => $notifyUrl,
        ];
        // Ozow hash: concatenate values in order + private key, lowercase, SHA512.
        $order = ['SiteCode', 'CountryCode', 'CurrencyCode', 'Amount', 'TransactionReference', 'BankReference', 'IsTest', 'SuccessUrl', 'CancelUrl', 'ErrorUrl', 'NotifyUrl'];
        $concat = '';
        foreach ($order as $k) {
            $concat .= $fields[$k];
        }
        $concat .= (string) $this->gw->cred('private_key');
        $fields['HashCheck'] = hash('sha512', strtolower($concat));

        $base = $this->gw->mode === 'live' ? 'https://pay.ozow.com' : 'https://stagingpay.ozow.com';

        return ['url' => $base, 'method' => 'POST', 'fields' => $fields];
    }

    public function paidAmount(Request $request): ?float
    {
        $amount = $request->input('Amount');

        return $amount === null ? null : (float) $amount;
    }

    public function verifyNotify(Request $request, PaymentGateway $gateway): ?string
    {
        $data = $request->all();
        $ref = $data['TransactionReference'] ?? null;
        $status = $data['Status'] ?? null;
        $hash = $data['Hash'] ?? '';
        if (! $ref) {
            return null;
        }

        // Re-hash the posted fields (order per Ozow's notify spec) + private key.
        $order = ['SiteCode', 'TransactionId', 'TransactionReference', 'Amount', 'Status', 'Optional1', 'Optional2', 'Optional3', 'Optional4', 'Optional5', 'CurrencyCode', 'IsTest', 'StatusMessage'];
        $concat = '';
        foreach ($order as $k) {
            $concat .= (string) ($data[$k] ?? '');
        }
        $concat .= (string) $gateway->cred('private_key');
        $expected = hash('sha512', strtolower($concat));

        if (! hash_equals($expected, (string) $hash)) {
            return null;
        }

        return $status === 'Complete' ? $ref : null;
    }
}
