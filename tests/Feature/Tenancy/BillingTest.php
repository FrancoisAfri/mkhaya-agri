<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\SubscriptionInvoice;
use App\Modules\Tenancy\Services\SubscriptionBilling;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_monthly_billing_raises_invoices_and_overdue_suspends(): void
    {
        $co = Company::create(['name' => 'Billed Co', 'plan' => 'standard', 'status' => 'active']);
        $billing = app(SubscriptionBilling::class);

        $raised = $billing->generateMonthly();
        $inv = SubscriptionInvoice::where('company_id', $co->id)->first();
        $this->assertNotNull($inv);
        $this->assertEquals(1499.0, (float) $inv->amount);

        // Overdue past grace → company suspended.
        $inv->update(['status' => 'overdue', 'due_date' => Carbon::now()->subDays(30)]);
        $result = $billing->processOverdue();
        $this->assertEquals('suspended', $co->fresh()->status);

        // Paying reactivates.
        $billing->markPaid($inv->fresh(), 'PAY-1');
        $this->assertEquals('paid', $inv->fresh()->status);
        $this->assertEquals('active', $co->fresh()->status);
    }
}
