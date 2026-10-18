<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\EnvironmentalService;
use App\Modules\Operations\Sheq\Services\WasteAndSpillService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Environmental management (ENV-1 to ENV-7).
 *
 * What makes this section different from the rest of the module: environmental non-compliance is
 * reported to a REGULATOR rather than to a head office. NEMA section 30 obliges notification of
 * an incident causing significant pollution; the Waste Act leaves the generator liable for
 * hazardous waste until it is lawfully disposed of, with the manifest as the only evidence.
 *
 * So the two tests that matter are the two refusals: a reportable spill cannot be closed until
 * the notification is recorded, and hazardous waste cannot leave without a manifest number.
 */
class EnvironmentalRegisterTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

    private int $meterId;

    private string $meterUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.hazard.report', 'sheq.configure']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'E'.Str::upper(Str::random(5)), 'name' => 'Plant',
            'site_type' => 'PLANT', 'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->meterUuid = (string) Str::uuid();

        $this->meterId = (int) DB::table('sheq_environmental_meters')->insertGetId([
            'uuid' => $this->meterUuid, 'company_id' => $this->companyId,
            'site_id' => $this->siteId, 'code' => 'DUST-1', 'name' => 'Dust fallout — boundary',
            'aspect' => 'AIR', 'unit' => 'mg/m2/day', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function registers(): WasteAndSpillService
    {
        return app(WasteAndSpillService::class);
    }

    // ── ENV-1 ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_aspect_records_the_activity_the_aspect_and_the_impact(): void
    {
        $saved = $this->registers()->saveAspect($this->companyId, null, [
            'activity' => 'Cutting concrete',
            'aspect' => 'Dust emission',
            'impact' => 'Air quality and respiratory health',
            'medium' => 'AIR',
            'condition' => 'NORMAL',
            'site_id' => $this->siteId,
            'inherent_likelihood' => 4, 'inherent_severity' => 3,
            'residual_likelihood' => 2, 'residual_severity' => 3,
            'meter_id' => $this->meterId,
        ], $this->user->id);

        $row = DB::table('sheq_environmental_aspects')->where('id', $saved['id'])->first();

        $this->assertStringStartsWith('ENV-', $row->reference);
        $this->assertSame('AIR', $row->medium);

        // Scored the same way the risk engine scores risk, so the two registers can be read
        // side by side.
        $this->assertSame(12, (int) $row->inherent_score);
        $this->assertSame(6, (int) $row->residual_score);
    }

    public function test_significance_defaults_from_the_residual_score_and_can_be_overruled(): void
    {
        /*
         * Deriving it outright would be wrong — significance under ISO 14001 takes legal
         * obligations and interested parties into account, not only the score — but leaving it
         * blank produces an empty register of significant aspects, which is the one list an
         * auditor asks for.
         */
        $high = $this->registers()->saveAspect($this->companyId, null, [
            'activity' => 'Storing diesel', 'aspect' => 'Potential spill',
            'impact' => 'Groundwater contamination',
            'residual_likelihood' => 3, 'residual_severity' => 4,
        ], $this->user->id);

        $this->assertTrue((bool) DB::table('sheq_environmental_aspects')
            ->where('id', $high['id'])->value('is_significant'));

        $overruled = $this->registers()->saveAspect($this->companyId, null, [
            'activity' => 'Storing oil', 'aspect' => 'Potential spill',
            'impact' => 'Soil contamination',
            'residual_likelihood' => 3, 'residual_severity' => 4,
            'is_significant' => false,
        ], $this->user->id);

        $this->assertFalse((bool) DB::table('sheq_environmental_aspects')
            ->where('id', $overruled['id'])->value('is_significant'));
    }

    public function test_all_three_iso_conditions_can_be_recorded(): void
    {
        // ISO 14001 requires normal, abnormal and emergency to be considered, and the emergency
        // condition is the one left out — a tank harmless in normal operation and a catastrophe
        // when it ruptures is one aspect with two significance ratings.
        foreach (['NORMAL', 'ABNORMAL', 'EMERGENCY'] as $condition) {
            $this->assertArrayHasKey($condition, WasteAndSpillService::CONDITIONS);
        }
    }

    // ── ENV-3 ─────────────────────────────────────────────────────────────────────────────────

    public function test_hazardous_waste_cannot_leave_without_a_manifest_number(): void
    {
        /*
         * The generator remains liable until the waste is lawfully disposed of, and the manifest
         * is the evidence. Accepting the record without one files a liability and calls it
         * compliance — and the moment to insist is while the truck is still at the gate.
         */
        try {
            $this->registers()->recordWaste($this->companyId, [
                'waste_stream' => 'Contaminated rags',
                'is_hazardous' => true,
                'quantity' => 240,
                'site_id' => $this->siteId,
            ], $this->user->id);

            $this->fail('Hazardous waste was recorded with no manifest number.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('manifest', strtolower($e->getMessage()));
        }

        // Non-hazardous waste needs none.
        $general = $this->registers()->recordWaste($this->companyId, [
            'waste_stream' => 'General builders rubble',
            'is_hazardous' => false, 'quantity' => 4000,
        ], $this->user->id);

        $this->assertStringStartsWith('WST-', $general['reference']);
    }

    public function test_the_chain_of_custody_is_recorded_and_the_manifest_can_be_returned(): void
    {
        $saved = $this->registers()->recordWaste($this->companyId, [
            'waste_stream' => 'Waste oil',
            'is_hazardous' => true,
            'classification_code' => 'HW-09',
            'quantity' => 600, 'unit' => 'L',
            'site_id' => $this->siteId,
            'transporter' => 'Enviro Transport', 'transporter_permit' => 'WML-123',
            'vehicle_registration' => 'CA 123-456',
            'destination' => 'Vissershok H:H', 'destination_permit' => 'WML-456',
            'manifest_number' => 'MF-2026-0099',
            'disposal_route' => 'TREATMENT',
        ], $this->user->id);

        $row = DB::table('sheq_waste_movements')->where('id', $saved['id'])->first();

        // ENV-3's list: stream, quantity, hazardous, transporter, contractor, manifest,
        // destination, evidence.
        $this->assertSame('Enviro Transport', $row->transporter);
        $this->assertSame('Vissershok H:H', $row->destination);
        $this->assertSame('MF-2026-0099', $row->manifest_number);
        $this->assertFalse((bool) $row->manifest_returned);

        $this->registers()->returnManifest($this->companyId, $row->uuid, null, $this->user->id);

        $this->assertTrue((bool) DB::table('sheq_waste_movements')
            ->where('id', $saved['id'])->value('manifest_returned'));
    }

    public function test_an_old_hazardous_consignment_with_no_manifest_back_is_flagged(): void
    {
        // The waste left, somebody signed for it, and until the copy comes back there is no
        // proof it arrived anywhere lawful.
        $saved = $this->registers()->recordWaste($this->companyId, [
            'waste_stream' => 'Asbestos sheeting', 'is_hazardous' => true,
            'quantity' => 1200, 'manifest_number' => 'MF-OLD',
            'site_id' => $this->siteId,
        ], $this->user->id);

        DB::table('sheq_waste_movements')->where('id', $saved['id'])
            ->update(['removed_on' => Carbon::today()->subDays(30)->toDateString()]);

        $row = collect($this->registers()->waste($this->companyId))
            ->firstWhere('reference', $saved['reference']);

        $this->assertTrue($row['manifest_outstanding']);

        $this->assertSame(1,
            $this->registers()->kpis($this->companyId)['waste']['manifests_outstanding']);
    }

    // ── ENV-4 ─────────────────────────────────────────────────────────────────────────────────

    public function test_whether_a_spill_is_reportable_is_computed_not_asked(): void
    {
        /*
         * Somebody deciding in the moment whether forty litres in a stormwater drain is
         * reportable will reasonably say no, because nothing visible happened — and the duty
         * does not depend on what was visible.
         */
        $contained = $this->registers()->recordSpill($this->companyId, [
            'substance' => 'Hydraulic oil', 'quantity' => 40,
            'receiving_environment' => 'CONTAINED', 'site_id' => $this->siteId,
        ], $this->user->id);

        $this->assertFalse($contained['notifiable']);

        $drain = $this->registers()->recordSpill($this->companyId, [
            'substance' => 'Hydraulic oil', 'quantity' => 40,
            'receiving_environment' => 'STORMWATER', 'site_id' => $this->siteId,
        ], $this->user->id);

        $this->assertTrue($drain['notifiable']);

        // And a reportable spill raises a corrective action without being asked: it has a cause
        // worth fixing by definition.
        $this->assertNotNull(DB::table('sheq_spills')->where('id', $drain['id'])
            ->value('action_id'));
    }

    public function test_a_non_hazardous_spill_is_not_reportable_wherever_it_went(): void
    {
        // Water from a burst pipe reaching a watercourse is not a pollution incident, and
        // treating it as one trains people to ignore the flag.
        $spill = $this->registers()->recordSpill($this->companyId, [
            'substance' => 'Potable water', 'is_hazardous' => false,
            'receiving_environment' => 'WATERCOURSE',
        ], $this->user->id);

        $this->assertFalse($spill['notifiable']);
    }

    public function test_a_reportable_spill_cannot_be_closed_before_the_notification(): void
    {
        /*
         * The one refusal in this register, and the reason it exists: a spill cleaned up within
         * the hour feels finished, and the statutory duty outlives the clean-up. A closed record
         * is one nobody revisits.
         */
        $spill = $this->registers()->recordSpill($this->companyId, [
            'substance' => 'Diesel', 'quantity' => 200,
            'receiving_environment' => 'SOIL', 'site_id' => $this->siteId,
        ], $this->user->id);

        $uuid = DB::table('sheq_spills')->where('id', $spill['id'])->value('uuid');

        try {
            $this->registers()->updateSpill($this->companyId, $uuid,
                ['cleaned_up' => true, 'close' => true], $this->user->id);

            $this->fail('A reportable spill was closed with no notification recorded.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('notified', strtolower($e->getMessage()));
        }

        $this->registers()->recordNotification($this->companyId, $uuid, [
            'authority_name' => 'Department of Water and Sanitation',
            'authority_reference' => 'DWS/2026/114',
        ], $this->user->id);

        $this->registers()->updateSpill($this->companyId, $uuid, ['close' => true],
            $this->user->id);

        $this->assertSame('CLOSED', DB::table('sheq_spills')
            ->where('id', $spill['id'])->value('status'));
    }

    public function test_a_notification_has_to_name_the_authority(): void
    {
        // "Notified" with nobody named is not evidence of notification.
        $spill = $this->registers()->recordSpill($this->companyId, [
            'substance' => 'Diesel', 'receiving_environment' => 'SEWER',
        ], $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/name the authority/i');

        $this->registers()->recordNotification($this->companyId,
            DB::table('sheq_spills')->where('id', $spill['id'])->value('uuid'),
            ['authority_name' => '  '], $this->user->id);
    }

    public function test_a_contained_spill_closes_without_ceremony(): void
    {
        $spill = $this->registers()->recordSpill($this->companyId, [
            'substance' => 'Paint', 'receiving_environment' => 'CONTAINED',
        ], $this->user->id);

        $this->registers()->updateSpill($this->companyId,
            DB::table('sheq_spills')->where('id', $spill['id'])->value('uuid'),
            ['cleaned_up' => true, 'close' => true], $this->user->id);

        $this->assertSame('CLOSED', DB::table('sheq_spills')
            ->where('id', $spill['id'])->value('status'));
    }

    // ── ENV-5 ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_reading_over_its_limit_is_stamped_as_a_breach(): void
    {
        DB::table('sheq_environmental_meters')->where('id', $this->meterId)->update([
            'threshold_value' => 600, 'warning_value' => 450,
            'threshold_source' => 'SANS 1929 residential limit', 'capa_on_breach' => true,
        ]);

        $result = app(EnvironmentalService::class)->record($this->companyId, [
            'meter_id' => $this->meterId, 'value' => 720,
            'reading_date' => Carbon::today()->toDateString(),
        ], $this->user->id);

        $this->assertTrue($result['breached']);
        $this->assertNotNull($result['action_reference']);

        $row = DB::table('sheq_environmental_readings')->where('id', $result['id'])->first();

        $this->assertTrue((bool) $row->breached_threshold);
        /*
         * The limit as it was, stamped. A threshold changes — a licence is renewed on different
         * terms — and a reading judged against today's limit would rewrite whether last year was
         * compliant.
         */
        $this->assertSame('600.0000', (string) $row->threshold_at_reading);
        $this->assertNotNull($row->action_id);

        /*
         * And the alert actually reached the queue. Asserted because the first version of this
         * did not: the dispatch insert was failing on a varchar(40) column and the catch was
         * swallowing it, so the test passed while no alert was ever queued.
         */
        $this->assertTrue(DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.environmental.threshold_exceeded')->exists());
    }

    public function test_a_reading_between_the_warning_and_the_limit_is_not_a_breach(): void
    {
        DB::table('sheq_environmental_meters')->where('id', $this->meterId)->update([
            'threshold_value' => 600, 'warning_value' => 450,
        ]);

        $result = app(EnvironmentalService::class)->record($this->companyId, [
            'meter_id' => $this->meterId, 'value' => 500,
        ], $this->user->id);

        $this->assertFalse($result['breached']);
        $this->assertTrue($result['warning']);
    }

    public function test_a_meter_with_no_limit_never_breaches(): void
    {
        // Most meters are consumption counters with no limit at all, and a breach against a
        // null limit would make every reading an exceedance.
        $result = app(EnvironmentalService::class)->record($this->companyId, [
            'meter_id' => $this->meterId, 'value' => 999999,
        ], $this->user->id);

        $this->assertFalse($result['breached']);
        $this->assertNull($result['threshold']);
    }

    public function test_a_breach_can_open_a_real_investigation(): void
    {
        // The same root-cause engine incidents and non-conformances use: a limit exceedance
        // worth investigating is worth investigating properly, with a method and a depth.
        DB::table('sheq_environmental_meters')->where('id', $this->meterId)->update([
            'threshold_value' => 100, 'capa_on_breach' => true,
            'investigate_on_breach' => true,
        ]);

        $result = app(EnvironmentalService::class)->record($this->companyId, [
            'meter_id' => $this->meterId, 'value' => 150,
        ], $this->user->id);

        $investigation = DB::table('sheq_investigations')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'ENV_READING')
            ->where('subject_id', $result['id'])
            ->first(['method', 'minimum_causes']);

        $this->assertNotNull($investigation);
        $this->assertGreaterThanOrEqual(3, (int) $investigation->minimum_causes);
    }

    public function test_a_warning_above_the_limit_is_refused_as_a_configuration_mistake(): void
    {
        // It would never fire, because anything past it has already breached.
        $this->actingAs($this->user)
            ->post("/sheq/environmental-registers/meters/{$this->meterUuid}/threshold", [
                'threshold_value' => 100, 'warning_value' => 150,
            ])
            ->assertSessionHasErrors('warning_value');
    }

    // ── ENV-6 ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_aspect_can_point_at_a_real_legal_obligation(): void
    {
        /*
         * A real foreign key rather than a typed reference, so that when the legal register is
         * updated — and it is, constantly — the aspects pointing at the changed line can be
         * found. A typed "NEMA s28" cannot be queried when NEMA changes.
         */
        $packId = (int) (DB::table('sheq_regulatory_packs')->value('id') ?? 0);

        if ($packId === 0) {
            $this->markTestSkipped('No regulatory pack, which a legal register line requires.');
        }

        $lineId = (int) DB::table('sheq_legal_register_lines')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            // A legal register line belongs to a regulatory pack; it is seeded from one rather
            // than typed, which is why the column is NOT NULL.
            'regulatory_pack_id' => $packId, 'seeded_from_version' => 1,
            'instrument' => 'NEMA', 'clause' => 's28',
            'requirement' => 'Duty of care to prevent significant pollution.',
            'applies_to' => 'ALL', 'evidence_types' => json_encode([]),
            'is_applicable' => true, 'status' => 'COMPLIANT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $saved = $this->registers()->saveAspect($this->companyId, null, [
            'activity' => 'Storing diesel', 'aspect' => 'Potential spill',
            'impact' => 'Groundwater contamination',
            'legal_register_line_id' => $lineId, 'is_significant' => true,
        ], $this->user->id);

        $row = collect($this->registers()->aspects($this->companyId))
            ->firstWhere('reference', $saved['reference']);

        $this->assertSame('NEMA', $row['legal_instrument']);
        $this->assertSame('s28', $row['legal_clause']);

        // And a significant aspect with nothing behind it is counted, because a register
        // disconnected from the legal register is one nobody can prove is complete.
        $this->registers()->saveAspect($this->companyId, null, [
            'activity' => 'Burning waste', 'aspect' => 'Emissions',
            'impact' => 'Air quality', 'is_significant' => true,
        ], $this->user->id);

        $this->assertSame(1, $this->registers()->kpis($this->companyId)
            ['aspects']['without_legal_link']);
    }

    // ── ENV-7 ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_kpis_cover_everything_the_requirement_lists(): void
    {
        $this->registers()->recordWaste($this->companyId, [
            'waste_stream' => 'Rubble', 'quantity' => 3000, 'unit' => 'KG',
            'disposal_route' => 'RECYCLING',
        ], $this->user->id);

        $this->registers()->recordSpill($this->companyId, [
            'substance' => 'Diesel', 'receiving_environment' => 'SOIL',
        ], $this->user->id);

        app(EnvironmentalService::class)->record($this->companyId,
            ['meter_id' => $this->meterId, 'value' => 300], $this->user->id);

        $kpis = $this->registers()->kpis($this->companyId);

        // ENV-7's list: waste, water, energy, emissions, spills, environmental incidents.
        $this->assertSame(3000.0, $kpis['waste']['total_kg']);
        $this->assertSame(1, $kpis['waste']['diverted']);
        $this->assertSame(1, $kpis['spills']['total']);
        $this->assertSame(1, $kpis['spills']['notifiable']);
        // The one that matters: a reportable spill nobody reported is the finding.
        $this->assertSame(1, $kpis['spills']['notification_outstanding']);
        $this->assertNotEmpty($kpis['meters']);
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_consumption_screen_and_the_register_screen_are_both_reachable(): void
    {
        /*
         * Two pages rather than one. They are read by different people for different reasons — a
         * sustainability report is compiled from meter totals once a quarter, and a reportable
         * spill is cleared by whoever is on site this afternoon.
         */
        $routes = collect(app('router')->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'sheq/environmental'))
            ->values();

        $this->assertContains('sheq/environmental', $routes->all());
        $this->assertContains('sheq/environmental-registers', $routes->all());
    }

    public function test_recording_a_spill_needs_the_entitlement(): void
    {
        $this->actingAs($this->userWith([]))
            ->post('/sheq/environmental-registers/spills', [
                'substance' => 'Oil', 'receiving_environment' => 'SOIL',
            ])->assertForbidden();
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'env-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }
}
