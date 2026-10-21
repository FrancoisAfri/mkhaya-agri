<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinItem;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinPriceList;
use App\Modules\Finance\Accounting\Models\FinTaxCode;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2a — what each trading entity owns is its own.
 *
 * The decision this holds to: "let separate customer and supplier per entity, one chart of
 * accounts per entity, all need to be completely separated." So a customer captured while working
 * in Mkhaya MK is not a customer of Mkhaya Move, and Mkhaya Move's account 1000 is a different
 * account from Mkhaya MK's account 1000 even though they share a code.
 *
 * Two distinct failures are worth separating, because they read the same from the outside and are
 * fixed differently:
 *
 *   Leaking   — entity B can see entity A's row. The scope is missing or not applied.
 *   Blocking  — entity B cannot create a row because entity A already used that code. The scope
 *               is applied but the database's unique key still thinks one tenant is one business.
 *
 * Every table below is checked for both.
 */
class EntitySeparatesMasterDataTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private FinEntity $mk;

    private FinEntity $move;

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

        $this->mk   = $this->entity('Mkhaya MK '.Str::random(4));
        $this->move = $this->entity('Mkhaya Move '.Str::random(4));
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
            'name'       => $name,
            'status'     => 'active',
        ]);
    }

    /** Work as this entity for the duration of the callback. */
    private function as(FinEntity $entity, callable $callback)
    {
        return app(CurrentEntity::class)->run($entity->id, $callback);
    }

    // ── Customers and suppliers ──────────────────────────────────────────────

    public function test_a_customer_captured_in_one_entity_is_not_a_customer_of_the_other(): void
    {
        $customer = $this->as($this->mk, fn () => FinParty::create([
            'kind' => 'customer', 'code' => 'ACME', 'name' => 'Acme Mining',
        ]));

        $this->as($this->mk, function () use ($customer) {
            $this->assertTrue(FinParty::whereKey($customer->id)->exists(), 'Mkhaya MK lost its own customer.');
        });

        $this->as($this->move, function () use ($customer) {
            $this->assertFalse(
                FinParty::whereKey($customer->id)->exists(),
                "Mkhaya Move can see Mkhaya MK's customer. The entity scope is not applied to fin_parties."
            );
            $this->assertSame(0, FinParty::customers()->count(), "Mkhaya Move's customer list is not empty.");
        });
    }

    public function test_a_supplier_captured_in_one_entity_is_not_a_supplier_of_the_other(): void
    {
        $supplier = $this->as($this->mk, fn () => FinParty::create([
            'kind' => 'supplier', 'code' => 'SUP-FUEL', 'name' => 'Engen',
        ]));

        $this->as($this->move, function () use ($supplier) {
            $this->assertFalse(FinParty::whereKey($supplier->id)->exists());
            $this->assertSame(0, FinParty::suppliers()->count());
        });
    }

    public function test_both_entities_may_use_the_same_customer_code(): void
    {
        $mine  = $this->as($this->mk, fn () => FinParty::create(['kind' => 'customer', 'code' => 'CUST001', 'name' => 'Acme']));
        $yours = $this->as($this->move, fn () => FinParty::create(['kind' => 'customer', 'code' => 'CUST001', 'name' => 'Acme Logistics']));

        $this->assertNotSame($mine->id, $yours->id, 'The second entity was handed the first entity\'s record.');
        $this->assertSame('CUST001', $yours->code);
    }

    public function test_one_entity_still_cannot_use_a_code_twice(): void
    {
        $this->as($this->mk, fn () => FinParty::create(['kind' => 'customer', 'code' => 'DUP', 'name' => 'First']));

        $this->expectException(QueryException::class);
        $this->as($this->mk, fn () => FinParty::create(['kind' => 'customer', 'code' => 'DUP', 'name' => 'Second']));
    }

    public function test_an_auto_generated_party_code_counts_only_within_the_entity(): void
    {
        $first  = $this->as($this->mk, fn () => FinParty::create(['kind' => 'customer', 'name' => 'First']));
        $second = $this->as($this->move, fn () => FinParty::create(['kind' => 'customer', 'name' => 'Also first, elsewhere']));

        $this->assertSame(
            $first->code,
            $second->code,
            'Each entity numbers its own customers, so the first customer of each is CUST0001.'
        );
    }

    // ── Chart of accounts ────────────────────────────────────────────────────

    public function test_each_entity_keeps_its_own_chart_of_accounts(): void
    {
        $bank = $this->as($this->mk, fn () => FinAccount::create([
            'code' => '1000', 'name' => 'Bank — Mkhaya MK', 'type' => 'asset',
        ]));

        $this->as($this->move, function () use ($bank) {
            $this->assertFalse(
                FinAccount::whereKey($bank->id)->exists(),
                "Mkhaya Move can see Mkhaya MK's bank account. A shared chart means a shared trial balance."
            );
        });
    }

    public function test_both_entities_may_number_an_account_1000(): void
    {
        $mine  = $this->as($this->mk, fn () => FinAccount::create(['code' => '1000', 'name' => 'Bank', 'type' => 'asset']));
        $yours = $this->as($this->move, fn () => FinAccount::create(['code' => '1000', 'name' => 'Bank', 'type' => 'asset']));

        $this->assertNotSame($mine->id, $yours->id);
        $this->assertSame($this->mk->id, $mine->entity_id);
        $this->assertSame($this->move->id, $yours->entity_id);
    }

    // ── Items, price lists, tax codes ────────────────────────────────────────

    public function test_items_price_lists_and_tax_codes_are_separated_too(): void
    {
        [$item, $list, $tax] = $this->as($this->mk, fn () => [
            FinItem::create(['code' => 'SKU1', 'name' => 'Consulting hour']),
            FinPriceList::create(['name' => 'Retail']),
            FinTaxCode::create(['code' => 'VAT15', 'name' => 'VAT 15%']),
        ]);

        $this->as($this->move, function () use ($item, $list, $tax) {
            $this->assertFalse(FinItem::whereKey($item->id)->exists(), 'Items leak between entities.');
            $this->assertFalse(FinPriceList::whereKey($list->id)->exists(), 'Price lists leak between entities.');
            $this->assertFalse(FinTaxCode::whereKey($tax->id)->exists(), 'Tax codes leak between entities.');

            // And the codes are free to be reused.
            FinItem::create(['code' => 'SKU1', 'name' => 'Delivery run']);
            FinTaxCode::create(['code' => 'VAT15', 'name' => 'VAT 15%']);
        });

        $this->assertTrue(true);
    }

    /**
     * The accounts that belong to nobody.
     *
     * Bad Debts, Retention Receivable and Staff Loans Receivable are installed by migration with
     * no company and no entity, and every tenant posts to them. A strict entity filter would hide
     * them from every business in the system, and a write-off would fail to find its contra
     * account — which is the kind of break that only shows up the first time somebody writes off
     * a debt, long after the change that caused it.
     */
    public function test_the_systems_own_accounts_are_visible_in_every_entity(): void
    {
        // Written here rather than looked for, so the rule is asserted even on a database whose
        // system accounts have since been claimed by a tenant.
        $shared = DB::table('fin_accounts')->insertGetId([
            'uuid'           => (string) Str::uuid(),
            'company_id'     => null,
            'entity_id'      => null,
            'code'           => 'SYS'.Str::random(5),
            'name'           => 'Bad Debts',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'is_postable'    => true,
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        foreach ([$this->mk, $this->move] as $entity) {
            $this->as($entity, function () use ($shared, $entity) {
                $this->assertTrue(
                    FinAccount::whereKey($shared)->exists(),
                    "A system account is invisible inside {$entity->name}."
                );
            });
        }
    }

    // ── Stamping and boundaries ──────────────────────────────────────────────

    public function test_a_record_is_stamped_with_the_entity_it_was_created_in(): void
    {
        $party = $this->as($this->move, fn () => FinParty::create(['kind' => 'customer', 'name' => 'Stamped']));

        $this->assertSame($this->move->id, $party->entity_id, 'The record did not take the selected entity.');
    }

    public function test_an_explicit_entity_is_not_overwritten_by_the_selected_one(): void
    {
        $party = $this->as($this->move, fn () => FinParty::create([
            'kind' => 'customer', 'name' => 'Filed elsewhere on purpose', 'entity_id' => $this->mk->id,
        ]));

        $this->assertSame($this->mk->id, $party->entity_id);
    }

    public function test_the_tenant_boundary_still_holds_underneath_the_entity_one(): void
    {
        $otherCompany = $this->anotherTenant();

        // Theirs is written inside their organisation: the database refuses it from ours.
        $theirs = app(\App\Modules\Tenancy\Services\TenantContext::class)->run((int) $otherCompany, fn () => FinParty::withoutGlobalScopes()->create([
            'company_id' => (int) $otherCompany, 'kind' => 'customer',
            'code' => 'THEIRS-'.Str::random(5), 'name' => 'Another tenant\'s customer',
        ]));

        foreach ([$this->mk, $this->move] as $entity) {
            $this->as($entity, function () use ($theirs) {
                $this->assertFalse(
                    FinParty::whereKey($theirs->id)->exists(),
                    'A customer of another tenant is visible. The entity scope must not stand in for the company one.'
                );
            });
        }
    }

    /**
     * A second tenant to be kept out of.
     *
     * Made here rather than skipped when the database has only one company. This is the assertion
     * that matters most in the file — an entity filter that quietly replaced the tenant filter
     * would pass every other test above and leak one customer's ledger into another's — so it must
     * not be the one that silently does not run.
     */
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

    public function test_the_migration_left_no_row_of_this_tenant_without_an_entity(): void
    {
        foreach (['fin_parties', 'fin_items', 'fin_price_lists', 'fin_price_list_items', 'fin_tax_codes', 'fin_accounts'] as $table) {
            $orphans = DB::table($table)
                ->where('company_id', $this->companyId)
                ->whereNull('entity_id')
                ->count();

            $this->assertSame(0, $orphans, "{$table} has {$orphans} row(s) that belong to no entity and are now invisible.");
        }
    }
}
