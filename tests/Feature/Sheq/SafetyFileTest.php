<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SafetyFileService;
use App\Modules\Operations\Sheq\Support\SafetyFileSections;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The digital contractor safety file (CON-6).
 *
 * What it replaces is a lever-arch folder with dividers, carried to the gate and photocopied when
 * it expires. Two properties make it a file rather than a pile of certificates, and most of these
 * tests are about them:
 *
 * **An empty divider is the finding.** A section with nothing in it has to be as visible as one
 * that is full, which is exactly what a list of uploaded documents cannot show.
 *
 * **It works for any industry.** The sections are data and the profiles are starting points, so
 * nothing in the engine knows a mine from a bookkeeper. A contractor who visits an office is not
 * asked for a load-test certificate, because asking is how a safety file gets ignored.
 */
class SafetyFileTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.contractor.manage', 'sheq.configure']);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'sf-'.Str::random(8).'@example.test',
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

    private function contractor(): array
    {
        return app(ContractorService::class)->register($this->companyId, [
            'name' => 'Scaffold Co', 'trade' => 'SCAFFOLDING', 'contact_email' => 'site@acme.test',
        ], $this->user->id);
    }

    private function file(string $uuid): array
    {
        return $this->actingAs($this->user)->get("/sheq/contractors/{$uuid}/safety-file")
            ->assertOk()->viewData('page')['props']['file'];
    }

    private function section(array $file, string $code): array
    {
        return collect($file['sections'])->firstWhere('code', $code);
    }

    // ── A file exists from the first moment, with no configuration ────────────────────────────

    public function test_a_fresh_installation_has_a_working_file_rather_than_an_empty_one(): void
    {
        $file = $this->file($this->contractor()['uuid']);

        // The default is computed from the shipped profile, not stored — which is why nothing had
        // to be backfilled across every company, and why there is no empty state to explain.
        $this->assertSame('GENERAL', $file['profile']);
        $this->assertCount(count(SafetyFileSections::catalogue()), $file['sections']);
        $this->assertGreaterThan(0, $file['summary']['required']);
        $this->assertDatabaseCount('sheq_safety_file_sections', 0);
    }

    public function test_every_section_in_the_specification_is_present(): void
    {
        $codes = array_column($this->file($this->contractor()['uuid'])['sections'], 'code');

        foreach (['COMPANY_REGISTRATION', 'COID', 'PUBLIC_LIABILITY', 'INSURANCE', 'HS_POLICY',
            'APPOINTMENTS', 'RISK_ASSESSMENTS', 'METHOD_STATEMENTS', 'SAFE_WORK_PROCEDURES',
            'WORKER_RECORDS', 'WORKER_COMPETENCIES', 'MEDICAL_CERTIFICATES', 'INDUCTIONS', 'PPE',
            'EQUIPMENT_CERTIFICATES', 'VEHICLE_DOCUMENTATION', 'EMERGENCY_INFORMATION',
            'ENVIRONMENTAL', 'QUALITY', 'SITE_SPECIFIC', 'PERMITS', 'AUDIT_HISTORY', 'INCIDENTS',
            'NCRS', 'CAPA'] as $code) {
            $this->assertContains($code, $codes, "The file has no {$code} section.");
        }
    }

    public function test_an_empty_section_is_reported_as_the_finding(): void
    {
        $file = $this->file($this->contractor()['uuid']);

        // The whole reason this is a file and not a document list: the gaps are named.
        $this->assertNotEmpty($file['summary']['missing']);
        $this->assertSame('MISSING', $this->section($file, 'COID')['state']);
        $this->assertFalse($file['summary']['complete']);
    }

    // ── Filing paperwork satisfies a section ──────────────────────────────────────────────────

    public function test_filing_a_document_satisfies_its_section(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'issuing_body' => 'Compensation Fund',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ])->assertRedirect();

        $section = $this->section($this->file($contractor['uuid']), 'COID');

        $this->assertSame('SATISFIED', $section['state']);
        $this->assertSame(1, $section['count']);
        $this->assertSame('Compensation Fund', $section['items'][0]['issuing_body']);
    }

    public function test_a_section_holding_only_lapsed_paperwork_is_not_satisfied(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->subDay()->toDateString(),
            ])->assertRedirect();

        $section = $this->section($this->file($contractor['uuid']), 'COID');

        /*
         * EXPIRED rather than SATISFIED-with-a-warning. Showing a lapsed certificate as satisfying
         * its section, with a note, is how an expired letter of good standing sits on a file for a
         * year — and MISSING and EXPIRED are different conversations: "send us this" against
         * "renew this".
         */
        $this->assertSame('EXPIRED', $section['state']);
        $this->assertTrue($section['items'][0]['expired']);
    }

    public function test_a_renewal_satisfies_the_section_the_lapsed_one_could_not(): void
    {
        $contractor = $this->contractor();

        foreach ([Carbon::today()->subDay(), Carbon::today()->addYear()] as $expiry) {
            $this->actingAs($this->user)
                ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                    'document_type' => 'COIDA_LETTER', 'expires_on' => $expiry->toDateString(),
                ]);
        }

        $section = $this->section($this->file($contractor['uuid']), 'COID');

        // Both are on the file — evidence is never destroyed — and one live document is enough.
        $this->assertSame('SATISFIED', $section['state']);
        $this->assertSame(2, $section['count']);
    }

    public function test_a_document_set_aside_leaves_the_file(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ]);

        $document = DB::table('sheq_contractor_documents')
            ->where('company_id', $this->companyId)->first(['uuid']);

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents/{$document->uuid}/supersede",
                ['reason' => 'Issued to the wrong legal entity; the corrected letter follows.']);

        // The file and the compliance gate must agree about what counts, or the two screens tell
        // a gatehouse different things about the same contractor.
        $this->assertSame('MISSING', $this->section($this->file($contractor['uuid']), 'COID')['state']);
    }

    public function test_a_section_expiring_soon_says_so_before_it_lapses(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addDays(10)->toDateString(),
            ]);

        $section = $this->section($this->file($contractor['uuid']), 'COID');

        $this->assertSame('SATISFIED', $section['state']);
        $this->assertSame(1, $section['expiring_soon']);
    }

    // ── Record sections assemble from what the system already holds ───────────────────────────

    public function test_the_worker_section_counts_the_workers_already_registered(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/workers", ['name' => 'T. Mokoena']);

        $section = $this->section($this->file($contractor['uuid']), 'WORKER_RECORDS');

        /*
         * Counted, not uploaded. A safety file that held its own copy of the worker list would be
         * a third place for a contractor's standing to disagree with itself — and somebody would
         * be asked to upload a list of the people they had already registered.
         */
        $this->assertSame('RECORD', $section['kind']);
        $this->assertSame(1, $section['count']);
        $this->assertSame('SATISFIED', $section['state']);
    }

    public function test_the_incident_section_is_history_and_does_not_have_to_be_filled(): void
    {
        $section = $this->section($this->file($this->contractor()['uuid']), 'INCIDENTS');

        // A contractor with no incidents has an empty section and a complete file. Demanding an
        // incident before letting somebody on site would be absurd.
        $this->assertSame(0, $section['count']);
        $this->assertSame('SATISFIED', $section['state']);
        $this->assertTrue($section['is_history']);
        $this->assertFalse($section['is_required']);
    }

    public function test_no_record_section_counts_a_table_with_no_contractor_link(): void
    {
        $sources = collect(SafetyFileSections::catalogue())
            ->filter(fn (array $s) => $s['kind'] === SafetyFileSections::RECORD)
            ->map(fn (array $s) => explode(':', $s['source'])[0])
            ->unique()->values()->all();

        /*
         * The guard on the whole design. Permits, audits and equipment carry no contractor
         * reference in this schema, so a RECORD section over them would read "0" for ever and look
         * like a contractor failing to supply something they had already supplied. Those sections
         * hold documents instead.
         */
        sort($sources);
        $this->assertSame(['CAPA', 'INCIDENTS', 'INDUCTIONS', 'WORKERS', 'WORKER_FILES'], $sources);
    }

    // ── It works for any industry ─────────────────────────────────────────────────────────────

    public function test_an_office_contractor_is_not_asked_for_a_load_test_certificate(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/profile",
                ['profile' => 'PROFESSIONAL_SERVICES'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $file = $this->file($contractor['uuid']);

        // Asking a bookkeeper for lifting-equipment certification is how a safety file gets
        // ignored by everybody, including where it matters.
        $this->assertFalse($this->section($file, 'EQUIPMENT_CERTIFICATES')['is_required']);
        $this->assertFalse($this->section($file, 'MEDICAL_CERTIFICATES')['is_required']);
        $this->assertTrue($this->section($file, 'PUBLIC_LIABILITY')['is_required']);

        // Still available, because an office-services company on a mine is still on a mine.
        $this->assertNotNull($this->section($file, 'EQUIPMENT_CERTIFICATES'));
    }

    public function test_a_mining_profile_demands_what_underground_work_demands(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/profile",
                ['profile' => 'MINING'])->assertRedirect();

        $file = $this->file($contractor['uuid']);

        $this->assertTrue($this->section($file, 'MEDICAL_CERTIFICATES')['is_required']);
        $this->assertTrue($this->section($file, 'WORKER_COMPETENCIES')['is_required']);
    }

    public function test_every_shipped_profile_demands_something_and_demands_only_real_sections(): void
    {
        $catalogue = SafetyFileSections::catalogue();

        foreach (SafetyFileSections::profiles() as $code => $profile) {
            $this->assertNotEmpty($profile['required'], "{$code} demands nothing.");

            foreach ($profile['required'] as $section) {
                // A profile naming a section the catalogue does not ship would silently demand
                // something no screen can ever satisfy.
                $this->assertArrayHasKey($section, $catalogue,
                    "{$code} demands {$section}, which is not a section.");
            }
        }
    }

    public function test_every_document_section_is_satisfiable_by_a_type_the_dropdown_offers(): void
    {
        $vocabulary = ContractorService::documentTypes();

        foreach (SafetyFileSections::catalogue() as $code => $section) {
            if ($section['kind'] !== SafetyFileSections::DOCUMENT) {
                continue;
            }

            $this->assertNotEmpty($section['types'], "{$code} can never be satisfied.");

            foreach ($section['types'] as $type) {
                /*
                 * One vocabulary. This module has already shipped two: a seeded
                 * `LETTER_OF_GOOD_STANDING` that never matched the gate's `COIDA_LETTER`, so the
                 * requirement could not be satisfied by the document that satisfied it.
                 */
                $this->assertArrayHasKey($type, $vocabulary,
                    "{$code} is satisfied by {$type}, which the upload dropdown does not offer.");
            }
        }
    }

    // ── Configuring the file ──────────────────────────────────────────────────────────────────

    public function test_a_section_can_be_renamed_because_coid_is_a_south_african_word(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/sections/COID",
                ['name' => 'Workers compensation'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Workers compensation',
            $this->section($this->file($contractor['uuid']), 'COID')['name']);
    }

    public function test_editing_one_section_keeps_the_rest_of_the_profile(): void
    {
        $contractor = $this->contractor();
        $before = count($this->file($contractor['uuid'])['sections']);

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/sections/COID",
                ['name' => 'Workers compensation']);

        // The first write materialises the whole profile. Without that, editing one section would
        // leave a file of one section and silently drop everything else.
        $this->assertSame($before, count($this->file($contractor['uuid'])['sections']));
        $this->assertGreaterThan(0, DB::table('sheq_safety_file_sections')
            ->where('company_id', $this->companyId)->count());
    }

    public function test_a_section_can_stop_being_demanded(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/sections/COID",
                ['is_required' => false])->assertRedirect();

        $this->assertFalse($this->section($this->file($contractor['uuid']), 'COID')['is_required']);
    }

    public function test_adopting_a_profile_does_not_destroy_a_tenants_own_choices(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/sections/COID",
                ['name' => 'Workers compensation']);

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/profile",
                ['profile' => 'MINING']);

        // A profile change says what is demanded, not what things are called. Somebody choosing
        // Mining after renaming three sections should not lose the renaming.
        $file = $this->file($contractor['uuid']);
        $this->assertSame('Workers compensation', $this->section($file, 'COID')['name']);
        $this->assertTrue($this->section($file, 'COID')['is_required']);
    }

    public function test_an_unknown_profile_is_refused(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/profile",
                ['profile' => 'ATLANTIS'])
            ->assertSessionHasErrors('profile');
    }

    // ── The bundle ────────────────────────────────────────────────────────────────────────────

    /** A real PDF: the file store detects the type from the content, not the extension. */
    private function pdfBytes(string $marker = 'A'): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 72 72]>>endobj\n"
            ."% {$marker}\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private function fileInto(string $contractorUuid, string $type, string $name,
        ?string $expires = null): void
    {
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractorUuid}/documents", array_filter([
                'document_type' => $type,
                'expires_on' => $expires,
                'files' => [\Illuminate\Http\UploadedFile::fake()
                    ->createWithContent($name, $this->pdfBytes($name))],
            ]))->assertRedirect()->assertSessionHasNoErrors();
    }

    /** @return array{entries:list<string>, zip:\ZipArchive} */
    private function bundle(string $contractorUuid): array
    {
        $response = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractorUuid}/safety-file/export")
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'sheq-test-zip-');
        file_put_contents($path, $response->streamedContent());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The export is not a readable archive.');

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }

        return ['entries' => $entries, 'zip' => $zip, 'path' => $path];
    }

    public function test_the_whole_file_comes_down_as_one_archive(): void
    {
        $contractor = $this->contractor();

        $this->fileInto($contractor['uuid'], 'COIDA_LETTER', 'coida.pdf',
            Carbon::today()->addYear()->toDateString());
        $this->fileInto($contractor['uuid'], 'HS_POLICY', 'policy.pdf');

        $bundle = $this->bundle($contractor['uuid']);

        /*
         * Downloading twenty-five certificates one at a time is not an alternative to the folder;
         * it is the same problem with extra steps.
         */
        $this->assertContains('00 Index.pdf', $bundle['entries']);
        $this->assertCount(3, $bundle['entries']);

        // Foldered by section, numbered in the order the file is assembled, so the archive sorts
        // the way the dividers do rather than alphabetically.
        $this->assertTrue(collect($bundle['entries'])
            ->contains(fn (string $e) => str_ends_with($e, 'coida.pdf') && str_contains($e, '/')));

        $bundle['zip']->close();
        @unlink($bundle['path']);
    }

    public function test_the_documents_arrive_byte_for_byte(): void
    {
        $contractor = $this->contractor();
        $this->fileInto($contractor['uuid'], 'COIDA_LETTER', 'coida.pdf');

        $bundle = $this->bundle($contractor['uuid']);
        $entry = collect($bundle['entries'])->first(fn (string $e) => str_ends_with($e, 'coida.pdf'));

        // Evidence is handed over exactly as it was supplied. A bundle that re-encodes or
        // rasterises its contents is not evidence of what the contractor gave you.
        $this->assertSame($this->pdfBytes('coida.pdf'), $bundle['zip']->getFromName($entry));

        $bundle['zip']->close();
        @unlink($bundle['path']);
    }

    public function test_the_index_is_a_readable_pdf(): void
    {
        $contractor = $this->contractor();
        $bundle = $this->bundle($contractor['uuid']);

        $index = $bundle['zip']->getFromName('00 Index.pdf');

        $this->assertNotFalse($index);
        $this->assertStringStartsWith('%PDF', $index);

        $bundle['zip']->close();
        @unlink($bundle['path']);
    }

    public function test_an_empty_file_still_bundles_and_says_it_is_empty(): void
    {
        $contractor = $this->contractor();
        $bundle = $this->bundle($contractor['uuid']);

        // A contractor who has supplied nothing is exactly the case somebody needs to send to a
        // principal contractor, so refusing to export would be the wrong answer.
        $this->assertSame(['00 Index.pdf'], $bundle['entries']);

        $bundle['zip']->close();
        @unlink($bundle['path']);
    }

    public function test_a_document_recorded_with_no_certificate_is_named_rather_than_absent(): void
    {
        $contractor = $this->contractor();

        // Recorded with an expiry and no file attached, which is common: somebody logs the date
        // from an email and means to scan the letter later.
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ])->assertRedirect();

        $export = app(\App\Modules\Operations\Sheq\Services\SafetyFileExporter::class)
            ->export($this->companyId, $contractor['uuid'], $this->user->id);

        /*
         * In a zip this document simply would not appear, and its absence would read as "never
         * supplied" rather than "logged but the certificate was never uploaded" — which are
         * different conversations with the contractor.
         */
        $this->assertSame(0, $export['documents']);
        $this->assertNotEmpty($export['skipped']);
        $this->assertStringContainsString('no file attached', $export['skipped'][0]);

        @unlink($export['path']);
    }

    public function test_two_documents_with_the_same_filename_do_not_overwrite_each_other(): void
    {
        $contractor = $this->contractor();

        $this->fileInto($contractor['uuid'], 'COIDA_LETTER', 'scan0001.pdf');
        $this->fileInto($contractor['uuid'], 'COIDA_LETTER', 'scan0001.pdf');

        $bundle = $this->bundle($contractor['uuid']);

        // Both scans are in the same section folder under the same name. Losing one silently
        // would mean the bundle holds less evidence than the file does.
        $scans = collect($bundle['entries'])->filter(fn (string $e) => str_contains($e, 'scan0001'));
        $this->assertCount(2, $scans);

        $bundle['zip']->close();
        @unlink($bundle['path']);
    }

    public function test_a_contractor_name_with_a_slash_does_not_create_folders(): void
    {
        $contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Acme (Pty) Ltd / Trading as Acme', 'trade' => 'SCAFFOLDING',
        ], $this->user->id);

        $export = app(\App\Modules\Operations\Sheq\Services\SafetyFileExporter::class)
            ->export($this->companyId, $contractor['uuid'], $this->user->id);

        // A path separator in a filename silently creates a directory, which is how a download
        // ends up named after half a company.
        $this->assertStringNotContainsString('/', $export['name']);
        $this->assertStringEndsWith('.zip', $export['name']);

        @unlink($export['path']);
    }

    public function test_one_unreadable_document_does_not_cost_the_others(): void
    {
        $contractor = $this->contractor();

        $this->fileInto($contractor['uuid'], 'COIDA_LETTER', 'good.pdf');
        $this->fileInto($contractor['uuid'], 'HS_POLICY', 'lost.pdf');

        // The stored bytes go missing — storage consolidation, a restore from an incomplete
        // backup, a disk that was cleared. The row still says STORED.
        $lost = DB::table('sheq_files')
            ->where('company_id', $this->companyId)
            ->where('original_name', 'lost.pdf')->first(['id', 'storage_path']);

        DB::table('sheq_files')->where('id', $lost->id)
            ->update(['storage_path' => 'documents/sheq/this-path-does-not-exist.pdf', 'data' => null]);

        $export = app(\App\Modules\Operations\Sheq\Services\SafetyFileExporter::class)
            ->export($this->companyId, $contractor['uuid'], $this->user->id);

        // One readable document delivered, the unreadable one named. Failing the whole export
        // would mean a single corrupted file makes the contractor's entire safety file
        // un-handoverable.
        $this->assertSame(1, $export['documents']);
        $this->assertCount(1, $export['skipped']);
        $this->assertStringContainsString('could not be read', $export['skipped'][0]);

        @unlink($export['path']);
    }

    public function test_exporting_is_recorded(): void
    {
        $contractor = $this->contractor();
        $this->fileInto($contractor['uuid'], 'COIDA_LETTER', 'coida.pdf');

        $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}/safety-file/export")->assertOk();

        $entry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'safety_file.exported')
            ->orderByDesc('id')->first();

        /*
         * A bundle is every compliance document a contractor has supplied, in one archive that
         * leaves the system. That is a different act from opening one certificate, and "who
         * exported this, and what was in it" is a question somebody will eventually ask.
         */
        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, (int) $entry->actor_id);
        $this->assertSame(1, json_decode((string) $entry->after, true)['documents']);
    }

    public function test_another_tenants_file_cannot_be_exported(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sf-exp-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreign = (string) Str::uuid();

        DB::table('sheq_contractors')->insert([
            'uuid' => $foreign, 'company_id' => $other, 'name' => 'Theirs',
            'access_status' => 'BLOCKED', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The bulk act is the one that matters most for tenancy: an unscoped export would hand
        // over another company's entire contractor file in one request.
        $this->actingAs($this->user)
            ->get("/sheq/contractors/{$foreign}/safety-file/export")->assertNotFound();
    }

    // ── Permissions, audit and tenancy ────────────────────────────────────────────────────────

    public function test_filing_is_a_different_entitlement_from_deciding_what_is_demanded(): void
    {
        $contractor = $this->contractor();
        $clerk = $this->userWith(['sheq.contractor.manage']);

        // A clerk uploading certificates must not be able to stop a section being demanded.
        $this->actingAs($clerk)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/sections/COID",
                ['is_required' => false])
            ->assertForbidden();

        $this->actingAs($clerk)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_choosing_a_profile_is_audited(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/profile",
                ['profile' => 'CONSTRUCTION']);

        $entry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'safety_file.profile_applied')
            ->orderByDesc('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, (int) $entry->actor_id);
        $this->assertSame('CONSTRUCTION', json_decode((string) $entry->after, true)['profile']);
    }

    public function test_changing_a_section_is_audited(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/safety-file/sections/COID",
                ['is_required' => false]);

        $this->assertNotNull(DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'safety_file.section_configured')->first());
    }

    public function test_another_tenants_sections_are_not_read(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sf-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Another tenant configures a file of one section. Ours must still read from the shipped
        // profile rather than from theirs.
        app(SafetyFileService::class)->applyProfile($other, 'PROFESSIONAL_SERVICES');

        DB::table('sheq_safety_file_sections')->where('company_id', $other)
            ->where('code', '<>', 'COID')->delete();

        $file = $this->file($this->contractor()['uuid']);

        $this->assertCount(count(SafetyFileSections::catalogue()), $file['sections']);
        $this->assertSame('GENERAL', $file['profile']);
    }

    public function test_another_tenants_contractor_has_no_file_here(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sf-x-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreign = (string) Str::uuid();

        DB::table('sheq_contractors')->insert([
            'uuid' => $foreign, 'company_id' => $other, 'name' => 'Theirs',
            'access_status' => 'BLOCKED', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->get("/sheq/contractors/{$foreign}/safety-file")
            ->assertNotFound();
    }

    public function test_the_file_opens_for_somebody_who_cannot_change_it(): void
    {
        $contractor = $this->contractor();

        // A gate reads this file. Making it readable only by whoever maintains it would mean the
        // paper copy stays in the guardhouse, which is the thing being replaced.
        $props = $this->actingAs($this->userWith([]))
            ->get("/sheq/contractors/{$contractor['uuid']}/safety-file")
            ->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['can']['manage']);
        $this->assertFalse($props['can']['configure']);
        $this->assertNotEmpty($props['file']['sections']);
    }

    // ── The bundle's limits ───────────────────────────────────────────────────────────────────

    /**
     * Clone one filed document until the contractor has `$total` of them.
     *
     * Posting three hundred uploads through the route would test the upload path three hundred
     * times over; the rows are copied instead, bytes and all, because what is under test here is
     * what the exporter does when there are more documents than it will carry.
     */
    private function multiply(int $contractorId, int $total): void
    {
        $document = DB::table('sheq_contractor_documents')
            ->where('contractor_id', $contractorId)->whereNull('deleted_at')->first();
        $stored = DB::table('sheq_files')
            ->where('subject_type', 'CONTRACTOR_DOCUMENT')->where('subject_id', $document->id)
            ->first();

        for ($i = 0; $i < $total - 1; $i++) {
            $row = (array) $document;
            unset($row['id']);
            $row['uuid'] = (string) Str::uuid();
            $row['title'] = 'Copy '.($i + 1);
            $newId = DB::table('sheq_contractor_documents')->insertGetId($row);

            $fileRow = (array) $stored;
            unset($fileRow['id']);
            $fileRow['uuid'] = (string) Str::uuid();
            $fileRow['subject_id'] = $newId;
            $fileRow['original_name'] = sprintf('scan-%03d.pdf', $i + 1);
            // `sheq_files` is unique on (company_id, client_id) for the offline uploader's sake,
            // so a copy cannot carry the original's client id.
            $fileRow['client_id'] = null;
            DB::table('sheq_files')->insert($fileRow);
        }
    }

    public function test_a_bundle_too_big_to_carry_says_what_it_left_out(): void
    {
        $contractor = $this->contractor();

        $this->fileInto($contractor['uuid'], 'COIDA_LETTER', 'coida.pdf',
            Carbon::today()->addYear()->toDateString());
        $this->multiply($contractor['id'], 305);

        $bundle = app(\App\Modules\Operations\Sheq\Services\SafetyFileExporter::class)
            ->export($this->companyId, $contractor['uuid'], $this->user->id);

        /*
         * The cap exists so one contractor cannot ask for a two-gigabyte download. What matters is
         * that it stops rather than truncates: a bundle quietly missing five certificates is worse
         * than no bundle at all, because the person who receives it believes it is complete.
         */
        $this->assertSame(300, $bundle['documents']);
        $this->assertCount(5, $bundle['skipped']);

        foreach ($bundle['skipped'] as $note) {
            $this->assertStringContainsString('reached its limit', $note);
        }

        // And the names of the five are in the index that travels inside the archive, not only in
        // a return value no human reads.
        $index = view('sheq.safety-file-index', [
            'contractor' => (object) ['name' => $contractor['name'], 'trade' => null,
                'registration_number' => null, 'access_status' => 'COMPLIANT'],
            'company' => 'Test', 'file' => app(SafetyFileService::class)
                ->assemble($this->companyId, $contractor['uuid']),
            'attachments' => [], 'documents' => $bundle['documents'],
            'skipped' => $bundle['skipped'], 'generatedAt' => Carbon::now(),
            'profileName' => 'General',
        ])->render();

        $this->assertStringContainsString('reached its limit', $index);

        @unlink($bundle['path']);
    }

    public function test_the_export_is_recorded_with_how_much_was_left_out(): void
    {
        $contractor = $this->contractor();

        $this->fileInto($contractor['uuid'], 'COIDA_LETTER', 'coida.pdf',
            Carbon::today()->addYear()->toDateString());
        $this->multiply($contractor['id'], 305);

        $bundle = app(\App\Modules\Operations\Sheq\Services\SafetyFileExporter::class)
            ->export($this->companyId, $contractor['uuid'], $this->user->id);

        // Taking a copy of a whole safety file is a different act from opening one certificate,
        // and an incomplete copy leaving the system is exactly what an audit would ask about.
        $entry = DB::table('sheq_audit_log')->where('company_id', $this->companyId)
            ->where('action', 'safety_file.exported')->orderByDesc('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame(5, json_decode($entry->after, true)['left_out']);
        $this->assertStringContainsString('5', (string) $entry->reason);

        @unlink($bundle['path']);
    }
}
