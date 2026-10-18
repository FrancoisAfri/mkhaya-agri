<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One tenant, several trading names.
 *
 * Mkhaya invoices as Mkhaya MK, Mkhaya Move and Mkhaya Hub. Each needs its own letterhead, VAT
 * number and banking details; the staff, payroll and subscription behind them stay single. This
 * is the foundation that carries that: the entity itself, how a request decides which one it is
 * working in, and how the settings that print on a document follow from it.
 *
 * The rule these tests exist to hold: what belongs to one entity is never read as another's, and
 * a tenant's boundary is never crossed at all.
 */
class MultiEntityFoundationTest extends TestCase
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
            'name'       => $name,
            'status'     => 'active',
        ], $attrs));
    }

    // ── The entity itself ────────────────────────────────────────────────────

    public function test_a_tenant_can_trade_under_several_names(): void
    {
        $mk = $this->entity('Mkhaya MK '.Str::random(4));
        $move = $this->entity('Mkhaya Move '.Str::random(4));

        $this->assertNotSame($mk->id, $move->id);
        $this->assertSame($this->companyId, $move->company_id);
    }

    /** Two entities named the same thing must both be creatable; the slug is unique, not the name. */
    public function test_two_entities_with_the_same_name_do_not_collide(): void
    {
        $name = 'Mkhaya Move '.Str::random(4);

        $first = $this->entity($name);
        $second = $this->entity($name);

        $this->assertNotSame($first->slug, $second->slug);
    }

    /**
     * The database allows one default per tenant.
     *
     * The application is supposed to keep this true; the partial unique index is what makes it so
     * even when it forgets, because two defaults means a user's landing entity is decided by row
     * order.
     */
    public function test_a_tenant_cannot_have_two_default_entities(): void
    {
        // Every tenant with finance data already has a default, given one by the backfill. Only
        // create one where there is none, so the test asserts the constraint rather than asserting
        // what the database happened to contain when it was written.
        if (! FinEntity::withoutGlobalScopes()->where('company_id', $this->companyId)->where('is_default', true)->exists()) {
            $this->entity('First '.Str::random(4), ['is_default' => true]);
        }

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->entity('Second '.Str::random(4), ['is_default' => true]);
    }

    // ── Which entity a request is in ─────────────────────────────────────────

    public function test_a_request_lands_in_the_tenants_default_entity(): void
    {
        $default = app(CurrentEntity::class)->defaultFor($this->companyId);

        $this->assertNotNull($default);
        $this->assertSame($default->id, app(CurrentEntity::class)->id());
    }

    public function test_switching_entity_is_remembered(): void
    {
        $move = $this->entity('Mkhaya Move '.Str::random(4));

        $this->assertTrue(app(CurrentEntity::class)->set($move->id));
        $this->assertSame($move->id, app(CurrentEntity::class)->id());
    }

    /**
     * The load-bearing one.
     *
     * A session carries an entity id across a logout and a tenant switch. If it were trusted, a
     * user would be shown another tenant's letterhead inside their own books.
     */
    public function test_an_entity_belonging_to_another_tenant_is_refused(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'slug' => 'rival-'.Str::random(6),
            'name' => 'Rival '.Str::random(5), 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $theirs = FinEntity::withoutGlobalScopes()->create([
            'company_id' => $otherCompanyId, 'name' => 'Their Brand', 'status' => 'active',
        ]);

        $this->assertFalse(app(CurrentEntity::class)->set($theirs->id),
            'An entity from another tenant was accepted as the active one.');
        $this->assertNotSame($theirs->id, app(CurrentEntity::class)->id());
    }

    /** An archived entity must not remain selected — its documents stay, its desk closes. */
    public function test_an_archived_entity_cannot_be_selected(): void
    {
        $old = $this->entity('Retired '.Str::random(4), ['status' => 'archived']);

        $this->assertFalse(app(CurrentEntity::class)->set($old->id));
    }

    /** A tenant that has never opened Accounting still gets somewhere to put its first document. */
    public function test_a_tenant_with_no_entity_is_given_a_default(): void
    {
        $freshId = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'slug' => 'fresh-'.Str::random(6),
            'name' => 'Fresh Co '.Str::random(4), 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $created = app(CurrentEntity::class)->defaultFor($freshId);

        $this->assertNotNull($created);
        $this->assertTrue($created->is_default);
        $this->assertSame($freshId, $created->company_id);
    }

    /** Console work and queued jobs have no session; `run()` is how they say which entity. */
    public function test_an_entity_can_be_pinned_for_a_callback(): void
    {
        // Selected first, so "back to normal" afterwards is a value this test set rather than
        // whichever entity the tenant happens to default to.
        $mk = $this->entity('Mkhaya MK '.Str::random(4));
        app(CurrentEntity::class)->set($mk->id);

        $move = $this->entity('Mkhaya Move '.Str::random(4));

        $inside = app(CurrentEntity::class)->run($move->id, fn () => app(CurrentEntity::class)->id());

        $this->assertSame($move->id, $inside);
        $this->assertSame($mk->id, app(CurrentEntity::class)->id(), 'The pin outlived its callback.');
    }

    // ── The letterhead that prints on a document ─────────────────────────────

    /**
     * The point of the whole exercise: two entities, two letterheads, from the same call every
     * document renderer already makes.
     */
    public function test_each_entity_prints_under_its_own_details(): void
    {
        $mk = $this->entity('Mkhaya MK '.Str::random(4));
        $move = $this->entity('Mkhaya Move '.Str::random(4));

        FinSetting::create([
            'company_id' => $this->companyId, 'entity_id' => $mk->id,
            'company_name' => 'Mkhaya MK (Pty) Ltd', 'vat_number' => '4000000001',
        ]);
        FinSetting::create([
            'company_id' => $this->companyId, 'entity_id' => $move->id,
            'company_name' => 'Mkhaya Move (Pty) Ltd', 'vat_number' => '4000000002',
        ]);

        app(CurrentEntity::class)->set($mk->id);
        $this->assertSame('4000000001', FinSetting::current()->vat_number);

        app(CurrentEntity::class)->set($move->id);
        $this->assertSame('4000000002', FinSetting::current()->vat_number,
            'A document would have printed the wrong VAT number for the entity issuing it.');
    }

    /**
     * A new entity starts blank rather than borrowing.
     *
     * Inheriting the default entity's details would be friendlier to set up and would print one
     * business's VAT number and bank account on another's invoices until somebody noticed. Blank
     * is visible; wrong is not.
     */
    public function test_a_new_entity_does_not_inherit_another_entitys_tax_details(): void
    {
        $mk = $this->entity('Mkhaya MK '.Str::random(4));
        FinSetting::create([
            'company_id' => $this->companyId, 'entity_id' => $mk->id,
            'company_name' => 'Mkhaya MK (Pty) Ltd', 'vat_number' => '4000000001',
            'bank_account_number' => '62000000001',
        ]);

        $move = $this->entity('Mkhaya Move '.Str::random(4));
        app(CurrentEntity::class)->set($move->id);

        $fresh = FinSetting::current();

        $this->assertNull($fresh->vat_number, 'A new entity inherited another entity\'s VAT number.');
        $this->assertNull($fresh->bank_account_number);
        $this->assertNull($fresh->company_name,
            'A blank letterhead must stay blank: a half-filled one reads as configured.');
        $this->assertFalse($fresh->exists, 'Settings should not be written until somebody saves them.');
    }

    /** The migration gathered what was already there; nothing a tenant had should have moved. */
    public function test_existing_settings_were_gathered_into_a_default_entity(): void
    {
        $orphans = DB::table('fin_settings')
            ->whereNull('entity_id')->whereNotNull('company_id')->count();

        $this->assertSame(0, $orphans,
            'Settings rows were left without an entity, so their letterhead is unreachable.');
    }
}
