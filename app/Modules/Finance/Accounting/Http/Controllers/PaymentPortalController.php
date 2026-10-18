<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinCollectionActivity;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Public "pay now" page reached from a dunning reminder's link. The pay_token is an unguessable
 * per-invoice credential, so no authentication is required (same pattern as fleet approval / public
 * candidate apply). Shows the balance and how to pay (EFT + SnapScan), and lets the customer tell us
 * they have paid. RLS is permissive when no tenant is set, so the token lookup works; the write is
 * wrapped in the invoice's company context so the activity is tagged to the right tenant.
 */
class PaymentPortalController extends Controller
{
    public function show(string $token): View
    {
        $invoice = FinInvoice::with('party')->where('pay_token', $token)->first();
        abort_unless($invoice && $invoice->direction === 'receivable', 404);

        $settings = FinSetting::current($invoice->company_id);

        return view('finance.pay', [
            'invoice'     => $invoice,
            'settings'    => $settings,
            'outstanding' => $invoice->outstanding(),
            'paid'        => $invoice->outstanding() <= 0.01,
            'token'       => $token,
        ]);
    }

    public function notifyPaid(string $token): RedirectResponse
    {
        $invoice = FinInvoice::where('pay_token', $token)->first();
        abort_unless($invoice && $invoice->direction === 'receivable', 404);

        app(TenantContext::class)->run($invoice->company_id, function () use ($invoice) {
            FinCollectionActivity::create([
                'invoice_id' => $invoice->id, 'party_id' => $invoice->party_id, 'type' => 'note',
                'channel' => 'portal', 'body' => 'Customer indicated they have paid (via the payment portal).',
                'meta' => ['source' => 'payment_portal'],
            ]);
        });

        return back()->with('paid_notified', true);
    }
}
