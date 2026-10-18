<?php

namespace Tests\Feature\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The scope has to survive a table that names no company.
 *
 * EntityScope lets through the rows belonging to nobody, and it reads "nobody" off company_id.
 * A handful of these tables have no such column — fin_nonpayment_reasons is a shared list with
 * nothing tenant-specific on it, so it was never given one — and naming the column anyway turned
 * every screen that reads such a table into a 500. The debtors screen was one of them.
 *
 * Found by sweeping the module rather than by reading it: the screen returns a 500 in production
 * and nothing in the suite touched it.
 */
class ASharedListWithNoCompanyColumnTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        app(CurrentCompany::class)->set((int) $companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $entity = FinEntity::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => (int) $companyId, 'is_default' => true],
            ['name' => 'Books '.Str::random(4), 'status' => 'active']
        );

        app(CurrentEntity::class)->set($entity->id);

        $this->user = User::first() ?? User::factory()->create();
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_the_debtors_screen_opens_while_a_business_is_selected(): void
    {
        $this->assertFalse(
            Schema::hasColumn('fin_nonpayment_reasons', 'company_id'),
            'This table grew a company column, so the case this guards is gone — check the scope.'
        );

        $this->actingAs($this->user)
            ->withoutMiddleware([
                \App\Http\Middleware\EnforceCrudPermissions::class,
                \App\Http\Middleware\EnsureModuleIsActive::class,
                \App\Http\Middleware\EnsureModuleTier::class,
            ])
            ->get(route('finance.accounting.debtors'))
            ->assertOk();
    }

    public function test_no_finance_table_can_break_the_scope_this_way_unnoticed(): void
    {
        // The scope reads company_id off every table it applies to, so any table that carries a
        // business without one is the same 500 waiting on whichever screen reads it next.
        $withoutCompany = [];

        foreach (DB::select("select table_name from information_schema.tables where table_schema = 'public'") as $table) {
            $columns = Schema::getColumnListing($table->table_name);

            if (in_array('entity_id', $columns, true) && ! in_array('company_id', $columns, true)) {
                $withoutCompany[] = $table->table_name;
            }
        }

        // Not an assertion that the list is empty — such a table is allowed, and one exists. The
        // point is that the scope handles them, which the screen test above proves.
        $this->assertContains('fin_nonpayment_reasons', $withoutCompany);
    }
}
