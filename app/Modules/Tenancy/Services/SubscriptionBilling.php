<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\SubscriptionInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform-side subscription billing (Phase E): raise a monthly invoice for each
 * billable company at its plan price, mark them paid (reactivating suspended
 * companies), and auto-suspend companies whose invoices are overdue beyond grace.
 */
class SubscriptionBilling
{
    public int $suspendAfterDays = 7;

    public function __construct(
        private CompanyEntitlements $entitlements,
        private ResellerCommissionService $resellerCommissions,
    ) {}

    /** Generate this month's invoice for every active/trial company. Returns the count raised. */
    public function generateMonthly(?Carbon $month = null): int
    {
        $month = ($month ?? Carbon::now())->copy();
        $period = $month->format('Y-m');
        $raised = 0;

        Company::whereIn('status', ['trial', 'active'])->orderBy('id')->get()->each(function (Company $c) use ($period, $month, &$raised) {
            if (SubscriptionInvoice::where('company_id', $c->id)->where('period', $period)->exists()) {
                return; // already billed this month
            }
            $amount = $this->entitlements->monthlyPrice($c);
            if ($amount <= 0) {
                return; // free / unpriced plan
            }
            SubscriptionInvoice::create([
                'company_id' => $c->id, 'period' => $period, 'amount' => $amount,
                'status' => 'open', 'due_date' => $month->copy()->endOfMonth()->addDays(7)->toDateString(),
            ]);
            $raised++;
        });

        // Accrue reseller commissions off this period's invoices (idempotent).
        $this->resellerCommissions->accrueForPeriod($period);

        return $raised;
    }

    public function markPaid(SubscriptionInvoice $invoice, ?string $reference = null, string $gateway = 'manual'): void
    {
        $invoice->update(['status' => 'paid', 'paid_at' => now(), 'reference' => $reference, 'gateway' => $gateway]);

        $company = $invoice->company;
        // An archived company (converted to a reseller) is never brought back by an old invoice being paid.
        if ($company && $company->isSuspended() && ! $company->isArchived()) {
            $company->update(['status' => 'active', 'suspended_at' => null, 'suspended_reason' => null]);
            DB::table('subscriptions')->where('company_id', $company->id)->update(['status' => 'active']);
        }
    }

    /** Flag overdue invoices and auto-suspend companies past the grace period. */
    public function processOverdue(): array
    {
        $overdue = SubscriptionInvoice::where('status', 'open')->whereDate('due_date', '<', now())->get();
        foreach ($overdue as $inv) {
            $inv->update(['status' => 'overdue']);
        }

        $suspended = 0;
        $cutoff = now()->subDays($this->suspendAfterDays);
        SubscriptionInvoice::where('status', 'overdue')->whereDate('due_date', '<', $cutoff)->get()
            ->groupBy('company_id')->each(function ($invoices, $companyId) use (&$suspended) {
                $company = Company::find($companyId);
                if ($company && $company->isActive()) {
                    $company->update(['status' => 'suspended', 'suspended_at' => now(), 'suspended_reason' => 'Overdue subscription invoice']);
                    DB::table('subscriptions')->where('company_id', $companyId)->update(['status' => 'suspended']);
                    $suspended++;
                }
            });

        return ['overdue' => $overdue->count(), 'suspended' => $suspended];
    }
}
