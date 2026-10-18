<?php

namespace Tests\Feature\Finance;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinItem;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Adding an item without filling in every box.
 *
 * Reported from the quote screen: adding a product inline 500'd with a NOT NULL violation on
 * `fin_items.unit`. The column has a default of 'each' and the form sends no unit — but a field
 * the form does not fill arrives as null, and null is not absent. The key went into the insert
 * carrying an explicit null, which overrides a column default rather than deferring to it.
 *
 * `unit` is the one that broke, and it is not the only one of its shape: sales_price, cost_price,
 * tax_rate and is_active are all NOT NULL with defaults and all optional in the request. They
 * survived only because that particular submission happened to fill them.
 */
class QuickAddingAnItemTest extends TestCase
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

        FinEntity::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => (int) $companyId, 'is_default' => true],
            ['name' => 'Books '.Str::random(4), 'status' => 'active']
        );

        $this->user = User::first() ?? User::factory()->create();
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function saveItem(array $payload)
    {
        return $this->actingAs($this->user)
            ->withoutMiddleware([
                \App\Http\Middleware\EnforceCrudPermissions::class,
                \App\Http\Middleware\EnsureModuleIsActive::class,
                \App\Http\Middleware\EnsureModuleTier::class,
            ])
            ->post(route('finance.accounting.items.store'), $payload);
    }

    public function test_the_submission_that_broke_the_quote_screen(): void
    {
        $name = 'FLYTECH P337N3 G35 '.Str::random(5);

        // Exactly what the quick-add sent: a unit key present and empty.
        $this->saveItem([
            'name'        => $name,
            'type'        => 'product',
            'sales_price' => 23100,
            'unit'        => null,
        ])->assertSessionHasNoErrors();

        $item = FinItem::withoutGlobalScopes()->where('name', $name)->first();

        $this->assertNotNull($item, 'The item was never saved.');
        $this->assertSame('each', $item->unit, "A blank unit should fall to the column's default.");
    }

    public function test_every_optional_box_can_be_left_empty(): void
    {
        $name = 'Bare item '.Str::random(5);

        // The same shape as `unit`: NOT NULL columns with defaults, all optional on the request.
        $this->saveItem([
            'name'        => $name,
            'type'        => 'service',
            'unit'        => null,
            'sales_price' => null,
            'cost_price'  => null,
            'tax_rate'    => null,
        ])->assertSessionHasNoErrors();

        $item = FinItem::withoutGlobalScopes()->where('name', $name)->first();

        $this->assertNotNull($item, 'An item with every optional box blank could not be saved.');
        $this->assertSame('each', $item->unit);
        $this->assertEquals(0, $item->sales_price);
        $this->assertEquals(15, $item->tax_rate);
    }

    public function test_clearing_a_description_still_clears_it(): void
    {
        $item = FinItem::withoutGlobalScopes()->create([
            'name'        => 'Described '.Str::random(5),
            'type'        => 'service',
            'unit'        => 'each',
            'description' => 'Something to remove',
        ]);

        // `description` is nullable, so a null there is a real instruction rather than a crash —
        // dropping it along with the others would silently ignore the user.
        $this->actingAs($this->user)
            ->withoutMiddleware([
                \App\Http\Middleware\EnforceCrudPermissions::class,
                \App\Http\Middleware\EnsureModuleIsActive::class,
                \App\Http\Middleware\EnsureModuleTier::class,
            ])
            ->patch(route('finance.accounting.items.update', ['item' => $item->id]), [
                'name'        => $item->name,
                'type'        => 'service',
                'description' => null,
            ])->assertSessionHasNoErrors();

        $this->assertNull($item->fresh()->description, 'Clearing the description was ignored.');
    }
}
