<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Beneficiary;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Setting up a bargaining council.
 *
 * The levy is calculated on every run, and until this screen the councils and rates behind it
 * could only be created with a SQL client — so in practice nobody could switch it on.
 */
class BargainingCouncilAdminTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'payroll'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Payroll', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();

        $this->actingAs(User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'bca+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => $this->companyId,
        ]));
    }

    private function council(?int $companyId = null): object
    {
        $id = DB::table('bargaining_councils')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
            'name' => 'MEIBC', 'sector' => 'Metal', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('bargaining_councils')->where('id', $id)->first();
    }

    private function employee(): int
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'e' . Str::random(6) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        DB::table('hr_people')->insert([
            'user_id' => $user->id, 'company_id' => $this->companyId,
            'first_name' => 'Thabo', 'surname' => 'Mokoena',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) $user->id;
    }

    // ── Creating one ─────────────────────────────────────────────────────────

    public function test_a_council_can_be_created_from_the_screen(): void
    {
        $fund = Beneficiary::create([
            'company_id' => $this->companyId, 'name' => 'MEIBC Fund', 'type' => 'other',
            'line_types' => ['other_deduction'], 'active' => true,
        ]);

        $this->post(route('payroll.bargaining-councils.store'), [
            'name' => 'MEIBC', 'code' => 'MEIBC', 'sector' => 'Metal and engineering',
            'beneficiary_uuid' => $fund->uuid,
        ])->assertRedirect();

        $row = DB::table('bargaining_councils')->where('name', 'MEIBC')->first();
        $this->assertNotNull($row);
        $this->assertSame($this->companyId, (int) $row->company_id);
        $this->assertSame($fund->id, (int) $row->beneficiary_id);
    }

    public function test_the_screen_shows_the_rate_in_force_and_who_is_covered(): void
    {
        $council = $this->council($this->companyId);
        DB::table('bargaining_council_rates')->insert([
            'council_id' => $council->id, 'company_id' => $this->companyId,
            'effective_from' => '2026-01-01', 'basis' => 'fixed',
            'employee_amount' => 25, 'employer_amount' => 25,
            'employee_percent' => 0, 'employer_percent' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get(route('payroll.bargaining-councils.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/HR/Payroll/BargainingCouncils/Index')
                ->where('councils.0.name', 'MEIBC')
                ->has('councils.0.current')
                ->has('employees'));
    }

    // ── Rates ────────────────────────────────────────────────────────────────

    public function test_a_new_rate_closes_the_one_it_replaces(): void
    {
        $council = $this->council($this->companyId);

        foreach (['2026-01-01' => 25, '2027-01-01' => 40] as $from => $amount) {
            $this->post(route('payroll.bargaining-councils.rates.store', $council->uuid), [
                'effective_from' => $from, 'basis' => 'fixed',
                'employee_amount' => $amount, 'employer_amount' => $amount,
            ])->assertRedirect();
        }

        $first = DB::table('bargaining_council_rates')
            ->where('council_id', $council->id)->whereDate('effective_from', '2026-01-01')->first();

        // The day before, so no date is covered twice and which levy applies is never a matter of
        // sort order.
        $this->assertSame('2026-12-31', substr((string) $first->effective_to, 0, 10));
    }

    public function test_a_percentage_rate_with_no_percentage_is_refused(): void
    {
        $council = $this->council($this->companyId);

        $this->post(route('payroll.bargaining-councils.rates.store', $council->uuid), [
            'effective_from' => '2026-01-01', 'basis' => 'percentage',
            'employee_percent' => 0, 'employer_percent' => 0,
        ])->assertSessionHas('error');

        $this->assertSame(0, DB::table('bargaining_council_rates')->where('council_id', $council->id)->count());
    }

    // ── Membership ───────────────────────────────────────────────────────────

    public function test_employees_can_be_added_and_are_not_duplicated(): void
    {
        $council = $this->council($this->companyId);
        $employeeId = $this->employee();

        $this->post(route('payroll.bargaining-councils.members.store', $council->uuid), [
            'employee_ids' => [$employeeId], 'joined_on' => '2026-01-01',
        ])->assertRedirect();

        $this->post(route('payroll.bargaining-councils.members.store', $council->uuid), [
            'employee_ids' => [$employeeId],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('bargaining_council_members')
            ->where('council_id', $council->id)->where('employee_id', $employeeId)->count());
    }

    public function test_ending_a_membership_keeps_the_row(): void
    {
        $council = $this->council($this->companyId);
        $employeeId = $this->employee();
        $this->post(route('payroll.bargaining-councils.members.store', $council->uuid), ['employee_ids' => [$employeeId]]);

        $memberId = DB::table('bargaining_council_members')->where('council_id', $council->id)->value('id');

        $this->put(route('payroll.bargaining-councils.members.end', [$council->uuid, $memberId]), [
            'left_on' => '2026-06-30',
        ])->assertRedirect();

        // Deleting it would make the payslip that carried the levy unaccountable.
        $row = DB::table('bargaining_council_members')->where('id', $memberId)->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-06-30', substr((string) $row->left_on, 0, 10));
    }

    // ── The shipped library ──────────────────────────────────────────────────

    public function test_editing_a_shipped_council_copies_it_to_this_tenant(): void
    {
        $shared = $this->council(null);
        DB::table('bargaining_council_rates')->insert([
            'council_id' => $shared->id, 'company_id' => null,
            'effective_from' => '2026-01-01', 'basis' => 'fixed',
            'employee_amount' => 25, 'employer_amount' => 25,
            'employee_percent' => 0, 'employer_percent' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->put(route('payroll.bargaining-councils.update', $shared->uuid), ['region' => 'Gauteng'])
            ->assertRedirect();

        // One employer's correction must not change the levy for every tenant on the platform.
        $this->assertNull(DB::table('bargaining_councils')->where('id', $shared->id)->value('region'));

        $copy = DB::table('bargaining_councils')
            ->where('company_id', $this->companyId)->where('name', 'MEIBC')->first();
        $this->assertNotNull($copy);
        $this->assertSame('Gauteng', $copy->region);

        // The rates come with it, or the copy would deduct nothing.
        $this->assertSame(1, DB::table('bargaining_council_rates')->where('council_id', $copy->id)->count());
    }

    public function test_adding_a_rate_to_a_shipped_council_also_copies_it_first(): void
    {
        $shared = $this->council(null);

        $this->post(route('payroll.bargaining-councils.rates.store', $shared->uuid), [
            'effective_from' => '2027-01-01', 'basis' => 'fixed',
            'employee_amount' => 30, 'employer_amount' => 30,
        ])->assertRedirect();

        $this->assertSame(0, DB::table('bargaining_council_rates')->where('council_id', $shared->id)->count());
        $this->assertSame(1, DB::table('bargaining_councils')->where('company_id', $this->companyId)->count());
    }
}
