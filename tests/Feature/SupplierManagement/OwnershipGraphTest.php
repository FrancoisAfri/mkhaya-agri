<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Services\OwnershipDiscrepancy;
use App\Modules\SupplierManagement\DueDiligence\Services\OwnershipGraph;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierEntity;
use App\Modules\SupplierManagement\Models\SupplierOwnership;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;

/**
 * Phase 2: who actually owns this supplier.
 *
 * The whole reason this exists is that the immediate shareholder is rarely the answer. A company
 * cannot be sanctioned, cannot be a PEP and cannot take a bribe — so a due-diligence system that
 * records "40% held by XYZ Investments" and stops has recorded nothing that can be screened.
 *
 * The cases that matter are the awkward ones: control split across two paths to stay under a
 * threshold, a chain that ends at a company nobody has looked through, ownership that does not add
 * up to 100%, and a structure that owns itself.
 */
class OwnershipGraphTest extends SupplierTestCase
{
    private OwnershipGraph $graph;

    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();
        $this->graph = app(OwnershipGraph::class);
    }

    private function supplier(string $name = 'ABC Holdings (Pty) Ltd'): Supplier
    {
        return Supplier::create([
            'company_name' => $name,
            'company_registration_number' => '2019/'.random_int(100000, 999999).'/07',
            'is_active' => 1,
        ]);
    }

    private function person(Supplier $supplier, string $first, string $last): SupplierPerson
    {
        return SupplierPerson::create([
            'supplier_id' => $supplier->id,
            'first_name' => $first, 'surname' => $last,
            'role' => 'shareholder', 'source' => 'supplier',
        ]);
    }

    private function entity(Supplier $supplier, string $name): SupplierEntity
    {
        return SupplierEntity::create([
            'supplier_id' => $supplier->id,
            'name' => $name,
            'source' => 'supplier',
        ]);
    }

    private function owns(Supplier $supplier, $holder, SupplierEntity $subject, ?float $percentage): SupplierOwnership
    {
        return SupplierOwnership::create([
            'supplier_id' => $supplier->id,
            'holder_kind' => $holder instanceof SupplierPerson ? 'person' : 'entity',
            'holder_id' => $holder->id,
            'subject_entity_id' => $subject->id,
            'percentage' => $percentage,
            'ownership_type' => 'shares',
            'as_at' => now()->toDateString(),
            'source' => 'supplier',
        ]);
    }

    // ── The chain ───────────────────────────────────────────────────────────

    public function test_the_ultimate_owner_behind_a_holding_company_is_found_and_the_stake_multiplied_out(): void
    {
        // ABC Holdings — 60% John Smith, 40% XYZ Investments; XYZ is 75% Mary Jones.
        // Mary's real stake in the supplier is 40% × 75% = 30%.
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);

        $john = $this->person($supplier, 'John', 'Smith');
        $mary = $this->person($supplier, 'Mary', 'Jones');
        $xyz = $this->entity($supplier, 'XYZ Investments (Pty) Ltd');

        $this->owns($supplier, $john, $root, 60);
        $this->owns($supplier, $xyz, $root, 40);
        $this->owns($supplier, $mary, $xyz, 75);

        $ubos = collect($this->graph->ultimateBeneficialOwners($supplier))->keyBy('name');

        $this->assertEquals(60.0, $ubos['John Smith']['effective_percentage']);
        $this->assertEquals(30.0, $ubos['Mary Jones']['effective_percentage'],
            'The stake through the holding company was not multiplied out.');
        $this->assertTrue($ubos['John Smith']['direct']);
        $this->assertFalse($ubos['Mary Jones']['direct']);
    }

    public function test_a_holding_split_across_two_paths_is_summed(): void
    {
        // The oldest trick in the book: 4% directly and 4% through a company, so neither figure
        // reaches a disclosure threshold on its own while the real holding is 8%.
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);

        $hidden = $this->person($supplier, 'Quiet', 'Partner');
        $vehicle = $this->entity($supplier, 'Nominee Holdings');

        $this->owns($supplier, $hidden, $root, 4);
        $this->owns($supplier, $vehicle, $root, 8);
        $this->owns($supplier, $hidden, $vehicle, 50);       // 8% × 50% = 4%

        $ubo = collect($this->graph->ultimateBeneficialOwners($supplier))->firstWhere('name', 'Quiet Partner');

        $this->assertEquals(8.0, $ubo['effective_percentage'], 'Split control was not added up.');
        $this->assertEquals(2, $ubo['paths']);
    }

    public function test_a_holding_below_the_threshold_is_not_reported_as_a_beneficial_owner(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);

        $big = $this->person($supplier, 'Major', 'Holder');
        $small = $this->person($supplier, 'Minor', 'Holder');

        $this->owns($supplier, $big, $root, 98);
        $this->owns($supplier, $small, $root, 2);

        $names = collect($this->graph->ultimateBeneficialOwners($supplier))->pluck('name');

        $this->assertContains('Major Holder', $names);
        $this->assertNotContains('Minor Holder', $names);

        // A lower threshold still finds them — the small holder is recorded, just not reported.
        $this->assertCount(2, $this->graph->ultimateBeneficialOwners($supplier, 0.0));
    }

    // ── The awkward structures ──────────────────────────────────────────────

    public function test_circular_ownership_is_reported_instead_of_hanging(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);

        $alpha = $this->entity($supplier, 'Alpha Holdings');
        $beta = $this->entity($supplier, 'Beta Holdings');

        $this->owns($supplier, $alpha, $root, 100);
        $this->owns($supplier, $beta, $alpha, 100);
        $this->owns($supplier, $alpha, $beta, 100);        // and round it goes

        $result = $this->graph->forSupplier($supplier);

        $this->assertNotEmpty($result['cycles'], 'A circular structure was not reported.');
        $this->assertStringContainsString('owned, directly or indirectly, by itself', $result['cycles'][0]);
    }

    public function test_a_chain_that_ends_at_a_company_is_a_dead_end_not_an_answer(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);

        $offshore = $this->entity($supplier, 'Opaque Nominees Ltd');
        $this->owns($supplier, $offshore, $root, 100);

        $result = $this->graph->forSupplier($supplier);

        $this->assertEmpty($result['ubos'], 'A company is not a beneficial owner.');
        $this->assertNotEmpty($result['dead_ends'], 'An unresolved chain must not look like "no owners exist".');
        $this->assertStringContainsString('Opaque Nominees Ltd', $result['dead_ends'][0]);
    }

    public function test_ownership_that_does_not_add_up_is_reported(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);

        $this->owns($supplier, $this->person($supplier, 'Declared', 'Owner'), $root, 70);

        $result = $this->graph->forSupplier($supplier);

        $this->assertCount(1, $result['unallocated']);
        $this->assertEquals(30.0, $result['unallocated'][0]['missing'],
            'The 30% nobody has named is the most useful finding on the screen.');
    }

    public function test_thirds_are_not_treated_as_a_gap(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);

        foreach (['One', 'Two', 'Three'] as $n) {
            $this->owns($supplier, $this->person($supplier, $n, 'Third'), $root, 33.33);
        }

        // 99.99% is three thirds, not a missing shareholder.
        $this->assertEmpty($this->graph->forSupplier($supplier)['unallocated']);
    }

    public function test_a_stake_of_unstated_size_is_surfaced_rather_than_guessed(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);

        $this->owns($supplier, $this->person($supplier, 'Known', 'Holder'), $root, 60);
        $this->owns($supplier, $this->person($supplier, 'Unstated', 'Holder'), $root, null);

        $gaps = $this->graph->forSupplier($supplier)['unallocated'];

        $this->assertCount(1, $gaps);
        $this->assertEquals(1, $gaps[0]['stakes_without_a_percentage']);
    }

    public function test_an_ended_stake_leaves_the_current_picture_but_stays_on_record(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);

        $former = $this->owns($supplier, $this->person($supplier, 'Former', 'Owner'), $root, 100);
        $former->update(['ended_at' => now()->subMonth()->toDateString()]);

        $this->assertEmpty($this->graph->ultimateBeneficialOwners($supplier));
        // "Who owned this when the contract was awarded" still has to be answerable.
        $this->assertEquals(1, SupplierOwnership::forSupplier($supplier->id)->count());
    }

    // ── Discrepancy against a registry ──────────────────────────────────────

    public function test_an_owner_the_registry_names_and_the_supplier_did_not_is_a_mismatch(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);
        $this->owns($supplier, $this->person($supplier, 'John', 'Smith'), $root, 100);

        $observation = app(OwnershipDiscrepancy::class)->compare($supplier, [
            ['name' => 'John Smith', 'percentage' => 60],
            ['name' => 'Patience Mahlangu', 'percentage' => 40],
        ]);

        $this->assertEquals(VerificationStatus::MISMATCH, $observation->status);
        $this->assertStringContainsString('Patience Mahlangu', $observation->note);
        $this->assertStringContainsString('did not disclose', $observation->note);

        // The gap between the two lists is where the interesting name lives, so it has to reach the
        // flags rather than sitting in an observation nobody opens.
        $this->assertDatabaseHas('supplier_risk_flags', [
            'supplier_id' => $supplier->id,
            'type' => 'verification_ownership_beneficial',
            'status' => 'open',
            'severity' => 'high',
        ]);
    }

    public function test_a_materially_different_percentage_is_a_mismatch_but_a_rounding_difference_is_not(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);
        $this->owns($supplier, $this->person($supplier, 'Thabo', 'Nkosi'), $root, 100);

        $close = app(OwnershipDiscrepancy::class)->compare($supplier, [['name' => 'Thabo Nkosi', 'percentage' => 99.5]]);
        $this->assertEquals(VerificationStatus::VERIFIED, $close->status, $close->note ?? '');

        $far = app(OwnershipDiscrepancy::class)->compare($supplier, [['name' => 'Thabo Nkosi', 'percentage' => 40]]);
        $this->assertEquals(VerificationStatus::MISMATCH, $far->status);
        $this->assertStringContainsString('declared at 100% and recorded at 40%', $far->note);
    }

    public function test_name_order_does_not_manufacture_a_discrepancy(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);
        $this->owns($supplier, $this->person($supplier, 'John', 'Smith'), $root, 100);

        // Registries write "SMITH, John". The supplier wrote "John Smith". One person.
        $observation = app(OwnershipDiscrepancy::class)->compare($supplier, [['name' => 'SMITH, John', 'percentage' => 100]]);

        $this->assertEquals(VerificationStatus::VERIFIED, $observation->status, $observation->note ?? '');
        $this->assertEquals(0, SupplierRiskFlag::where('supplier_id', $supplier->id)->count());
    }

    public function test_the_comparison_looks_through_the_chain_not_at_immediate_shareholders(): void
    {
        // The supplier declares a holding company; the registry's beneficial-ownership filing names
        // the person behind it. Comparing filings to shareholders would flag every well-structured
        // supplier as a discrepancy.
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);
        $vehicle = $this->entity($supplier, 'Family Trust Holdings');
        $mary = $this->person($supplier, 'Mary', 'Jones');

        $this->owns($supplier, $vehicle, $root, 100);
        $this->owns($supplier, $mary, $vehicle, 100);

        $observation = app(OwnershipDiscrepancy::class)->compare($supplier, [['name' => 'Mary Jones', 'percentage' => 100]]);

        $this->assertEquals(VerificationStatus::VERIFIED, $observation->status, $observation->note ?? '');
    }

    public function test_the_discrepancy_is_recorded_as_a_dated_sourced_observation(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);
        $this->owns($supplier, $this->person($supplier, 'John', 'Smith'), $root, 100);

        $observation = app(OwnershipDiscrepancy::class)->compare(
            $supplier,
            [['name' => 'Someone Else', 'percentage' => 100]],
            reference: 'CIPC-BO-2026-0042'
        );

        $this->assertEquals(CheckType::OWNERSHIP_BENEFICIAL, $observation->check_type);
        $this->assertEquals('CIPC-BO-2026-0042', $observation->reference);
        $this->assertNotNull($observation->checked_at);
        $this->assertArrayHasKey('owner: John Smith', $observation->claimed);
        $this->assertArrayHasKey('owner: Someone Else', $observation->observed);
    }

    // ── Across suppliers ────────────────────────────────────────────────────

    public function test_a_person_on_two_suppliers_links_them(): void
    {
        $first = $this->supplier('First Supplier');
        $second = $this->supplier('Second Supplier');

        $this->person($first, 'Shared', 'Director');
        $this->person($second, 'DIRECTOR', 'Shared');   // written the other way round, same person

        $related = $this->graph->relatedSuppliers($first);

        $this->assertCount(1, $related);
        $this->assertEquals($second->id, $related[0]['supplier_id']);
        $this->assertEquals('person', $related[0]['via'][0]['kind']);
    }

    public function test_a_shared_holding_company_links_two_suppliers(): void
    {
        $first = $this->supplier('First Supplier');
        $second = $this->supplier('Second Supplier');

        $this->entity($first, 'Common Parent (Pty) Ltd');
        $this->entity($second, 'COMMON PARENT PROPRIETARY LIMITED');

        $related = $this->graph->relatedSuppliers($first);

        $this->assertCount(1, $related, 'The same parent written two ways was treated as two companies.');
        $this->assertEquals('entity', $related[0]['via'][0]['kind']);
    }

    public function test_the_graph_is_tenant_scoped(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);
        $this->owns($supplier, $this->person($supplier, 'Tenant', 'One'), $root, 100);

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->makeCompany('Other Tenant'));

        $this->assertEquals(0, SupplierOwnership::count());
        $this->assertEquals(0, SupplierEntity::count());
    }

    // ── The routes ──────────────────────────────────────────────────────────

    public function test_a_stake_can_be_captured_naming_a_holder_who_is_not_on_file_yet(): void
    {
        $supplier = $this->supplier();

        $this->post("/suppliers/{$supplier->uuid}/ownership", [
            'holder_kind' => 'entity',
            'holder_name' => 'Newly Named Holdings (Pty) Ltd',
            'holder_registration_number' => '2015/000111/07',
            'percentage' => 55,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('supplier_entities', [
            'supplier_id' => $supplier->id,
            'name' => 'Newly Named Holdings (Pty) Ltd',
        ]);
        // The root node is created on demand, so the stake has something to point at.
        $this->assertEquals(1, SupplierOwnership::forSupplier($supplier->id)->count());
    }

    public function test_the_same_company_written_two_ways_does_not_become_two_nodes(): void
    {
        $supplier = $this->supplier();

        $this->post("/suppliers/{$supplier->uuid}/ownership", [
            'holder_kind' => 'entity', 'holder_name' => 'Common Parent (Pty) Ltd', 'percentage' => 50,
        ]);
        $this->post("/suppliers/{$supplier->uuid}/ownership", [
            'holder_kind' => 'entity', 'holder_name' => 'COMMON PARENT PROPRIETARY LIMITED', 'percentage' => 50,
        ]);

        // Two nodes for one company would hide the shared parent the graph exists to reveal.
        $this->assertEquals(1, SupplierEntity::where('supplier_id', $supplier->id)->where('is_root', false)->count());
        $this->assertEquals(2, SupplierOwnership::forSupplier($supplier->id)->count());
    }

    public function test_a_company_cannot_be_given_a_stake_in_itself(): void
    {
        $supplier = $this->supplier();
        $entity = $this->entity($supplier, 'Circular Holdings');

        $this->post("/suppliers/{$supplier->uuid}/ownership", [
            'holder_kind' => 'entity',
            'holder_id' => $entity->id,
            'subject_entity_id' => $entity->id,
            'percentage' => 100,
        ])->assertSessionHasErrors('holder_id');

        $this->assertEquals(0, SupplierOwnership::forSupplier($supplier->id)->count());
    }

    public function test_a_stake_is_ended_rather_than_deleted(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);
        $stake = $this->owns($supplier, $this->person($supplier, 'Outgoing', 'Owner'), $root, 100);

        $this->patch("/suppliers/{$supplier->uuid}/ownership/{$stake->id}", [
            'ended_at' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertNotNull($stake->fresh()->ended_at);
        $this->assertDatabaseHas('supplier_ownerships', ['id' => $stake->id, 'deleted_at' => null]);
    }

    public function test_a_stake_belonging_to_another_supplier_cannot_be_edited_through_this_one(): void
    {
        $mine = $this->supplier('Mine');
        $theirs = $this->supplier('Theirs');
        $stake = $this->owns($theirs, $this->person($theirs, 'Their', 'Owner'), SupplierEntity::rootFor($theirs), 100);

        $this->patch("/suppliers/{$mine->uuid}/ownership/{$stake->id}", ['percentage' => 1])
            ->assertNotFound();
    }

    public function test_the_registry_comparison_runs_from_the_route(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);
        $this->owns($supplier, $this->person($supplier, 'Declared', 'Owner'), $root, 100);

        $this->post("/suppliers/{$supplier->uuid}/ownership/compare", [
            'owners' => [
                ['name' => 'Declared Owner', 'percentage' => 60],
                ['name' => 'Hidden Partner', 'percentage' => 40],
            ],
            'source' => 'CIPC beneficial ownership',
            'reference' => 'BO-2026-1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('supplier_verifications', [
            'supplier_id' => $supplier->id,
            'check_type' => CheckType::OWNERSHIP_BENEFICIAL,
            'status' => VerificationStatus::MISMATCH,
            'reference' => 'BO-2026-1',
        ]);
    }

    public function test_the_supplier_page_carries_the_resolved_chain(): void
    {
        $supplier = $this->supplier();
        $root = SupplierEntity::rootFor($supplier);
        $vehicle = $this->entity($supplier, 'XYZ Investments');
        $mary = $this->person($supplier, 'Mary', 'Jones');

        $this->owns($supplier, $vehicle, $root, 40);
        $this->owns($supplier, $mary, $vehicle, 75);

        $this->withoutVite()
            ->get("/suppliers/{$supplier->uuid}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $ownership = $page->toArray()['props']['ownership'];

                $this->assertEquals(30.0, collect($ownership['ubos'])->firstWhere('name', 'Mary Jones')['effective_percentage']);
                $this->assertNotEmpty($ownership['unallocated'], 'The 60% nobody has named must be on the screen.');
                $this->assertCount(2, $ownership['edges']);
                // The root and both nodes it needs to draw.
                $this->assertCount(3, $ownership['nodes']);
            });
    }
}
