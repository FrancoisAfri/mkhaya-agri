<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Reseller;
use App\Modules\Tenancy\Models\ResellerCommission;
use App\Modules\Tenancy\Models\SubscriptionInvoice;

/**
 * Accrues monthly reseller commission. For each subscription invoice in a period whose
 * client was brought by an ACTIVE reseller, records one commission row (percent or fixed
 * of the invoice amount). Idempotent per (reseller, client, period) via a unique key, so
 * it is safe to run repeatedly and safe to call right after SubscriptionBilling.
 */
class ResellerCommissionService
{
    /** Accrue commissions for the given period (YYYY-MM). Returns the count of rows raised. */
    public function accrueForPeriod(string $period): int
    {
        $raised = 0;

        SubscriptionInvoice::where('period', $period)
            ->whereIn('company_id', function ($q) {
                $q->select('id')->from('companies')->whereNotNull('reseller_id');
            })
            ->with('company:id,name,reseller_id')
            ->orderBy('id')
            ->get()
            ->each(function (SubscriptionInvoice $inv) use ($period, &$raised) {
                $company = $inv->company;
                if (! $company || ! $company->reseller_id) {
                    return;
                }

                // Skip if already accrued for this reseller+client+period (idempotent).
                $exists = ResellerCommission::where('reseller_id', $company->reseller_id)
                    ->where('company_id', $company->id)
                    ->where('period', $period)
                    ->exists();
                if ($exists) {
                    return;
                }

                $reseller = Reseller::find($company->reseller_id);
                if (! $reseller || ! $reseller->isActive()) {
                    return; // suspended resellers stop accruing
                }

                $base = (float) $inv->amount;
                $commission = $reseller->commissionOn($base);
                if ($commission <= 0) {
                    return;
                }

                ResellerCommission::create([
                    'reseller_id' => $reseller->id,
                    'company_id' => $company->id,
                    'subscription_invoice_id' => $inv->id,
                    'period' => $period,
                    'base_amount' => $base,
                    'commission_type' => $reseller->commission_type,
                    'commission_value' => $reseller->commission_value,
                    'commission_amount' => $commission,
                    'status' => 'pending',
                ]);
                $raised++;
            });

        return $raised;
    }
}
