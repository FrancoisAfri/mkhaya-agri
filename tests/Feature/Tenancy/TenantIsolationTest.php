<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Finance\Accounting\Models\FinBudget;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_company_scope_isolates_data_and_auto_stamps(): void
    {
        $a = Company::create(['name' => 'Alpha Co']);
        $b = Company::create(['name' => 'Bravo Co']);
        $cc = app(CurrentCompany::class);

        // Auto-stamp: creating under a company writes its company_id.
        $cc->set($a->id);
        $budgetA = FinBudget::create(['name' => 'A budget', 'fiscal_year' => 2026]);
        $this->assertEquals($a->id, $budgetA->company_id);

        $cc->set($b->id);
        $budgetB = FinBudget::create(['name' => 'B budget', 'fiscal_year' => 2026]);

        // Isolation: Alpha sees only its own budget, not Bravo's.
        $cc->set($a->id);
        $this->assertEquals([$budgetA->id], FinBudget::pluck('id')->all());
        $this->assertNull(FinBudget::find($budgetB->id)); // cannot reach another tenant's row by id

        $cc->set($b->id);
        $this->assertEquals([$budgetB->id], FinBudget::pluck('id')->all());

        // No active company (console/platform admin) → sees all.
        $cc->clear();
        $this->assertEqualsCanonicalizing([$budgetA->id, $budgetB->id], FinBudget::pluck('id')->all());
    }
}
