<?php

namespace Tests\Feature\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Finance\Accounting\Services\EntityAllowanceService;
use App\Modules\Tenancy\Models\BillingModule;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Choosing a business, and staying in it.
 *
 * "We need to choose a company in the setup; once done it needs to reflect all over the module
 * till the user changes it." Two halves, and the second is the one that is easy to get wrong: a
 * selection that lives only in the session quietly reverts overnight, and somebody who spent a
 * week in Mkhaya Move comes back on Monday raising invoices under Mkhaya MK's VAT number.
 *
 * So the choice is written against the user, not only the session, and every Accounting page is
 * handed the current business so it can say which one it is showing.
 */
class EntitySwitcherTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

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

        $this->user = User::first() ?? User::factory()->create();
        $this->user->forceFill(['fin_entity_id' => null])->saveQuietly();
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function entity(string $name, array $attrs = []): FinEntity
    {
        return FinEntity::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->companyId,
            'name'       => $name.' '.Str::random(4),
            'status'     => 'active',
        ], $attrs));
    }

    private function actingAsFinanceUser(): self
    {
        return $this->actingAs($this->user)->withoutMiddleware([
            \App\Http\Middleware\EnforceCrudPermissions::class,
            \App\Http\Middleware\EnsureModuleIsActive::class,
            \App\Http\Middleware\EnsureModuleTier::class,
        ]);
    }

    // ── Choosing ─────────────────────────────────────────────────────────────

    public function test_switching_changes_what_the_module_reads(): void
    {
        $mk = $this->entity('Mkhaya MK');
        $move = $this->entity('Mkhaya Move');

        $theirs = app(CurrentEntity::class)->run($mk->id, fn () => FinParty::create([
            'kind' => 'customer', 'code' => 'SW'.Str::random(5), 'name' => 'Only MK knows this one',
        ]));

        $this->actingAsFinanceUser()
            ->post(route('finance.accounting.entities.switch', ['entity' => $move->id]))
            ->assertRedirect();

        $this->assertSame($move->id, app(CurrentEntity::class)->id());
        $this->assertFalse(
            FinParty::whereKey($theirs->id)->exists(),
            'After switching, the other business\'s customer is still visible.'
        );
    }

    /**
     * The half that a session cookie cannot do.
     *
     * Forgetting the session and asking again is what next Monday looks like.
     */
    public function test_the_choice_outlives_the_session(): void
    {
        $move = $this->entity('Mkhaya Move');

        $this->actingAsFinanceUser()
            ->post(route('finance.accounting.entities.switch', ['entity' => $move->id]));

        $this->assertSame(
            $move->id,
            (int) $this->user->fresh()->fin_entity_id,
            'The chosen business was not remembered against the user.'
        );

        // A fresh sign-in: nothing in the session, everything from the stored preference.
        session()->flush();
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->set($this->companyId);

        $this->assertSame($move->id, app(CurrentEntity::class)->id());
    }

    public function test_another_tenants_business_cannot_be_switched_into(): void
    {
        $otherCompany = $this->anotherTenant();

        // Theirs is written inside their organisation: the database refuses it from ours.
        $theirs = app(\App\Modules\Tenancy\Services\TenantContext::class)->run((int) $otherCompany, fn () => FinEntity::withoutGlobalScopes()->create([
            'company_id' => $otherCompany, 'name' => 'Somebody Else '.Str::random(4), 'status' => 'active',
        ]));

        $before = app(CurrentEntity::class)->id();

        $this->actingAsFinanceUser()
            ->post(route('finance.accounting.entities.switch', ['entity' => $theirs->id]));

        $this->assertSame($before, app(CurrentEntity::class)->id(), 'A switch into another tenant was allowed.');
        $this->assertNotSame($theirs->id, $this->user->fresh()->fin_entity_id);
    }

    public function test_an_archived_business_cannot_be_switched_into(): void
    {
        $archived = $this->entity('Closed Down', ['status' => 'archived']);

        $before = app(CurrentEntity::class)->id();

        $this->actingAsFinanceUser()
            ->post(route('finance.accounting.entities.switch', ['entity' => $archived->id]));

        $this->assertSame($before, app(CurrentEntity::class)->id());
    }

    // ── Reflected all over the module ────────────────────────────────────────

    public function test_every_accounting_page_is_told_which_business_it_is_showing(): void
    {
        $this->entity('Mkhaya MK');
        $move = $this->entity('Mkhaya Move');

        $this->actingAsFinanceUser()
            ->post(route('finance.accounting.entities.switch', ['entity' => $move->id]));

        $props = $this->actingAsFinanceUser()
            ->get(route('finance.accounting.entities'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNotNull($props['financeEntity'] ?? null, 'Accounting pages are not told the current business.');
        $this->assertSame($move->id, $props['financeEntity']['current']['id']);
        $this->assertContains(
            $move->id,
            array_column($props['financeEntity']['options'], 'id'),
            'The switcher was not offered the business the user is in.'
        );
    }

    public function test_pages_outside_the_module_are_not_given_one(): void
    {
        $this->entity('Mkhaya MK');

        $props = $this->actingAsFinanceUser()
            ->get(route('dashboard'))
            ->viewData('page')['props'];

        $this->assertNull($props['financeEntity'] ?? null, 'A non-finance page pays for the entity lookup.');
    }

    // ── Administrators who are not inside a tenant ───────────────────────────

    /**
     * A platform admin sits above any one tenant, and the page has to say so.
     *
     * This is what it did instead: "0 of 1 businesses used" over an empty list, with a button that
     * answered "No active company." — a true sentence that nobody can act on. Worse on a
     * self-hosted install, where there is no tenant switcher to reach for at all.
     */
    public function test_a_platform_admin_is_offered_the_switch_rather_than_an_empty_list(): void
    {
        $admin = $this->platformAdmin();

        $props = $this->actingAs($admin)->withoutMiddleware([
            \App\Http\Middleware\EnforceCrudPermissions::class,
            \App\Http\Middleware\EnsureModuleIsActive::class,
            \App\Http\Middleware\EnsureModuleTier::class,
        ])->get(route('finance.accounting.entities'))->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['noCompany'] ?? false, 'The page did not notice it had no tenant.');
        $this->assertNotEmpty($props['noCompanyReason'] ?? '', 'It does not say why.');
        $this->assertStringNotContainsStringIgnoringCase(
            'SHEQ',
            $props['noCompanyReason'],
            'Accounting is telling the reader about SHEQ records.'
        );
    }

    public function test_a_platform_admin_is_told_what_to_do_rather_than_just_refused(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->withoutMiddleware([
            \App\Http\Middleware\EnforceCrudPermissions::class,
            \App\Http\Middleware\EnsureModuleIsActive::class,
            \App\Http\Middleware\EnsureModuleTier::class,
        ])->post(route('finance.accounting.entities.store'), ['name' => 'Nowhere'])
          ->assertRedirect();

        $message = session('error');

        $this->assertNotNull($message, 'Adding a business without a tenant said nothing at all.');
        $this->assertStringContainsStringIgnoringCase('company', $message);
    }

    /**
     * On a single-tenant install there is one company and no switcher, so the question has one
     * possible answer and the module must give it rather than stranding the administrator.
     */
    public function test_on_a_self_hosted_install_the_sole_company_is_the_company(): void
    {
        if (DB::table('companies')->count() !== 1) {
            $this->markTestSkipped('Needs exactly one company to be a single-tenant install.');
        }

        config(['edition.edition' => 'self_hosted']);

        $admin = $this->platformAdmin();
        app(CurrentCompany::class)->clear();

        $props = $this->actingAs($admin)->withoutMiddleware([
            \App\Http\Middleware\EnforceCrudPermissions::class,
            \App\Http\Middleware\EnsureModuleIsActive::class,
            \App\Http\Middleware\EnsureModuleTier::class,
        ])->get(route('finance.accounting.entities'))->assertOk()->viewData('page')['props'];

        $this->assertFalse(
            $props['noCompany'] ?? false,
            'A self-hosted administrator was stranded on the one company their install has.'
        );
    }

    /**
     * A standalone install with leftovers still knows which company it is.
     *
     * "Exactly one row" was too literal. A self-hosted box accumulates companies that are not the
     * customer — a trial from evaluating, a `test` from setup — and refusing to resolve sent the
     * administrator to "view as company", a multi-tenant control that means nothing on a
     * single-tenant install. The active one is the company; the leftovers are not.
     */
    public function test_a_standalone_install_resolves_its_active_company_despite_leftovers(): void
    {
        config(['edition.edition' => 'self_hosted']);

        DB::table('companies')->where('id', $this->companyId)->update(['status' => 'active']);
        $leftover = $this->anotherTenant();
        DB::table('companies')->where('id', $leftover)->update(['status' => 'trial']);

        $admin = $this->platformAdmin();

        $props = $this->actingAs($admin)->withoutMiddleware([
            \App\Http\Middleware\EnforceCrudPermissions::class,
            \App\Http\Middleware\EnsureModuleIsActive::class,
            \App\Http\Middleware\EnsureModuleTier::class,
        ])->get(route('finance.accounting.entities'))->assertOk()->viewData('page')['props'];

        $this->assertFalse(
            $props['noCompany'] ?? false,
            'A standalone install with one active company and a leftover trial could not name itself.'
        );
    }

    /** Two active companies on one box is genuinely ambiguous, and says so honestly. */
    public function test_a_standalone_install_with_two_active_companies_says_what_to_change(): void
    {
        config(['edition.edition' => 'self_hosted']);

        DB::table('companies')->where('id', $this->companyId)->update(['status' => 'active']);
        $second = $this->anotherTenant();
        DB::table('companies')->where('id', $second)->update(['status' => 'active']);

        $admin = $this->platformAdmin();

        $props = $this->actingAs($admin)->withoutMiddleware([
            \App\Http\Middleware\EnforceCrudPermissions::class,
            \App\Http\Middleware\EnsureModuleIsActive::class,
            \App\Http\Middleware\EnsureModuleTier::class,
        ])->get(route('finance.accounting.entities'))->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['noCompany'] ?? false);

        $reason = $props['noCompanyReason'] ?? '';

        $this->assertStringContainsString('active companies', $reason);
        $this->assertStringNotContainsStringIgnoringCase(
            'has been set up',
            $reason,
            'It claims no company exists on an install that has several.'
        );
    }

    /** An administrator above tenancy: no CurrentCompany and no company of their own. */
    private function platformAdmin(): User
    {
        $admin = User::withoutGlobalScopes()->where('is_platform_admin', true)->first()
            ?? User::factory()->create();

        $admin->forceFill([
            'is_platform_admin' => true,
            'company_id'        => null,
            'fin_entity_id'     => null,
        ])->saveQuietly();

        app(CurrentCompany::class)->clear();
        app(CurrentEntity::class)->clear();
        session()->flush();

        return $admin;
    }

    // ── Adding one ───────────────────────────────────────────────────────────

    public function test_a_business_can_be_added_and_is_selected_immediately(): void
    {
        $this->allowEntities(9);

        $name = 'Mkhaya Hub '.Str::random(4);

        $this->actingAsFinanceUser()
            ->post(route('finance.accounting.entities.store'), ['name' => $name])
            ->assertSessionHasNoErrors();

        $created = FinEntity::withoutGlobalScopes()->where('company_id', $this->companyId)->where('name', $name)->first();

        $this->assertNotNull($created, 'The business was not created.');
        $this->assertSame($created->id, app(CurrentEntity::class)->id(), 'The new business was not selected.');
    }

    public function test_a_new_business_starts_empty(): void
    {
        $this->allowEntities(9);

        $mk = $this->entity('Mkhaya MK');

        app(CurrentEntity::class)->run($mk->id, fn () => FinParty::create([
            'kind' => 'customer', 'code' => 'E'.Str::random(5), 'name' => 'An existing customer',
        ]));

        $this->actingAsFinanceUser()
            ->post(route('finance.accounting.entities.store'), ['name' => 'Fresh '.Str::random(4)]);

        $this->assertSame(0, FinParty::count(), 'The new business inherited another business\'s customers.');
    }

    public function test_a_business_beyond_what_the_plan_covers_is_refused(): void
    {
        $this->allowEntities(1);

        // Use the allowance up.
        while (app(EntityAllowanceService::class)->canAddAnother($this->companyId)) {
            $this->entity('Filler');
        }

        $before = FinEntity::withoutGlobalScopes()->where('company_id', $this->companyId)->count();

        $this->actingAsFinanceUser()
            ->post(route('finance.accounting.entities.store'), ['name' => 'One Too Many'])
            ->assertSessionHasErrors('name');

        $this->assertSame(
            $before,
            FinEntity::withoutGlobalScopes()->where('company_id', $this->companyId)->count(),
            'A business was created past the plan allowance.'
        );
    }

    public function test_the_last_active_business_cannot_be_archived(): void
    {
        // Leave exactly one standing.
        $keep = $this->entity('The Only One');

        FinEntity::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('id', '!=', $keep->id)
            ->update(['status' => 'archived', 'is_default' => false]);

        $this->actingAsFinanceUser()
            ->patch(route('finance.accounting.entities.update', ['entity' => $keep->id]), ['status' => 'archived']);

        $this->assertSame('active', $keep->fresh()->status, 'The tenant was left with nowhere to work.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Price the accounting module at a given number of included businesses. */
    private function allowEntities(int $n): void
    {
        BillingModule::query()->updateOrCreate(
            ['code' => 'accounting'],
            ['name' => 'Accounting', 'entities_included' => $n]
        );
    }

    private function anotherTenant(): int
    {
        $existing = DB::table('companies')->where('id', '!=', $this->companyId)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $suffix = Str::random(6);

        return (int) DB::table('companies')->insertGetId([
            'uuid'              => (string) Str::uuid(),
            'name'              => 'Other Tenant '.$suffix,
            'slug'              => 'other-tenant-'.Str::lower($suffix),
            'status'            => 'active',
            'monthly_total'     => 0,
            'founding_customer' => false,
            'white_label'       => false,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
