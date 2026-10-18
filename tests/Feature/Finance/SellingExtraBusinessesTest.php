<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Services\EntityAllowanceService;
use App\Core\User\Models\User;
use App\Http\Middleware\EnforceCrudPermissions;
use App\Http\Middleware\EnsureCompanyOwner;
use App\Modules\Tenancy\Models\BillingAddon;
use App\Modules\Tenancy\Models\BillingModule;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyAddon;
use App\Modules\Tenancy\Models\CompanyModule;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Tenancy\Services\SubscriptionBilling;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 — an extra trading business is something the platform sells.
 *
 * Two halves, and only one of them is about entities.
 *
 * The allowance: how many businesses a tenant may keep is the plan's included number plus whatever
 * they have bought, and the thing that adds one has to refuse when there is nothing left.
 *
 * The invoice: the amount raised each month has to be the amount the customer agreed to. It was
 * the bundle's list price and nothing else — no à-la-carte modules, no add-on packs, and the
 * launch price rather than the price that replaces it. All three undercharge, which is why it
 * survived; an extra business could have been sold and never charged for.
 */
class SellingExtraBusinessesTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $id = DB::table('companies')->min('id');
        if ($id === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->company = Company::findOrFail($id);
        app(CurrentCompany::class)->set($this->company->id);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->company->id]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ── The allowance ────────────────────────────────────────────────────────

    public function test_the_module_covers_one_business_on_its_own(): void
    {
        BillingModule::query()->updateOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'entities_included' => 1]);
        CompanyAddon::where('company_id', $this->company->id)->where('type', 'entities')->delete();

        $summary = app(EntityAllowanceService::class)->summary($this->company->id);

        $this->assertSame(1, $summary['included'], 'Accounting should cover one business without an add-on.');
        $this->assertSame(0, $summary['purchased']);
    }

    /**
     * An unpriced module still gets one business, not none.
     *
     * A null means nobody has decided yet. Reading it as zero would lock a paying customer out of
     * the module they are paying for, which is the wrong way to fail an unanswered question.
     */
    public function test_an_unpriced_module_still_allows_one_business(): void
    {
        BillingModule::query()->updateOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'entities_included' => null]);

        $this->assertSame(1, app(EntityAllowanceService::class)->summary($this->company->id)['included']);
    }

    public function test_buying_the_add_on_raises_the_allowance(): void
    {
        BillingModule::query()->updateOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'entities_included' => 1]);

        $addon = BillingAddon::where('type', 'entities')->orderBy('units')->firstOrFail();

        CompanyAddon::updateOrCreate(
            ['company_id' => $this->company->id, 'addon_code' => $addon->code],
            ['type' => 'entities', 'quantity' => 2, 'price_monthly' => $addon->price_monthly],
        );

        $summary = app(EntityAllowanceService::class)->summary($this->company->id);

        $this->assertSame(2 * (int) $addon->units, $summary['purchased']);
        $this->assertSame(1 + 2 * (int) $addon->units, $summary['allowance']);
    }

    public function test_the_catalogue_offers_a_business_to_buy(): void
    {
        $packages = app(EntityAllowanceService::class)->packages();

        $this->assertNotEmpty($packages, 'There is nothing in the catalogue to sell an extra business with.');

        foreach ($packages as $package) {
            $this->assertGreaterThan(0, $package['units']);
            $this->assertGreaterThan(0, $package['price_monthly'], 'An extra business is being given away.');
        }
    }

    public function test_the_allowance_counts_only_businesses_that_are_still_open(): void
    {
        BillingModule::query()->updateOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'entities_included' => 99]);

        $before = app(EntityAllowanceService::class)->summary($this->company->id)['used'];

        FinEntity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Closed '.Str::random(4), 'status' => 'archived',
        ]);

        $after = app(EntityAllowanceService::class)->summary($this->company->id)['used'];

        $this->assertSame($before, $after, 'An archived business is still using up the allowance.');
    }

    // ── The invoice ──────────────────────────────────────────────────────────

    /**
     * The whole subscription, not just the bundle.
     *
     * This is the one that matters commercially: the figure below is what
     * `subscription_invoices` is raised for, so anything it leaves out is money nobody asks for.
     */
    public function test_the_monthly_price_includes_the_modules_and_add_ons(): void
    {
        $plan = $this->planAt(500.00);
        $this->company->forceFill(['plan' => $plan->code])->saveQuietly();
        DB::table('subscriptions')->where('company_id', $this->company->id)->delete();

        CompanyModule::where('company_id', $this->company->id)->delete();
        CompanyAddon::where('company_id', $this->company->id)->delete();

        CompanyModule::create([
            'company_id' => $this->company->id, 'module_code' => 'accounting',
            'price_monthly' => 300.00, 'source' => 'addon',
        ]);

        CompanyAddon::create([
            'company_id' => $this->company->id, 'addon_code' => 'entity_1',
            'type' => 'entities', 'quantity' => 2, 'price_monthly' => 150.00,
        ]);

        $price = app(CompanyEntitlements::class)->monthlyPrice($this->company);

        $this->assertSame(
            1100.00,
            round($price, 2),
            'The monthly price is not the bundle plus the modules plus the add-ons '
            .'(500 + 300 + 2×150). An add-on that is sold and not billed is revenue lost every month.'
        );
    }

    public function test_the_invoice_raised_is_the_price_the_customer_agreed_to(): void
    {
        $plan = $this->planAt(500.00);
        $this->company->forceFill(['plan' => $plan->code, 'status' => 'active'])->saveQuietly();
        DB::table('subscriptions')->where('company_id', $this->company->id)->delete();

        CompanyModule::where('company_id', $this->company->id)->delete();
        CompanyAddon::where('company_id', $this->company->id)->delete();

        CompanyAddon::create([
            'company_id' => $this->company->id, 'addon_code' => 'entity_1',
            'type' => 'entities', 'quantity' => 1, 'price_monthly' => 150.00,
        ]);

        // A period nothing has been billed for yet.
        $month = Carbon::now()->addYears(3)->startOfMonth();
        DB::table('subscription_invoices')
            ->where('company_id', $this->company->id)->where('period', $month->format('Y-m'))->delete();

        app(SubscriptionBilling::class)->generateMonthly($month);

        $amount = DB::table('subscription_invoices')
            ->where('company_id', $this->company->id)->where('period', $month->format('Y-m'))
            ->value('amount');

        $this->assertNotNull($amount, 'No invoice was raised.');
        $this->assertSame(650.00, round((float) $amount, 2), 'The invoice left the add-on off.');
    }

    /**
     * What the screen says and what the invoice says are one number.
     *
     * A customer shown one total and charged another is the version of this that gets noticed, and
     * they are computed in two different classes — so this asserts they agree rather than trusting
     * that they will stay that way.
     */
    public function test_the_subscription_screen_and_the_invoice_agree(): void
    {
        $plan = $this->planAt(750.00);
        $this->company->forceFill(['plan' => $plan->code])->saveQuietly();
        DB::table('subscriptions')->where('company_id', $this->company->id)->delete();

        CompanyAddon::where('company_id', $this->company->id)->delete();
        CompanyAddon::create([
            'company_id' => $this->company->id, 'addon_code' => 'entity_3',
            'type' => 'entities', 'quantity' => 1, 'price_monthly' => 390.00,
        ]);

        $shown = app(\App\Modules\Tenancy\Services\CompanySubscription::class)->snapshot($this->company)['total'];
        $billed = app(CompanyEntitlements::class)->monthlyPrice($this->company);

        $this->assertSame(round((float) $shown, 2), round($billed, 2));
    }

    /**
     * Buying one on the subscription screen, and finding it on the bill.
     *
     * The whole commercial loop in one test: choose the add-on where a customer actually chooses
     * it, and the allowance rises, the total rises, and the invoice for the month says the same
     * number. Each half was covered already; nothing proved they were connected — and the screen
     * that sells it had no control for this add-on at all, so it could be neither bought nor
     * billed.
     */
    public function test_a_customer_can_buy_a_business_and_it_reaches_the_bill(): void
    {
        $plan = $this->planAt(500.00);
        $this->company->forceFill(['plan' => $plan->code, 'status' => 'active'])->saveQuietly();
        DB::table('subscriptions')->where('company_id', $this->company->id)->delete();
        CompanyAddon::where('company_id', $this->company->id)->delete();
        CompanyModule::where('company_id', $this->company->id)->delete();

        BillingModule::query()->updateOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'entities_included' => 1]);

        $addon = BillingAddon::where('type', 'entities')->where('units', 1)->firstOrFail();
        $before = app(EntityAllowanceService::class)->summary($this->company->id)['allowance'];

        $user = User::withoutGlobalScopes()->where('company_id', $this->company->id)->first()
            ?? User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($user)
            ->withoutMiddleware([
                EnforceCrudPermissions::class,
                EnsureCompanyOwner::class,
            ])
            ->post(route('tenancy.subscription.compose'), [
                'plan'    => $plan->code,
                'modules' => [],
                'addons'  => [['code' => $addon->code, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        // The allowance the module enforces.
        $after = app(EntityAllowanceService::class)->summary($this->company->id);
        $this->assertSame(
            $before + 2 * (int) $addon->units,
            $after['allowance'],
            'Buying two businesses did not raise what the module lets them keep.'
        );

        // And the money.
        $expected = 500.00 + 2 * (float) $addon->price_monthly;

        $this->assertSame(
            $expected,
            round(app(CompanyEntitlements::class)->monthlyPrice($this->company->fresh()), 2),
            'The purchase is not on the monthly price.'
        );

        $month = Carbon::now()->addYears(4)->startOfMonth();
        DB::table('subscription_invoices')
            ->where('company_id', $this->company->id)->where('period', $month->format('Y-m'))->delete();

        app(SubscriptionBilling::class)->generateMonthly($month);

        $invoiced = DB::table('subscription_invoices')
            ->where('company_id', $this->company->id)->where('period', $month->format('Y-m'))->value('amount');

        $this->assertSame($expected, round((float) $invoiced, 2), 'The invoice does not charge for the businesses bought.');
    }

    /**
     * Self-hosted: we grant it, they do not buy it.
     *
     * A standalone install has no subscription to manage — entitlement is the licence, and the
     * customer asks us for another set of books rather than reaching for a card. Every install
     * ships with a platform administrator for exactly this, so the workflow is: sign in as them,
     * open the company, add the business under Add-ons, and it becomes available.
     *
     * This asserts that path end to end, on a self-hosted edition, through the platform screen —
     * not the tenant's own subscription page, which on a standalone install is the wrong door.
     */
    public function test_a_platform_admin_can_grant_a_business_on_a_self_hosted_install(): void
    {
        config(['edition.edition' => 'self_hosted']);

        BillingModule::query()->updateOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'entities_included' => 1]);
        CompanyAddon::where('company_id', $this->company->id)->delete();

        $plan = $this->planAt(0.00);
        $this->company->forceFill(['plan' => $plan->code])->saveQuietly();

        $addon = BillingAddon::where('type', 'entities')->where('units', 1)->firstOrFail();
        $before = app(EntityAllowanceService::class)->summary($this->company->id);

        $admin = User::withoutGlobalScopes()->where('is_platform_admin', true)->first()
            ?? User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->saveQuietly();

        $this->actingAs($admin)
            ->withoutMiddleware([EnforceCrudPermissions::class])
            ->post(route('tenancy.companies.compose', ['company' => $this->company->id]), [
                'plan'    => $plan->code,
                'modules' => [],
                'addons'  => [['code' => $addon->code, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        $after = app(EntityAllowanceService::class)->summary($this->company->id);

        $this->assertSame(
            $before['allowance'] + 2 * (int) $addon->units,
            $after['allowance'],
            'A platform administrator could not grant a second set of books on a standalone install.'
        );
        $this->assertGreaterThan(0, $after['remaining'], 'The business still cannot be added.');
    }

    private function planAt(float $price): Plan
    {
        return Plan::updateOrCreate(
            ['code' => 'test-plan-'.Str::lower(Str::random(6))],
            [
                'name' => 'Test Plan', 'price_monthly' => $price,
                'regular_price_monthly' => $price, 'is_active' => true, 'sort' => 99,
            ],
        );
    }
}
