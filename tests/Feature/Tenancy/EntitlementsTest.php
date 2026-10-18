<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_plan_gates_modules_and_user_quota(): void
    {
        $starter = Company::create(['name' => 'Starter Co', 'plan' => 'starter', 'status' => 'active']);
        $ent = app(CompanyEntitlements::class);
        $cc = app(CurrentCompany::class);
        $cc->set($starter->id);

        // Starter is the base platform only — core modules included; functional
        // modules (leave, accounting) are à la carte and NOT part of the base plan.
        $this->assertTrue($ent->currentAllowsModule('user_management'));
        $this->assertFalse($ent->currentAllowsModule('leave_management'));
        $this->assertFalse($ent->currentAllowsModule('accounting'));
        $this->assertEquals(5, $ent->maxUsers($starter));

        // Enterprise (null modules) allows everything.
        $ent2 = app(CompanyEntitlements::class);        $enterprise = Company::create(['name' => 'Ent Co', 'plan' => 'enterprise', 'status' => 'active']);
        $cc->set($enterprise->id);
        $this->assertTrue($ent->currentAllowsModule('accounting'));
        $this->assertEquals(100, $ent->maxUsers($enterprise));

        $cc->clear();
    }
}
