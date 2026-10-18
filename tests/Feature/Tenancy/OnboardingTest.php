<?php

namespace Tests\Feature\Tenancy;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\CompanyProvisioner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_provisioning_creates_a_trial_company_and_pinned_admin(): void
    {
        $result = app(CompanyProvisioner::class)->provision([
            'company_name' => 'Acme Pty',
            'admin_name'   => 'Ada Admin',
            'email'        => 'ada' . uniqid() . '@acme.test',
            'password'     => 'secret-password',
        ]);

        $company = $result['company'];
        $user = $result['user'];

        $this->assertEquals('trial', $company->status);
        $this->assertTrue($company->trial_ends_at->isFuture());
        $this->assertNotEmpty($company->slug);

        // The admin is pinned to the new company.
        $this->assertEquals($company->id, $user->company_id);
        $this->assertEquals($company->id, User::withoutGlobalScopes()->find($user->id)->company_id);
    }
}
