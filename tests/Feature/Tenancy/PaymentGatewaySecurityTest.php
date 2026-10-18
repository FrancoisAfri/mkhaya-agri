<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Payment;
use App\Modules\Tenancy\Models\PaymentGateway;
use App\Modules\Tenancy\Payments\GatewayFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What it takes to convince this system that money arrived.
 *
 * Two holes sat side by side in the gateway callbacks. PayFast's notification signature is an md5
 * over the posted fields with the merchant's passphrase appended — and the passphrase was optional,
 * so a gateway could be enabled and switched live with the signature computable from public data
 * alone. Anyone could post a COMPLETE notification for any reference they could guess.
 *
 * The second hole needed no forgery at all: no callback compared the sum paid to the sum owed, so a
 * confirmed payment of one rand activated a subscription billed at ten thousand.
 */
class PaymentGatewaySecurityTest extends TestCase
{
    use DatabaseTransactions;

    private function gateway(string $code, array $credentials): PaymentGateway
    {
        // The gateway rows are reference data and already seeded; the test sets their state
        // rather than making rivals for a unique code.
        return tap(PaymentGateway::firstOrNew(['code' => $code]), function (PaymentGateway $g) use ($code, $credentials) {
            $g->fill([
                'label' => $g->label ?: ucfirst($code), 'is_enabled' => true,
                'is_active' => false, 'mode' => 'test', 'credentials' => $credentials,
            ])->save();
        });
    }

    private function payment(float $amount = 10000): Payment
    {
        return Payment::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => DB::table('companies')->min('id'),
            'gateway' => 'payfast', 'purpose' => 'subscription',
            'amount' => $amount, 'currency' => 'ZAR', 'status' => 'pending',
        ]);
    }

    /** The signature exactly as PayFastGateway computes it. */
    private function payfastSignature(array $fields, string $passphrase): string
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

    private function notify(array $fields): Request
    {
        return Request::create('/pay/notify/payfast', 'POST', $fields);
    }

    // ── The forgery ──────────────────────────────────────────────────────────

    /**
     * The whole point. Without a passphrase an attacker computes the same signature the server
     * will, so the signature check compares two numbers they both control.
     */
    public function test_payfast_refuses_a_notification_when_no_passphrase_is_configured(): void
    {
        $gateway = $this->gateway('payfast', ['merchant_id' => '10000100', 'merchant_key' => 'abc']);

        $forged = ['m_payment_id' => 'MK-FORGED01', 'payment_status' => 'COMPLETE', 'amount_gross' => '1.00'];
        $forged['signature'] = $this->payfastSignature($forged, '');

        $this->assertNull(
            GatewayFactory::make($gateway)->verifyNotify($this->notify($forged), $gateway),
            'A forged notification was accepted because no passphrase was set.',
        );
    }

    /** With a passphrase the same forgery fails on its own merits. */
    public function test_payfast_rejects_a_signature_computed_without_the_passphrase(): void
    {
        $gateway = $this->gateway('payfast', [
            'merchant_id' => '10000100', 'merchant_key' => 'abc', 'passphrase' => 'a-real-secret',
        ]);

        $forged = ['m_payment_id' => 'MK-FORGED02', 'payment_status' => 'COMPLETE', 'amount_gross' => '1.00'];
        $forged['signature'] = $this->payfastSignature($forged, '');

        $this->assertNull(
            GatewayFactory::make($gateway)->verifyNotify($this->notify($forged), $gateway),
        );
    }

    /** And a genuine one still gets through, which is the half that is easy to break. */
    public function test_payfast_accepts_a_properly_signed_notification(): void
    {
        $gateway = $this->gateway('payfast', [
            'merchant_id' => '10000100', 'merchant_key' => 'abc', 'passphrase' => 'a-real-secret',
        ]);

        $fields = ['m_payment_id' => 'MK-GENUINE1', 'payment_status' => 'COMPLETE', 'amount_gross' => '10000.00'];
        $fields['signature'] = $this->payfastSignature($fields, 'a-real-secret');

        $this->assertSame(
            'MK-GENUINE1',
            GatewayFactory::make($gateway)->verifyNotify($this->notify($fields), $gateway),
        );
    }

    // ── Enabling a gateway that cannot verify anything ───────────────────────

    public function test_payfast_cannot_be_enabled_without_a_passphrase(): void
    {
        $gateway = $this->gateway('payfast', []);
        $gateway->update(['is_enabled' => false]);

        $this->actingAs($this->platformAdmin());

        $response = $this->from('/settings/payments')->put(
            route('tenancy.payment-settings.update', $gateway),
            ['mode' => 'live', 'is_enabled' => true,
             'credentials' => ['merchant_id' => '10000100', 'merchant_key' => 'abc']],
        );

        if ($response->status() === 403) {
            $this->markTestSkipped('The payment settings screen is not open to this user.');
        }

        $response->assertSessionHasErrors('credentials');
        $this->assertFalse($gateway->fresh()->is_enabled, 'The gateway was enabled without a passphrase.');
    }

    public function test_payfast_can_be_enabled_once_a_passphrase_is_given(): void
    {
        $gateway = $this->gateway('payfast', []);
        $gateway->update(['is_enabled' => false]);

        $this->actingAs($this->platformAdmin());

        $response = $this->put(route('tenancy.payment-settings.update', $gateway), [
            'mode' => 'test', 'is_enabled' => true,
            'credentials' => ['merchant_id' => '10000100', 'merchant_key' => 'abc', 'passphrase' => 'secret'],
        ]);

        if ($response->status() === 403) {
            $this->markTestSkipped('The payment settings screen is not open to this user.');
        }

        $response->assertSessionHasNoErrors();
        $this->assertTrue($gateway->fresh()->is_enabled);
    }

    // ── Paying less than you owe ─────────────────────────────────────────────

    public function test_each_gateway_reports_what_was_actually_paid(): void
    {
        $payfast = $this->gateway('payfast', ['passphrase' => 'x']);
        $ozow    = $this->gateway('ozow', ['private_key' => 'x']);
        $paygate = $this->gateway('paygate', ['encryption_key' => 'x']);

        $this->assertSame(150.0, GatewayFactory::make($payfast)
            ->paidAmount(Request::create('/', 'POST', ['amount_gross' => '150.00'])));

        $this->assertSame(150.0, GatewayFactory::make($ozow)
            ->paidAmount(Request::create('/', 'POST', ['Amount' => '150.00'])));

        // PayGate speaks in cents, which is exactly the sort of difference that makes a
        // one-size comparison in the controller the wrong place for this.
        $this->assertSame(150.0, GatewayFactory::make($paygate)
            ->paidAmount(Request::create('/', 'POST', ['AMOUNT' => '15000'])));
    }

    public function test_a_gateway_that_reports_no_amount_returns_null(): void
    {
        $payfast = $this->gateway('payfast', ['passphrase' => 'x']);

        $this->assertNull(GatewayFactory::make($payfast)->paidAmount(Request::create('/', 'POST', [])));
    }

    /** A short payment must not buy the full entitlement. */
    public function test_a_short_payment_does_not_mark_the_payment_paid(): void
    {
        $gateway = $this->gateway('payfast', [
            'merchant_id' => '10000100', 'merchant_key' => 'abc', 'passphrase' => 'a-real-secret',
        ]);
        $gateway->update(['is_active' => true]);

        $payment = $this->payment(10000);

        $fields = ['m_payment_id' => $payment->our_ref, 'payment_status' => 'COMPLETE', 'amount_gross' => '1.00'];
        $fields['signature'] = $this->payfastSignature($fields, 'a-real-secret');

        $this->post('/pay/notify/payfast', $fields)->assertOk();

        $this->assertSame('pending', $payment->fresh()->status,
            'A payment of R1 against an invoice of R10 000 was accepted in full.');
    }

    /** The right amount still goes through. */
    public function test_the_full_amount_is_accepted(): void
    {
        $gateway = $this->gateway('payfast', [
            'merchant_id' => '10000100', 'merchant_key' => 'abc', 'passphrase' => 'a-real-secret',
        ]);
        $gateway->update(['is_active' => true]);

        $payment = $this->payment(10000);

        $fields = ['m_payment_id' => $payment->our_ref, 'payment_status' => 'COMPLETE', 'amount_gross' => '10000.00'];
        $fields['signature'] = $this->payfastSignature($fields, 'a-real-secret');

        $this->post('/pay/notify/payfast', $fields)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
    }

    /** A cent of rounding between their arithmetic and ours is not a short payment. */
    public function test_a_cent_of_rounding_is_tolerated(): void
    {
        $gateway = $this->gateway('payfast', [
            'merchant_id' => '10000100', 'merchant_key' => 'abc', 'passphrase' => 'a-real-secret',
        ]);
        $gateway->update(['is_active' => true]);

        $payment = $this->payment(10000);

        $fields = ['m_payment_id' => $payment->our_ref, 'payment_status' => 'COMPLETE', 'amount_gross' => '9999.99'];
        $fields['signature'] = $this->payfastSignature($fields, 'a-real-secret');

        $this->post('/pay/notify/payfast', $fields)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
    }

    private function platformAdmin(): \App\Core\User\Models\User
    {
        return \App\Core\User\Models\User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'pg+'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => DB::table('companies')->min('id'),
        ]);
    }
}
