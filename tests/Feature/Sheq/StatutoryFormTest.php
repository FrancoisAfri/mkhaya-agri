<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Services\StatutoryFormService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Statutory form generation (SHEQ-REG-6).
 *
 * The forms themselves go to the Compensation Commissioner and the Provincial Director, so the
 * behaviours worth defending are about honesty rather than convenience: a field the records cannot
 * fill is left blank and reported, an override never erases what the system proposed, and an
 * incomplete form cannot be marked as submitted.
 *
 * The field labels and their order are pack content awaiting practitioner sign-off. These tests
 * assert the engine, not the legal accuracy of the layout.
 */
class StatutoryFormTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $packVersionId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'form-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manifest = app(PackLoader::class)->load(resource_path('regulatory-packs/za-ohsa'));

        // Reuse the row if the pack is already installed. `sheq:packs:install` is a normal thing
        // to have been run against a database, and a fixture that assumes a pristine table turns
        // that into fourteen unexplained unique-violation errors.
        $packId = DB::table('sheq_regulatory_packs')
            ->where('pack_id', $manifest->packId)->value('id')
            ?? DB::table('sheq_regulatory_packs')->insertGetId([
                'pack_id' => $manifest->packId,
                'name' => $manifest->label(), 'jurisdiction' => $manifest->jurisdiction(),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

        $this->packVersionId = DB::table('sheq_regulatory_pack_versions')
            ->where('pack_id', $packId)->where('version', $manifest->version)->value('id')
            ?? DB::table('sheq_regulatory_pack_versions')->insertGetId([
                'pack_id' => $packId, 'version' => $manifest->version,
                'effective_from' => '2020-01-01', 'manifest' => json_encode($manifest->manifest),
                'checksum' => $manifest->checksum(), 'signature_verified' => false,
                'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
    }

    private function service(): StatutoryFormService
    {
        return app(StatutoryFormService::class);
    }

    /** An injury incident already classified against the pack. */
    private function incident(array $overrides = [], array $person = []): int
    {
        $manifest = DB::table('sheq_regulatory_pack_versions')->find($this->packVersionId);

        $id = DB::table('sheq_incidents')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(8),
            'occurred_at' => Carbon::parse('2026-08-01 14:30:00'),
            'reported_at' => Carbon::parse('2026-08-01 15:00:00'),
            'incident_type' => 'INJURY',
            'description' => 'Operator caught a hand between the guard and the roller.',
            'immediate_action' => 'Machine isolated and locked out.',
            'location_text' => 'Line 2, packing hall',
            'activity_at_time' => 'Clearing a jam',
            'treatment_level' => 'HOSPITAL',
            'days_lost' => 5,
            'statutory_category' => 'OHSA_S24(b)', 'is_reportable' => true,
            'required_forms' => json_encode(['WCL1_OR_WCL2', 'ANNEXURE_1']),
            'regulatory_pack_id' => 'za-ohsa',
            'regulatory_pack_version' => $manifest->version,
            'status' => 'REPORTED',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        DB::table('sheq_incident_people')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'incident_id' => $id, 'role' => 'INJURED',
            'external_name' => 'Sipho Ndlovu',
            'injury_nature' => 'Crush injury to left hand',
            'body_part' => 'Left hand',
            'created_at' => now(), 'updated_at' => now(),
        ], $person));

        return $id;
    }

    private function settings(array $values): void
    {
        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            $values + ['updated_at' => now(), 'created_at' => now()]
        );
    }

    // ── Generation ──────────────────────────────────────────────────────────────────────────────

    public function test_a_form_is_prefilled_from_the_incident_record(): void
    {
        $form = $this->service()->generate($this->companyId, $this->incident(), 'ANNEXURE_1', $this->userId);

        $fields = collect($form['content']['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('key');

        $this->assertSame('2026-08-01', $fields['date_of_incident']['value']);
        $this->assertSame('14:30', $fields['time_of_incident']['value']);
        $this->assertSame('Line 2, packing hall', $fields['place']['value']);
        $this->assertSame('Sipho Ndlovu', $fields['person_name']['value']);
        $this->assertSame('Crush injury to left hand', $fields['nature_of_injury']['value']);
        // A boolean has to read as a person would write it on a form, not as `1`.
        $this->assertSame('Yes', $fields['reportable']['value']);
    }

    public function test_a_field_the_records_cannot_fill_is_reported_as_a_gap_not_invented(): void
    {
        /**
         * The most dangerous thing this code could do is put a plausible value in a form going to
         * the Compensation Commissioner. A blank the practitioner must fill is the honest outcome.
         */
        $this->settings(['coida_registration_number' => null]);

        $form = $this->service()->generate($this->companyId, $this->incident(), 'WCL1_OR_WCL2', $this->userId);

        $fields = collect($form['content']['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('key');

        $this->assertNull($fields['coida_reg_no']['value']);
        $this->assertTrue($fields['coida_reg_no']['is_gap']);
        $this->assertContains('COIDA registration number', $form['gaps']);
        $this->assertGreaterThan(0, $form['gap_count']);
    }

    public function test_employer_particulars_fill_from_sheq_settings(): void
    {
        // The COIDA number lives in SHEQ settings; without it a W.Cl.2 can never be completed.
        $this->settings([
            'coida_registration_number' => '990001234/5',
            'nature_of_business' => 'Food packaging',
        ]);

        $form = $this->service()->generate($this->companyId, $this->incident(), 'WCL1_OR_WCL2', $this->userId);
        $fields = collect($form['content']['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('key');

        $this->assertSame('990001234/5', $fields['coida_reg_no']['value']);
        $this->assertFalse($fields['coida_reg_no']['is_gap']);
    }

    public function test_the_form_records_which_pack_version_produced_it(): void
    {
        // SHEQ-REG-2: the layout is versioned with the rules that classified the incident.
        $form = $this->service()->generate($this->companyId, $this->incident(), 'ANNEXURE_1', $this->userId);

        $this->assertStringStartsWith('za-ohsa@', $form['pack']);
    }

    public function test_the_injured_person_is_used_rather_than_the_first_person_involved(): void
    {
        // A witness's name on a W.Cl.2 would be a false statement to the Commissioner.
        $incidentId = $this->incident();

        DB::table('sheq_incident_people')->where('incident_id', $incidentId)->delete();

        foreach ([
            ['role' => 'WITNESS', 'external_name' => 'Thabo Witness'],
            ['role' => 'INJURED', 'external_name' => 'Nomsa Injured', 'injury_nature' => 'Laceration'],
        ] as $person) {
            DB::table('sheq_incident_people')->insert($person + [
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'incident_id' => $incidentId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $form = $this->service()->generate($this->companyId, $incidentId, 'ANNEXURE_1', $this->userId);
        $fields = collect($form['content']['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('key');

        $this->assertSame('Nomsa Injured', $fields['person_name']['value']);
    }

    public function test_a_form_the_pack_does_not_define_is_refused_clearly(): void
    {
        $this->expectExceptionMessageMatches('/defines no form/');
        $this->service()->generate($this->companyId, $this->incident(), 'WCL_99', $this->userId);
    }

    public function test_the_required_forms_list_comes_from_the_classification(): void
    {
        $incidentId = $this->incident();

        $required = $this->service()->requiredFor($this->companyId, $incidentId);

        $this->assertEqualsCanonicalizing(['WCL1_OR_WCL2', 'ANNEXURE_1'],
            array_column($required, 'form_code'));
        // Both are defined in the shipped pack, so neither is a dead button.
        $this->assertSame([true, true], array_column($required, 'defined'));
        $this->assertSame([false, false], array_column($required, 'generated'));
    }

    // ── Overrides (SHEQ-REG-6) ──────────────────────────────────────────────────────────────────

    public function test_an_override_keeps_what_the_system_proposed(): void
    {
        $form = $this->service()->generate($this->companyId, $this->incident(), 'ANNEXURE_1', $this->userId);

        $after = $this->service()->override($this->companyId, $form['uuid'], 'place',
            'Line 2, packing hall — east end', 'The inspector asked for the exact bay.', $this->userId);

        $fields = collect($after['content']['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('key');

        $this->assertSame('Line 2, packing hall — east end', $fields['place']['value']);
        $this->assertSame('Line 2, packing hall', $fields['place']['generated'],
            'The generated value must survive the override — an audit without it is not an audit.');
        $this->assertTrue($fields['place']['overridden']);

        $this->assertSame('Line 2, packing hall', $after['overrides'][0]['generated_value']);
        $this->assertSame('The inspector asked for the exact bay.', $after['overrides'][0]['reason']);
    }

    public function test_an_override_is_written_to_the_audit_log(): void
    {
        $form = $this->service()->generate($this->companyId, $this->incident(), 'ANNEXURE_1', $this->userId);
        $this->service()->override($this->companyId, $form['uuid'], 'place', 'Elsewhere', null, $this->userId);

        $this->assertTrue(DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'statutory_form.field_overridden')->exists());
    }

    public function test_filling_a_gap_by_hand_closes_it(): void
    {
        $this->settings(['coida_registration_number' => null]);
        $form = $this->service()->generate($this->companyId, $this->incident(), 'WCL1_OR_WCL2', $this->userId);
        $before = $form['gap_count'];

        $after = $this->service()->override($this->companyId, $form['uuid'],
            'coida_reg_no', '990001234/5', 'Taken from the COIDA letter of good standing.', $this->userId);

        $this->assertSame($before - 1, $after['gap_count']);
        $this->assertNotContains('COIDA registration number', $after['gaps']);
    }

    public function test_overriding_a_field_that_does_not_exist_is_refused(): void
    {
        $form = $this->service()->generate($this->companyId, $this->incident(), 'ANNEXURE_1', $this->userId);

        $this->expectExceptionMessageMatches('/has no field/');
        $this->service()->override($this->companyId, $form['uuid'], 'invented_field', 'x', null, $this->userId);
    }

    // ── Finalisation ────────────────────────────────────────────────────────────────────────────

    public function test_a_form_with_blanks_cannot_be_finalised(): void
    {
        /**
         * Letting an incomplete W.Cl.2 be marked submitted would leave the statutory obligation
         * looking discharged when the Commissioner never received a usable form.
         */
        $this->settings(['coida_registration_number' => null]);
        $form = $this->service()->generate($this->companyId, $this->incident(), 'WCL1_OR_WCL2', $this->userId);

        $this->expectExceptionMessageMatches('/not complete/');
        $this->service()->finalise($this->companyId, $form['uuid'], $this->userId);
    }

    public function test_a_finalised_form_is_fingerprinted_and_frozen(): void
    {
        $form = $this->service()->generate($this->companyId, $this->incident(), 'ANNEXURE_1', $this->userId);

        // Fill whatever the records could not supply.
        foreach ($form['content']['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['is_gap']) {
                    $this->service()->override($this->companyId, $form['uuid'], $field['key'],
                        'Completed by hand', null, $this->userId);
                }
            }
        }

        $final = $this->service()->finalise($this->companyId, $form['uuid'], $this->userId);
        $this->assertSame('FINAL', $final['status']);

        $row = DB::table('sheq_statutory_form_instances')->where('uuid', $form['uuid'])->first();
        $this->assertNotNull($row->content_sha256, 'What was submitted must be fingerprinted.');

        // And it stops changing — a submitted form is a record of what was sent.
        $this->expectExceptionMessageMatches('/finalised/');
        $this->service()->override($this->companyId, $form['uuid'], 'place', 'changed', null, $this->userId);
    }

    public function test_another_tenants_form_is_not_found(): void
    {
        $otherCompany = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Tenant',
            'slug' => 'other-form-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $form = $this->service()->generate($this->companyId, $this->incident(), 'ANNEXURE_1', $this->userId);

        $this->expectExceptionMessageMatches('/Form not found/');
        $this->service()->view($otherCompany, $form['uuid']);
    }
}
