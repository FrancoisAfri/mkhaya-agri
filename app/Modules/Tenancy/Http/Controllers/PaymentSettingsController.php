<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\PaymentGateway;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Platform-admin payment settings: configure PayFast / PayGate / Ozow credentials,
 * enable them, and choose which one companies check out through. Secrets are
 * stored encrypted and never sent back to the client in full.
 */
class PaymentSettingsController extends Controller
{
    /** Credential fields per gateway. */
    private const FIELDS = [
        'payfast' => ['merchant_id', 'merchant_key', 'passphrase'],
        'paygate' => ['paygate_id', 'encryption_key'],
        'ozow' => ['site_code', 'private_key', 'api_key'],
    ];

    /**
     * The credentials without which a gateway cannot be trusted, as opposed to merely not work.
     *
     * PayFast's passphrase is the whole of its security. Its notification signature is an md5 over
     * the posted fields with the passphrase appended; with no passphrase the signature is computed
     * from public data alone, so anyone can compute a valid one and post a COMPLETE notification
     * for any payment reference. It was optional here, which meant a gateway could be enabled and
     * switched live with a lock that opens to anybody.
     *
     * Ozow and PayGate mix a secret into their hashes unconditionally, so their notifications
     * cannot be forged the same way — but a gateway missing its key cannot verify anything either,
     * and enabling one in that state only produces payments that never confirm.
     */
    private const REQUIRED_TO_ENABLE = [
        'payfast' => ['merchant_id', 'merchant_key', 'passphrase'],
        'paygate' => ['paygate_id', 'encryption_key'],
        'ozow' => ['site_code', 'private_key'],
    ];

    public function index()
    {
        return Inertia::render('Tenancy/PaymentSettings', [
            'gateways' => PaymentGateway::orderBy('id')->get()->map(fn (PaymentGateway $g) => [
                'id' => $g->id, 'code' => $g->code, 'label' => $g->label,
                'is_enabled' => $g->is_enabled, 'is_active' => $g->is_active, 'mode' => $g->mode,
                'fields' => self::FIELDS[$g->code] ?? [],
                // Only report which credential fields are set — never the values.
                'configured' => collect(self::FIELDS[$g->code] ?? [])->mapWithKeys(fn ($f) => [$f => ! empty($g->cred($f))]),
            ]),
        ]);
    }

    public function update(Request $request, PaymentGateway $gateway)
    {
        $fields = self::FIELDS[$gateway->code] ?? [];
        $data = $request->validate([
            'mode' => ['required', 'in:test,live'],
            'is_enabled' => ['required', 'boolean'],
            'credentials' => ['array'],
        ]);

        // Merge only provided (non-blank) credential fields, keep existing others.
        $creds = $gateway->credentials ?? [];
        foreach ($fields as $f) {
            $val = $request->input("credentials.$f");
            if ($val !== null && $val !== '') {
                $creds[$f] = $val;
            }
        }

        /*
         * Checked against the merged credentials, not the posted ones: a field already stored and
         * left blank in the form is still configured, and re-typing a passphrase to change the
         * mode would be a strange thing to ask for.
         */
        if ($data['is_enabled']) {
            $missing = array_values(array_filter(
                self::REQUIRED_TO_ENABLE[$gateway->code] ?? [],
                fn ($f) => empty($creds[$f]),
            ));

            if ($missing !== []) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'credentials' => __(
                        ':gateway cannot be enabled without :fields. '
                        . 'Without it, payment notifications cannot be told apart from forgeries.',
                        [
                            'gateway' => $gateway->label,
                            'fields' => implode(', ', array_map(
                                fn ($f) => str_replace('_', ' ', $f), $missing,
                            )),
                        ],
                    ),
                ]);
            }
        }

        $gateway->update([
            'mode' => $data['mode'],
            'is_enabled' => $data['is_enabled'],
            'credentials' => $creds,
        ]);

        return back()->with('success', "{$gateway->label} settings saved.");
    }

    public function activate(PaymentGateway $gateway)
    {
        abort_unless($gateway->is_enabled, 422, 'Enable and configure the gateway first.');
        PaymentGateway::query()->update(['is_active' => false]);
        $gateway->update(['is_active' => true]);

        return back()->with('success', "{$gateway->label} is now the active gateway.");
    }
}
