<?php

namespace App\Modules\Tenancy\Payments;

use App\Modules\Tenancy\Models\Payment;
use App\Modules\Tenancy\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayGate PayWeb3 (South Africa) — initiate returns a PAY_REQUEST_ID + CHECKSUM
 * that we redirect the payer with; the notify callback posts TRANSACTION_STATUS
 * (1 = approved) with a CHECKSUM (md5 of the concatenated values + encryption key).
 */
class PayGateGateway implements GatewayContract
{
    public function __construct(private readonly PaymentGateway $gw) {}

    public function redirect(Payment $payment, string $returnUrl, string $cancelUrl, string $notifyUrl): array
    {
        $id = (string) $this->gw->cred('paygate_id');
        $key = (string) $this->gw->cred('encryption_key');

        $fields = [
            'PAYGATE_ID' => $id,
            'REFERENCE' => $payment->our_ref,
            'AMOUNT' => (string) (int) round(((float) $payment->amount) * 100), // cents
            'CURRENCY' => 'ZAR',
            'RETURN_URL' => $returnUrl,
            'TRANSACTION_DATE' => now()->format('Y-m-d H:i:s'),
            'LOCALE' => 'en-za',
            'COUNTRY' => 'ZAF',
            'EMAIL' => (string) ($payment->company?->contact_email ?? 'billing@mkhaya.co.za'),
            'NOTIFY_URL' => $notifyUrl,
        ];
        $fields['CHECKSUM'] = md5(implode('', $fields).$key);

        // Initiate — PayWeb3 returns PAY_REQUEST_ID + CHECKSUM used on the redirect.
        $resp = Http::asForm()->post('https://secure.paygate.co.za/payweb3/initiate.trans', $fields);
        parse_str($resp->body(), $out);

        if (empty($out['PAY_REQUEST_ID'])) {
            return ['url' => $cancelUrl, 'method' => 'GET', 'fields' => []];
        }

        return [
            'url' => 'https://secure.paygate.co.za/payweb3/process.trans',
            'method' => 'POST',
            'fields' => [
                'PAY_REQUEST_ID' => $out['PAY_REQUEST_ID'],
                'CHECKSUM' => $out['CHECKSUM'] ?? '',
            ],
        ];
    }

    public function paidAmount(Request $request): ?float
    {
        // PayGate speaks in cents, both out and back.
        $amount = $request->input('AMOUNT');

        return $amount === null ? null : ((float) $amount) / 100;
    }

    public function verifyNotify(Request $request, PaymentGateway $gateway): ?string
    {
        $data = $request->all();
        $ref = $data['REFERENCE'] ?? null;
        $status = $data['TRANSACTION_STATUS'] ?? null;
        $checksum = $data['CHECKSUM'] ?? '';
        if (! $ref) {
            return null;
        }

        $verify = $data;
        unset($verify['CHECKSUM']);
        $expected = md5(implode('', $verify).(string) $gateway->cred('encryption_key'));

        if (! hash_equals($expected, (string) $checksum)) {
            return null;
        }

        return (string) $status === '1' ? $ref : null; // 1 = Approved
    }
}
