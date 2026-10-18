<?php

namespace App\Modules\Tenancy\Payments;

use App\Modules\Tenancy\Models\Payment;
use App\Modules\Tenancy\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PayFast (South Africa) — redirect checkout + ITN callback. Supports card and
 * Instant EFT (PayFast presents the payer's available methods). Sandbox for test
 * mode. Signature = md5 of the URL-encoded parameter string (+ passphrase).
 */
class PayFastGateway implements GatewayContract
{
    public function __construct(private readonly PaymentGateway $gw) {}

    public function redirect(Payment $payment, string $returnUrl, string $cancelUrl, string $notifyUrl): array
    {
        $fields = [
            'merchant_id' => (string) $this->gw->cred('merchant_id'),
            'merchant_key' => (string) $this->gw->cred('merchant_key'),
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'notify_url' => $notifyUrl,
            'm_payment_id' => $payment->our_ref,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'item_name' => 'Mkhaya Agri — '.$payment->purpose,
        ];
        $fields['signature'] = $this->signature($fields, (string) $this->gw->cred('passphrase', ''));

        $base = $this->gw->mode === 'live' ? 'https://www.payfast.co.za' : 'https://sandbox.payfast.co.za';

        return ['url' => "{$base}/eng/process", 'method' => 'POST', 'fields' => $fields];
    }

    public function paidAmount(Request $request): ?float
    {
        $gross = $request->input('amount_gross');

        return $gross === null ? null : (float) $gross;
    }

    public function verifyNotify(Request $request, PaymentGateway $gateway): ?string
    {
        $data = $request->all();
        $status = $data['payment_status'] ?? null;
        $ref = $data['m_payment_id'] ?? null;
        if (! $ref) {
            return null;
        }

        /*
         * No passphrase, no trust.
         *
         * The signature below is an md5 over the posted fields with the passphrase appended. With
         * no passphrase it is computed from public data alone, so anybody can produce a valid one
         * and post a COMPLETE notification for any reference they can guess. Accepting that is
         * worse than accepting nothing, because it looks verified.
         *
         * The settings screen refuses to enable PayFast without one; this is the second lock, for
         * a gateway row that predates that rule or was written around it.
         */
        if (trim((string) $gateway->cred('passphrase', '')) === '') {
            Log::warning('PayFast notification rejected: no passphrase is configured, so its '
                . 'signature cannot be distinguished from a forgery.', ['gateway_id' => $gateway->id]);

            return null;
        }

        // Validate the signature the same way it was generated.
        $posted = $data;
        $sig = $posted['signature'] ?? '';
        unset($posted['signature']);
        $expected = $this->signature($posted, (string) $gateway->cred('passphrase', ''));

        if (! hash_equals($expected, (string) $sig)) {
            return null;
        }

        return $status === 'COMPLETE' ? $ref : null;
    }

    /** PayFast signature: urlencoded k=v joined by &, trailing &passphrase, md5. */
    private function signature(array $fields, string $passphrase): string
    {
        $pairs = [];
        foreach ($fields as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $pairs[] = $k.'='.urlencode(trim((string) $v));
        }
        $str = implode('&', $pairs);
        if ($passphrase !== '') {
            $str .= '&passphrase='.urlencode(trim($passphrase));
        }

        return md5($str);
    }
}
