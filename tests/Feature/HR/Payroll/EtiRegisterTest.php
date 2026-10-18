<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Showing the ETI ledger that has been written all along.
 *
 * Every run has recorded a row per employee per month since the ledger was built, and nothing ever
 * displayed it: summaryFor() and historyFor() existed with no caller anywhere in the codebase. So
 * the one question an employer has about the incentive — how much is left, and when does it stop —
 * could only be answered with a SQL client, and in practice was answered by the PAYE bill jumping
 * in the month somebody's twenty-four months quietly ran out.
 */
class EtiRegisterTest extends PayrollTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        // The register aggregates the whole ledger and scopes it by company, so these tests are
        // only meaningful with a real tenant in play. The shared payroll fixture does not set one.
        // Through the model, which fills uuid and slug — both NOT NULL — on create.
        $this->companyId = (int) (DB::table('companies')->min('id')
            ?? Company::create(['name' => 'ETI Test Co'])->id);

        DB::table('users')->where('id', $this->hrManager->id)->update(['company_id' => $this->companyId]);
        $this->hrManager->refresh();

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);
    }

    private function claimant(string $name, int $months, float $each = 1500.0, int $skipped = 0): User
    {
        $user = User::factory()->create(['status' => 1]);

        DB::table('hr_people')->insert([
            'user_id' => $user->id, 'first_name' => $name, 'surname' => 'Test',
            'employee_number' => 'E' . $user->id, 'monthly_package' => 4000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $period = now()->startOfMonth()->subMonths($months + $skipped);

        for ($i = 1; $i <= $months; $i++) {
            $this->claim($user->id, $period->copy()->addMonths($i - 1), true, $i, $each);
        }

        // Months the run looked at and decided against. Kept, because "why did this person get
        // nothing in June" is the question the history exists to answer.
        for ($i = 1; $i <= $skipped; $i++) {
            $this->claim($user->id, $period->copy()->addMonths($months + $i - 1), false, null, 0.0);
        }

        return $user;
    }

    private function claim(int $employeeId, \Illuminate\Support\Carbon $start, bool $eligible, ?int $month, float $amount): void
    {
        EtiClaim::create([
            'company_id'    => $this->companyId,
            'employee_type' => EtiClaim::HR,
            'employee_id'   => $employeeId,
            'period_key'    => $start->format('Y-m'),
            'period_start'  => $start->toDateString(),
            'period_end'    => $start->copy()->endOfMonth()->toDateString(),
            'tax_year'      => 2027,
            'claim_month'   => $month,
            'eligible'      => $eligible,
            'amount'        => $amount,
            'monthly_remuneration' => 4000,
            'age'           => 24,
            'reason'        => $eligible ? null : 'Remuneration above the ETI ceiling for the month.',
        ]);
    }

    // ── The register ─────────────────────────────────────────────────────────

    public function test_it_shows_how_many_of_the_twenty_four_months_are_used(): void
    {
        $person = $this->claimant('Thabo', months: 8);

        $this->actingAs($this->hrManager)
            ->get(route('payroll.eti'))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.employee_id', $person->id)
                ->where('rows.0.claimed', 8)
                ->where('rows.0.remaining', 16)
                ->where('maxMonths', 24));
    }

    public function test_somebody_three_months_from_the_end_is_flagged_before_it_happens(): void
    {
        // The whole point of the screen: the employer can budget for the PAYE going up, rather than
        // discovering it after the fact.
        $this->claimant('Almost', months: 22);

        $this->actingAs($this->hrManager)
            ->get(route('payroll.eti'))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.ending_soon', true)
                ->where('summary.ending_soon', 1));
    }

    public function test_an_exhausted_employee_is_not_in_the_claiming_list(): void
    {
        $this->claimant('Finished', months: 24);

        $this->actingAs($this->hrManager)
            ->get(route('payroll.eti'))
            ->assertInertia(fn ($page) => $page->has('rows', 0)->where('summary.exhausted', 1));
    }

    public function test_the_exhausted_filter_finds_them(): void
    {
        $person = $this->claimant('Finished', months: 24);

        $this->actingAs($this->hrManager)
            ->get(route('payroll.eti', ['filter' => 'exhausted']))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.employee_id', $person->id)
                ->where('rows.0.remaining', 0)
                ->where('rows.0.exhausted', true));
    }

    public function test_months_that_claimed_nothing_do_not_consume_one_of_the_twenty_four(): void
    {
        // The distinction the ledger exists for: months claimed, not months employed.
        $this->claimant('Patchy', months: 5, skipped: 4);

        $this->actingAs($this->hrManager)
            ->get(route('payroll.eti'))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.claimed', 5)
                ->where('rows.0.remaining', 19)
                ->where('rows.0.skipped', 4));
    }

    // ── One person's history ─────────────────────────────────────────────────

    public function test_the_history_lists_the_months_that_claimed_nothing_and_why(): void
    {
        $person = $this->claimant('Patchy', months: 2, skipped: 1);

        $this->actingAs($this->hrManager)
            ->get(route('payroll.eti.show', $person->id))
            ->assertInertia(fn ($page) => $page
                ->has('history', 3)
                ->where('summary.claimed', 2)
                ->where('summary.remaining', 22)
                ->where('history.0.eligible', false)
                ->where('history.0.reason', 'Remuneration above the ETI ceiling for the month.'));
    }

    public function test_an_employee_with_no_eti_history_is_not_a_blank_page(): void
    {
        $stranger = User::factory()->create(['status' => 1]);

        $this->actingAs($this->hrManager)
            ->get(route('payroll.eti.show', $stranger->id))
            ->assertNotFound();
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    public function test_another_tenants_claims_are_not_counted(): void
    {
        /*
         * The register aggregates across the whole ledger, which is the shape of query that leaks.
         * A platform admin with no current company would otherwise sum one employer's incentive
         * together with every other employer's on the box.
         *
         * The foreign row has to be planted as the other tenant: row-level security refuses an
         * insert whose company_id is not the session's, which is itself worth knowing — the
         * database would stop this even if the controller did not.
         */
        $ours = $this->claimant('Ours', months: 3);

        // A real second company, because eti_claims.company_id is a foreign key — a made-up id
        // would be refused for the wrong reason and prove nothing about the scoping.
        $otherCompanyId = (int) Company::create(['name' => 'Another Employer'])->id;

        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $otherCompanyId]);

        try {
            DB::table('eti_claims')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'company_id' => $otherCompanyId,
                'employee_type' => EtiClaim::HR,
                'employee_id' => User::factory()->create(['status' => 1])->id,
                'period_key' => '2026-01',
                'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
                'tax_year' => 2026, 'claim_month' => 1, 'eligible' => true,
                'amount' => 1500, 'monthly_remuneration' => 4000, 'age' => 24,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } finally {
            DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);
        }

        $this->actingAs($this->hrManager)
            ->get(route('payroll.eti'))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.employee_id', $ours->id));
    }
}
