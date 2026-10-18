<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\NumberSequence;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\Manufacturing\Models\UomConversion;
use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use App\Modules\Operations\Manufacturing\Services\NumberSequences;
use App\Modules\Operations\Manufacturing\Services\UomConverter;
use App\Modules\Tenancy\Models\Company;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MFG-001.05 (units and valid conversions) and MFG-001.06 (document numbering).
 *
 * "Valid" is the operative word for units: the system must refuse the conversions that have no
 * answer rather than invent one, because an invented kg-to-litre factor mis-states a requirement
 * by the density and nobody sees it.
 */
class UnitsAndNumberingTest extends ManufacturingTestCase
{
    private function converter(): UomConverter
    {
        return new UomConverter();
    }

    // ── Units ────────────────────────────────────────────────────────────────────────────────

    public function test_units_of_one_dimension_convert_without_anything_being_configured(): void
    {
        $this->asCompany($this->company, function () {
            $c = $this->converter();

            $this->assertEqualsWithDelta(2000.0, $c->convert(2, $this->uom('kg'), $this->uom('g')), 1e-9);
            $this->assertEqualsWithDelta(0.5, $c->convert(500, $this->uom('g'), $this->uom('kg')), 1e-9);
            $this->assertEqualsWithDelta(1.5, $c->convert(1500, $this->uom('ml'), $this->uom('l')), 1e-9);
            $this->assertEqualsWithDelta(2.5, $c->convert(2500, $this->uom('mm'), $this->uom('m')), 1e-9);
            $this->assertEqualsWithDelta(1.0, $c->convert(1000, $this->uom('kg'), $this->uom('t')), 1e-9);
        });
    }

    public function test_a_conversion_across_dimensions_is_refused_rather_than_guessed(): void
    {
        $this->asCompany($this->company, function () {
            $c = $this->converter();

            $this->assertFalse($c->canConvert($this->uom('kg'), $this->uom('l')));

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('measure different things');

            $c->convert(1, $this->uom('kg'), $this->uom('l'));
        });
    }

    public function test_an_item_conversion_works_in_both_directions_from_one_row(): void
    {
        $item = $this->material('BOLTS', 'ea');

        $this->asCompany($this->company, function () use ($item) {
            // 1 pack = 12 units, recorded once.
            UomConversion::create([
                'company_id' => $this->company->id, 'stock_item_id' => $item->id,
                'from_uom_id' => $this->uom('pack')->id, 'to_uom_id' => $this->uom('ea')->id, 'factor' => 12,
            ]);

            $c = $this->converter();

            $this->assertEqualsWithDelta(36.0, $c->convert(3, $this->uom('pack'), $this->uom('ea'), $item->id), 1e-9);
            // And back, by inversion -- nobody should have to record both rows and let them drift.
            $this->assertEqualsWithDelta(3.0, $c->convert(36, $this->uom('ea'), $this->uom('pack'), $item->id), 1e-9);
        });
    }

    public function test_an_item_conversion_does_not_leak_onto_another_item(): void
    {
        $dozen = $this->material('DOZEN-ITEM', 'ea');
        $other = $this->material('OTHER-ITEM', 'ea');

        $this->asCompany($this->company, function () use ($dozen, $other) {
            UomConversion::create([
                'company_id' => $this->company->id, 'stock_item_id' => $dozen->id,
                'from_uom_id' => $this->uom('pack')->id, 'to_uom_id' => $this->uom('ea')->id, 'factor' => 12,
            ]);

            // A pack is not a fixed number of units; how many depends on the item.
            $this->assertFalse($this->converter()->canConvert($this->uom('pack'), $this->uom('ea'), $other->id));

            $this->expectException(DomainException::class);
            $this->converter()->convert(1, $this->uom('pack'), $this->uom('ea'), $other->id);
        });
    }

    public function test_display_rounding_follows_the_target_units_own_precision(): void
    {
        $this->asCompany($this->company, function () {
            // ea has 0 decimal places: half a unit is not a thing you can issue.
            $this->assertSame(0, (int) $this->uom('ea')->decimal_places);

            // 1 g = 0.001 kg; kg keeps 3 places.
            $this->assertEqualsWithDelta(
                0.001, $this->converter()->convertForDisplay(1, $this->uom('g'), $this->uom('kg')), 1e-9
            );
        });
    }

    public function test_a_dimension_can_have_only_one_base_unit(): void
    {
        try {
            DB::transaction(fn () => DB::table('mfg_uoms')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id,
                'code' => 'oz', 'name' => 'Ounce', 'dimension' => 'MASS', 'is_base' => true,
                'factor_to_base' => 28.3495, 'decimal_places' => 2, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('A second base unit was accepted for MASS.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('mfg_uom_one_base_per_dim', $e->getMessage());
        }
    }

    public function test_containers_have_their_own_dimension_and_never_derive_a_size(): void
    {
        // Giving "pack" a factor of 12 would make every item's pack a dozen -- and leaving it at 1
        // inside COUNT made "3 packs" equal "3 units" for every item with no conversion recorded.
        foreach (['pack', 'pallet', 'roll'] as $code) {
            $this->assertSame('CONTAINER', $this->uom($code)->dimension, $code);
            $this->assertFalse((bool) $this->uom($code)->is_base, $code);
        }

        $this->asCompany($this->company, function () {
            $c = $this->converter();

            // Not to a unit, and not to another container: a pallet is not a fixed number of packs.
            $this->assertFalse($c->canConvert($this->uom('pack'), $this->uom('ea')));
            $this->assertFalse($c->canConvert($this->uom('pack'), $this->uom('pallet')));
        });
    }

    public function test_an_existing_tenant_has_its_containers_reclassified_by_a_reinstall(): void
    {
        // As an earlier build left them: pack filed under COUNT, converting "3 packs" to "3 units".
        DB::table('mfg_uoms')->where('company_id', $this->company->id)->where('code', 'pack')
            ->update(['dimension' => 'COUNT']);

        $this->asCompany($this->company, fn () => app(ManufacturingReferenceData::class)
            ->installFor($this->company->id));

        $this->assertSame('CONTAINER', $this->uom('pack')->dimension);
    }

    // ── Reference data ───────────────────────────────────────────────────────────────────────

    public function test_installing_reference_data_twice_creates_nothing_the_second_time(): void
    {
        $again = $this->asCompany($this->company, fn () => app(ManufacturingReferenceData::class)
            ->installFor($this->company->id));

        $this->assertSame(['units' => 0, 'sequences' => 0, 'policy' => false, 'downtime_reasons' => 0], $again);
    }

    public function test_every_measurable_dimension_has_exactly_one_base_and_containers_have_none(): void
    {
        foreach (ManufacturingReferenceData::standardUnits() as $dimension => $units) {
            $bases = array_filter($units, fn ($u) => ! empty($u['base']));

            if (in_array($dimension, Uom::UNDERIVABLE, true)) {
                $this->assertCount(0, $bases, "{$dimension} measures nothing, so it must have no base unit.");

                continue;
            }

            $this->assertCount(1, $bases, "{$dimension} must have exactly one base unit.");
        }
    }

    public function test_the_install_command_fans_out_per_tenant_and_each_tenant_sees_only_its_own(): void
    {
        $other = Company::create(['name' => 'Second Smelter']);

        $this->artisan('mfg:install-reference-data', ['--company' => $other->id])->assertSuccessful();

        $mine = $this->asCompany($this->company, fn () => Uom::count());
        $theirs = $this->asCompany($other, fn () => Uom::count());

        $this->assertGreaterThan(0, $theirs, 'The command wrote nothing visible to the tenant it ran for.');
        $this->assertSame($mine, $theirs, 'Both tenants should hold the same standard set.');

        // And a row written for one is invisible to the other.
        $this->asCompany($other, fn () => Uom::where('code', 'kg')->update(['name' => 'Their kilogram']));
        $this->assertSame('Kilogram', $this->asCompany($this->company, fn () => Uom::where('code', 'kg')->value('name')));
    }

    // ── Numbering ────────────────────────────────────────────────────────────────────────────

    private function next(string $type = 'PRODUCTION_ORDER', ?int $factoryId = null): string
    {
        return $this->asCompany($this->company, fn () => app(NumberSequences::class)
            ->next($this->company->id, $type, $factoryId));
    }

    public function test_numbers_are_sequential_and_carry_the_year(): void
    {
        $year = now()->format('Y');

        $this->assertSame("PO-{$year}-00001", $this->next());
        $this->assertSame("PO-{$year}-00002", $this->next());
        $this->assertSame("PO-{$year}-00003", $this->next());

        // A different document type has its own counter.
        $this->assertSame("WO-{$year}-00001", $this->next('WORK_ORDER'));
    }

    public function test_fifty_numbers_are_fifty_distinct_numbers(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[] = $this->next('JOB_CARD');
        }

        $this->assertCount(50, array_unique($seen));
    }

    public function test_previewing_does_not_consume_a_number(): void
    {
        $service = app(NumberSequences::class);

        $first = $this->asCompany($this->company, fn () => $service->preview($this->company->id, 'MATERIAL_ISSUE'));
        $again = $this->asCompany($this->company, fn () => $service->preview($this->company->id, 'MATERIAL_ISSUE'));

        $this->assertSame($first, $again);
        $this->assertSame($first, $this->next('MATERIAL_ISSUE'), 'The previewed number is the one issued.');
    }

    public function test_a_factory_sequence_wins_over_the_company_wide_one(): void
    {
        $factory = $this->factory('CPT1');
        $year = now()->format('Y');

        $this->asCompany($this->company, fn () => NumberSequence::create([
            'company_id' => $this->company->id, 'factory_id' => $factory->id,
            'document_type' => 'PRODUCTION_ORDER', 'prefix' => 'CPT-', 'padding' => 4,
            'next_number' => 100, 'reset_period' => 'YEAR', 'is_active' => true,
        ]));

        $this->assertSame("CPT-{$year}-0100", $this->next('PRODUCTION_ORDER', $factory->id));
        // A factory with no sequence of its own falls back to the company-wide one.
        $this->assertSame("PO-{$year}-00001", $this->next('PRODUCTION_ORDER', $this->factory('DBN1')->id));
    }

    public function test_a_yearly_sequence_restarts_when_the_year_turns(): void
    {
        // It last issued into a previous year at 57. The first number in this year must be 1, not 58.
        DB::table('mfg_number_sequences')
            ->where('company_id', $this->company->id)->where('document_type', 'SCRAP_NOTE')
            ->update(['period_key' => '2019', 'next_number' => 57]);

        $this->assertSame('SN-'.now()->format('Y').'-00001', $this->next('SCRAP_NOTE'));
    }

    public function test_asking_for_an_unconfigured_document_type_says_so(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active number sequence');

        $this->next('NOT_A_DOCUMENT');
    }

    public function test_two_company_wide_sequences_for_one_document_type_are_refused(): void
    {
        // Postgres never collides two NULLs, so a plain unique key would allow it and then issue
        // the same number from both. The COALESCE index is what stops it.
        try {
            DB::transaction(fn () => DB::table('mfg_number_sequences')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->company->id,
                'factory_id' => null, 'document_type' => 'PRODUCTION_ORDER', 'prefix' => 'X-',
                'suffix' => '', 'padding' => 5, 'next_number' => 1, 'reset_period' => 'NONE',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('A second company-wide sequence was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('mfg_seq_scope_uniq', $e->getMessage());
        }
    }
}
