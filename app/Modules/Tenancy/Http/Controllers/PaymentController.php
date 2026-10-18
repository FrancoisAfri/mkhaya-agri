<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMonthlyCredit;
use App\Modules\Tenancy\Models\CompanyStorageCredit;
use App\Modules\Tenancy\Models\Payment;
use App\Modules\Tenancy\Models\PaymentGateway;
use App\Modules\Tenancy\Payments\GatewayContract;
use App\Modules\Tenancy\Payments\GatewayFactory;
use App\Modules\Tenancy\Services\CompanySubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Company-side payments: pay the monthly subscription and buy AI/SMS top-ups
 * through the platform's active gateway (PayFast / PayGate / Ozow). Flow:
 * checkout() creates a pending Payment and redirects to the gateway; the gateway
 * calls notify() server-to-server; a verified "paid" applies the effect
 * (activate the company / grant credits) and records paid_at.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly CompanySubscription $subs) {}

    /** Payment landing page: monthly total, active gateway, recent payments. */
    public function pay()
    {
        $company = $this->company();
        abort_unless($company, 404);
        $gw = PaymentGateway::active();

        return Inertia::render('Tenancy/Pay', [
            'company' => ['id' => $company->id, 'name' => $company->name, 'status' => $company->status],
            'total' => (float) ($this->subs->snapshot($company)['total']),
            'gateway' => $gw ? ['code' => $gw->code, 'label' => $gw->label, 'mode' => $gw->mode] : null,
            'payments' => Payment::where('company_id', $company->id)->latest('id')->limit(20)->get()
                ->map(fn (Payment $p) => [
                    'ref' => $p->our_ref, 'purpose' => $p->purpose, 'amount' => (float) $p->amount,
                    'status' => $p->status, 'gateway' => $p->gateway,
                    'created_at' => $p->created_at?->format('Y-m-d H:i'), 'paid_at' => $p->paid_at?->format('Y-m-d H:i'),
                ]),
        ]);
    }

    /** Create a pending payment and hand off to the gateway. */
    public function checkout(Request $request)
    {
        $company = $this->company();
        abort_unless($company, 404);

        $data = $request->validate([
            'purpose' => ['required', 'in:subscription,topup_ai,topup_sms,topup_storage'],
            'amount' => ['required', 'numeric', 'min:1'],
            'meta' => ['nullable', 'array'],
        ]);

        $gw = PaymentGateway::active();
        if (! $gw) {
            return back()->with('error', 'No payment gateway is configured yet. Please contact Mkhaya.');
        }

        $payment = Payment::create([
            'company_id' => $company->id,
            'gateway' => $gw->code,
            'purpose' => $data['purpose'],
            'amount' => $data['amount'],
            'status' => 'pending',
            'meta' => $data['meta'] ?? null,
        ]);

        $redirect = GatewayFactory::make($gw)->redirect(
            $payment->load('company'),
            route('tenancy.pay.return', $payment->uuid),
            route('tenancy.pay.cancel', $payment->uuid),
            route('tenancy.pay.notify', $gw->code),
        );

        // Render an auto-submitting form that posts the payer to the gateway.
        return response()->view('payments.redirect', ['redirect' => $redirect]);
    }

    /** Gateway server-to-server callback (no auth / no CSRF). */
    public function notify(Request $request, string $code)
    {
        $gw = PaymentGateway::where('code', $code)->first();
        if (! $gw) {
            return response('unknown gateway', 400);
        }

        try {
            $gateway = GatewayFactory::make($gw);
            $ref = $gateway->verifyNotify($request, $gw);
            if ($ref) {
                $payment = Payment::where('our_ref', $ref)->first();
                if ($payment && $payment->status !== 'paid' && $this->amountMatches($gateway, $request, $payment)) {
                    $this->markPaidAndApply($payment, (string) ($request->input('pf_payment_id') ?? $request->input('TransactionId') ?? $request->input('PAY_REQUEST_ID') ?? ''));
                }
            }
        } catch (\Throwable $e) {
            Log::error('Payment notify failed', ['gateway' => $code, 'error' => $e->getMessage()]);
        }

        return response('OK', 200); // gateways expect a 200 ack
    }

    /**
     * Did they pay what was asked?
     *
     * A verified callback used to apply the whole effect — a suspended company reactivated, a
     * storage top-up granted, AI tokens credited — on the strength of the word "paid" alone.
     * Nothing compared the sum. A short payment bought the full entitlement.
     *
     * A gateway that reports no figure at all is accepted, because refusing there would break a
     * genuine payment over a missing field; the reconciliation below is what catches it. A gateway
     * that reports a figure must report the right one.
     */
    private function amountMatches(GatewayContract $gateway, Request $request, Payment $payment): bool
    {
        $paid = $gateway->paidAmount($request);

        if ($paid === null) {
            Log::warning('Payment notify carried no amount; accepted on the gateway\'s word.', [
                'our_ref' => $payment->our_ref, 'gateway' => $payment->gateway,
            ]);

            return true;
        }

        /*
         * A cent of rounding between a gateway's arithmetic and ours is not a short payment.
         *
         * Rounded before comparing, because binary floats do not hold rand and cents exactly:
         * 10000 - 9999.99 comes out as 0.010000000000218, which a bare `<= 0.01` reads as a
         * shortfall and refuses a perfectly good payment.
         */
        if (round(abs($paid - (float) $payment->amount), 2) <= 0.01) {
            return true;
        }

        /*
         * Not marked paid, and not silently dropped either: the money may well have moved, and
         * somebody has to decide whether to refund it or accept it. Left pending, it shows up in
         * the payments list as exactly what it is — unresolved.
         */
        Log::error('Payment notify rejected: the amount paid does not match the amount owed.', [
            'our_ref' => $payment->our_ref,
            'gateway' => $payment->gateway,
            'expected' => (float) $payment->amount,
            'paid' => $paid,
        ]);

        return false;
    }

    public function return(string $uuid)
    {
        $payment = Payment::where('uuid', $uuid)->first();

        return redirect()->route('tenancy.subscription.index')
            ->with($payment && $payment->status === 'paid' ? 'success' : 'info',
                $payment && $payment->status === 'paid'
                    ? 'Payment received — thank you!'
                    : 'Thanks. If your payment succeeded it will reflect shortly.');
    }

    public function cancel(string $uuid)
    {
        $payment = Payment::where('uuid', $uuid)->first();
        if ($payment && $payment->status === 'pending') {
            $payment->update(['status' => 'cancelled']);
        }

        return redirect()->route('tenancy.subscription.index')->with('warning', 'Payment cancelled.');
    }

    /** Mark a payment paid and apply its effect. */
    private function markPaidAndApply(Payment $payment, string $gatewayRef): void
    {
        $payment->update(['status' => 'paid', 'gateway_ref' => $gatewayRef, 'paid_at' => now()]);
        $company = Company::find($payment->company_id);
        if (! $company) {
            return;
        }

        if ($payment->purpose === 'subscription') {
            // A successful subscription payment (re)activates the company, unless it was converted to a
            // reseller: paying an old invoice settles the debt but does not reopen the tenant.
            if ($company->isArchived()) {
                return;
            }
            $company->update(['status' => 'active', 'suspended_at' => null, 'suspended_reason' => null]);
        } elseif ($payment->purpose === 'topup_storage') {
            // Storage top-up: amount is in GB, granted as a permanent credit.
            $gb = (int) ($payment->meta['amount'] ?? 0);
            if ($gb > 0) {
                CompanyStorageCredit::grantGb($company->id, $gb, 'purchase', "Paid top-up ({$payment->our_ref})");
            }
        } elseif (in_array($payment->purpose, ['topup_ai', 'topup_sms'], true)) {
            $type = $payment->purpose === 'topup_ai' ? 'ai' : 'sms';
            $amount = (int) ($payment->meta['amount'] ?? 0);
            if ($amount > 0) {
                CompanyMonthlyCredit::grant($company->id, $type, $amount, 'purchase', null, "Paid top-up ({$payment->our_ref})");
            }
        }
    }

    private function company(): ?Company
    {
        $id = Auth::user()->company_id ?? null;

        return $id ? Company::find($id) : null;
    }
}
