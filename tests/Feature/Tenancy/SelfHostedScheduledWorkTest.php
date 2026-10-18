<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Scheduled work on a self-hosted installation.
 *
 * Every per-tenant command sweeps `activeCompanyIds()`, which selects companies whose status is
 * `active` or `trial`. That is a SaaS billing question. A self-hosted installation has exactly one
 * company and no subscription to be active *of*, so its status is whatever the install happened to
 * leave — and if that is not one of those two words, the sweep selects nobody.
 *
 * What follows is worse than an empty sweep. `eachCompany()` treats "no active companies" as a
 * fresh install and runs the callback once untenanted, but `RunsPerTenant` refuses a null company
 * — correctly, since doing tenant work untenanted would write rows belonging to nobody. So the
 * command runs, does nothing at all, reports success, and stamps its heartbeat. Content plans,
 * publishing, supplier sweeps, budget alerts, leave accrual: all of them, silently.
 */
class SelfHostedScheduledWorkTest extends TestCase
{
    use DatabaseTransactions;

    /** A throwaway command that records which tenants it was actually run for. */
    private function sweep(): array
    {
        $seen = [];

        $command = new class extends Command
        {
            use RunsPerTenant;

            public array $seen = [];

            public function run(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
            {
                return 0;
            }

            public function go(TenantContext $tenants): void
            {
                $this->eachTenant($tenants, function () {
                    $this->seen[] = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();
                });
            }
        };

        $command->setLaravel(app());
        $command->go(app(TenantContext::class));

        return $command->seen;
    }

    public function test_a_self_hosted_company_is_swept_whatever_its_billing_status_says(): void
    {
        config(['edition.edition' => 'self_hosted']);

        // One company, the shape of a self-hosted install — and a status that is not a SaaS
        // subscription word, because on this edition there is no subscription.
        Company::query()->update(['status' => 'suspended']);
        $sole = Company::query()->orderBy('id')->first();

        $seen = $this->sweep();

        $this->assertContains(
            (int) $sole->id,
            array_map('intval', array_filter($seen, fn ($id) => $id !== null)),
            'On a self-hosted installation the one company must be swept, or every scheduled '
            .'command silently does nothing while reporting success.',
        );
    }

    public function test_saas_still_skips_a_company_that_is_not_active(): void
    {
        // The self-hosted allowance must not become a way for a suspended SaaS tenant to keep
        // receiving scheduled work it is no longer paying for.
        config(['edition.edition' => 'saas']);

        Company::query()->update(['status' => 'suspended']);

        $this->assertSame(
            [],
            array_filter($this->sweep(), fn ($id) => $id !== null),
            'A suspended SaaS tenant must stay out of the sweep.',
        );
    }

    public function test_an_active_saas_tenant_is_still_swept(): void
    {
        config(['edition.edition' => 'saas']);

        Company::query()->update(['status' => 'active']);

        $this->assertNotEmpty(
            array_filter($this->sweep(), fn ($id) => $id !== null),
            'Active tenants must keep being swept.',
        );
    }
}
