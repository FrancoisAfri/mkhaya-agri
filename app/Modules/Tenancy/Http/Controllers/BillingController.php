<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\SubscriptionInvoice;
use App\Modules\Tenancy\Services\SubscriptionBilling;
use Inertia\Inertia;

/**
 * Platform billing — subscription revenue KPIs and every company's invoices.
 * Mirrors mkhaya-prop's BillingController, using hub's SubscriptionBilling.
 */
class BillingController extends Controller
{
    public function __construct(private readonly SubscriptionBilling $billing) {}

    public function index()
    {
        return Inertia::render('Tenancy/Billing', [
            'metrics' => $this->metrics(),
            'invoices' => SubscriptionInvoice::with('company:id,name,plan')->latest('id')->limit(300)->get()
                ->map(fn (SubscriptionInvoice $i) => [
                    'id' => $i->id,
                    'number' => $i->reference ?: ('INV-'.str_replace('-', '', $i->period).'-'.$i->id),
                    'company' => $i->company?->name,
                    'plan' => $i->company?->plan,
                    'amount' => (float) $i->amount,
                    'period' => $i->period,
                    'due_date' => $i->due_date?->format('Y-m-d'),
                    'status' => $i->status,
                    'paid_at' => $i->paid_at?->format('Y-m-d'),
                ]),
        ]);
    }

    public function generate()
    {
        $n = $this->billing->generateMonthly();

        return back()->with('success', "Generated {$n} invoice(s) for this month.");
    }

    public function processOverdue()
    {
        $r = $this->billing->processOverdue();

        return back()->with('success', "Overdue flagged: {$r['overdue']}; auto-suspended: {$r['suspended']}.");
    }

    public function markPaid(SubscriptionInvoice $invoice)
    {
        $this->billing->markPaid($invoice);

        return back()->with('success', 'Invoice marked paid; company reactivated if it was suspended.');
    }

    /** MRR/ARR, company status counts, outstanding. */
    private function metrics(): array
    {
        $prices = Plan::pluck('price_monthly', 'code')->map(fn ($p) => (float) $p)->toArray();

        $byStatus = Company::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $mrr = (float) Company::where('status', 'active')->get()->sum(fn ($c) => $prices[$c->plan] ?? 0.0);

        return [
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'active' => (int) ($byStatus['active'] ?? 0),
            'trial' => (int) ($byStatus['trial'] ?? 0),
            'suspended' => (int) (($byStatus['suspended'] ?? 0) + ($byStatus['cancelled'] ?? 0)),
            'outstanding' => (float) SubscriptionInvoice::whereIn('status', ['open', 'overdue'])->sum('amount'),
        ];
    }
}
