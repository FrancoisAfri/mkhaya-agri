<?php

namespace Tests\Feature\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Where is the company name to choose here?"
 *
 * Reported from hub.mkhayamk.co.za: two businesses set up, the Businesses screen listing both and
 * saying which one is being worked in — and no picker anywhere in the app bar to change it.
 *
 * The cause was a second copy of "which company is this?". CurrentEntity resolves it with the
 * sole-company fallback a self-hosted install needs, so every finance query really was scoped to
 * Mkhaya Move. The app-bar picker asked the same question for itself, stopped at
 * `users.company_id`, and a platform administrator on a standalone box has neither that nor a
 * CurrentCompany — so it concluded there was no tenant and rendered nothing. The module was scoped
 * to a business it would not name, which is worse than not being scoped at all: the user cannot
 * see which set of books they are invoicing from.
 *
 * So the picker now asks CurrentEntity, and there is one answer rather than two.
 */
class SelfHostedSeesWhichBusinessTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        // The shape of the reported install: a platform administrator, who operates above tenancy
        // and therefore carries no company of their own, and no "view as company" in force.
        $this->admin = User::first() ?? User::factory()->create();
        $this->admin->forceFill(['company_id' => null, 'fin_entity_id' => null])->saveQuietly();

        app(CurrentCompany::class)->clear();
        app(CurrentEntity::class)->clear();

        // Let the connection read; the defect under test is the PHP-side null guard, not RLS.
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function entity(string $name): FinEntity
    {
        return FinEntity::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'name'       => $name.' '.Str::random(4),
            'status'     => 'active',
        ]);
    }

    private function openBusinesses(): array
    {
        return $this->actingAs($this->admin)
            ->withoutMiddleware([
                \App\Http\Middleware\EnforceCrudPermissions::class,
                \App\Http\Middleware\EnsureModuleIsActive::class,
                \App\Http\Middleware\EnsureModuleTier::class,
            ])
            ->get(route('finance.accounting.entities'))
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_a_standalone_admin_is_told_which_business_they_are_in(): void
    {
        config(['edition.edition' => 'self_hosted']);

        $mk   = $this->entity('Mkhaya MK');
        $move = $this->entity('Mkhaya Move');

        $props = $this->openBusinesses();

        $this->assertNotNull(
            $props['financeEntity'] ?? null,
            'The app bar was given no business to name, so the picker renders nothing and the '
            .'administrator cannot see or change which set of books they are working in.'
        );

        $this->assertNotNull($props['financeEntity']['current'] ?? null);

        $offered = array_column($props['financeEntity']['options'], 'id');
        $this->assertContains($mk->id, $offered);
        $this->assertContains($move->id, $offered);
    }

    public function test_the_picker_and_the_module_agree_on_the_business(): void
    {
        config(['edition.edition' => 'self_hosted']);

        $this->entity('Mkhaya MK');
        $this->entity('Mkhaya Move');

        $props = $this->openBusinesses();

        // The failure this replaces was precisely a disagreement: scoping said one thing, the app
        // bar said there was nothing to say.
        $this->assertSame(
            app(CurrentEntity::class)->id(),
            $props['financeEntity']['current']['id'],
            'The picker names a different business from the one the queries are scoped to.'
        );
    }

    public function test_multi_tenant_still_refuses_to_guess(): void
    {
        config(['edition.edition' => 'saas']);

        $this->entity('Mkhaya MK');
        $this->entity('Mkhaya Move');

        app(CurrentEntity::class)->clear();

        // On SaaS a platform admin with no company chose no tenant, and there is no single right
        // answer to fall back to. Naming one here would be the cross-tenant guess the whole
        // sole-company guard exists to prevent.
        $this->assertNull(
            $this->openBusinesses()['financeEntity'] ?? null,
            'A platform admin who has not entered a tenant was handed one anyway.'
        );
    }
}
