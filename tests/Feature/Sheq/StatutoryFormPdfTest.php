<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Services\StatutoryFormService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The printed statutory form (SHEQ-REG-6).
 *
 * This is the artefact that physically leaves the building. A WCL-1 goes to the Compensation
 * Commissioner and a section 24 notification goes to the Department of Employment and Labour, and
 * both are read by someone who will reject the submission if it is ambiguous about what was
 * supplied and what was not.
 *
 * So these tests are about the properties a reader depends on rather than about layout: that a
 * draft cannot be mistaken for a submission, that a blank field says it is blank rather than
 * appearing as an empty box somebody might read as "none", and that the document says which version
 * of which regulatory pack produced it.
 */
class StatutoryFormPdfTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $incidentId;

    private int $packVersionId;

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

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'pdf-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = User::withoutGlobalScopes()->findOrFail($userId);
        $this->grantModuleAccess($userId);
        $this->ensureModuleActive();

        $manifest = (new PackLoader)->load(base_path('resources/regulatory-packs/za-ohsa'));

        $packId = DB::table('sheq_regulatory_packs')
            ->where('pack_id', $manifest->packId)->value('id')
            ?? DB::table('sheq_regulatory_packs')->insertGetId([
                'pack_id' => $manifest->packId, 'name' => $manifest->label(),
                'jurisdiction' => $manifest->jurisdiction(), 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        $this->packVersionId = DB::table('sheq_regulatory_pack_versions')
            ->where('pack_id', $packId)->where('version', $manifest->version)->value('id')
            ?? DB::table('sheq_regulatory_pack_versions')->insertGetId([
                'pack_id' => $packId, 'version' => $manifest->version,
                'effective_from' => '2020-01-01', 'manifest' => json_encode($manifest->manifest),
                'checksum' => $manifest->checksum(), 'signature_verified' => false,
                'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

        $this->incidentId = DB::table('sheq_incidents')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(6),
            'occurred_at' => now()->subDays(2), 'reported_at' => now()->subDays(2),
            'incident_type' => 'INJURY', 'description' => 'Caught between conveyor and guard.',
            'actual_severity' => 4, 'potential_severity' => 5, 'status' => 'UNDER_INVESTIGATION',
            // The string code ('za-ohsa'), not the packs table id — that is what the
            // service resolves the manifest by.
            'regulatory_pack_id' => $manifest->packId,
            'regulatory_pack_version' => $manifest->version,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Give a user access to the SHEQ module at the level the hub gate expects.
     *
     * `module.tier` enforces hub role separation on the URL as well as in the navigation, so a user
     * with no `security_module_access` row is refused before the controller runs — which is correct
     * behaviour and would otherwise make this whole file a study of 403s.
     */
    private function grantModuleAccess(int $userId, int $level = 4): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => $level,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The route group sits behind `module.active`, which reads a five-minute cache. */
    private function ensureModuleActive(): void
    {
        $module = DB::table('security_modules')->where('code_name', 'sheq')->first(['id', 'active']);

        if ($module === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        if (! $module->active) {
            DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);
        }

        Cache::forget('module_active_sheq');
    }

    private function generate(string $formCode = 'WCL1_OR_WCL2'): array
    {
        return app(StatutoryFormService::class)
            ->generate($this->companyId, $this->incidentId, $formCode, $this->user->id);
    }

    public function test_a_form_renders_to_a_real_pdf(): void
    {
        $form = $this->generate();

        $response = $this->actingAs($this->user)->get("/sheq/forms/{$form['uuid']}/pdf");

        $response->assertOk();

        $body = $response->getContent();

        // The magic number, not just a 200. A view that throws mid-render can still come back as a
        // successful response containing an HTML error page with a PDF filename on it.
        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertGreaterThan(1000, strlen($body), 'A one-line PDF is a failed render.');
    }

    public function test_an_unfinalised_form_is_watermarked_as_a_draft(): void
    {
        $form = $this->generate();

        $this->assertNotSame('FINAL', $form['status']);

        $html = view('sheq.statutory-form', [
            'form' => $form,
            'company' => 'Test Employer',
        ])->render();

        // A draft that reaches the Commissioner looking like a submission is the worst outcome
        // this document has — it is acted on as though the employer had certified it.
        $this->assertStringContainsString('DRAFT', $html);
    }

    public function test_a_finalised_form_does_not_print_stamped_draft(): void
    {
        /*
         * The other half of the draft banner, and the half that was broken.
         *
         * `finalise()` writes status 'FINAL'; the template compared against 'FINALISED', so
         * `$isDraft` was true for every form ever rendered — including completed ones. A
         * finalised statutory submission printed "must not be submitted in this state", which is
         * the one thing this document exists not to say. Everything else was in place: the
         * route, the controller, the field binding and the template. The artefact simply could
         * not be used for its purpose.
         */
        $form = $this->generate();

        DB::table('sheq_statutory_form_instances')
            ->where('uuid', $form['uuid'])
            ->update([
                'status' => 'FINAL',
                'finalised_at' => now(),
                'content_sha256' => hash('sha256', 'test-content'),
                'gap_count' => 0,
            ]);

        $finalised = app(\App\Modules\Operations\Sheq\Services\StatutoryFormService::class)
            ->view($this->companyId, $form['uuid']);

        $this->assertSame('FINAL', $finalised['status']);

        $html = view('sheq.statutory-form', [
            'form' => $finalised,
            'company' => 'Test Employer',
        ])->render();

        $this->assertStringNotContainsString('must not be submitted', $html,
            'A finalised form prints as a draft, so the completed statutory submission tells the '
            .'Commissioner not to accept it.');
        $this->assertStringNotContainsString('DRAFT —', $html);
    }

    public function test_a_finalised_form_carries_the_fingerprint_it_was_finalised_with(): void
    {
        /*
         * `finalise()` takes a SHA-256 of exactly what was submitted so a later dispute has an
         * answer. That is worth nothing if the paper copy posted to the regulator cannot be
         * matched back to it, and the value was computed, stored, and never read by anything.
         */
        $form = $this->generate();
        $hash = hash('sha256', 'the-exact-content-submitted');

        DB::table('sheq_statutory_form_instances')
            ->where('uuid', $form['uuid'])
            ->update(['status' => 'FINAL', 'finalised_at' => now(),
                'content_sha256' => $hash, 'gap_count' => 0]);

        $finalised = app(\App\Modules\Operations\Sheq\Services\StatutoryFormService::class)
            ->view($this->companyId, $form['uuid']);

        $this->assertSame($hash, $finalised['content_sha256'],
            'The service does not return the fingerprint, so nothing can print it.');

        $html = view('sheq.statutory-form', [
            'form' => $finalised, 'company' => 'Test Employer',
        ])->render();

        $this->assertStringContainsString($hash, $html,
            'The document does not carry its own fingerprint, so a paper copy cannot be '
            .'reconciled with the record it came from.');
    }

    public function test_a_draft_does_not_claim_a_fingerprint(): void
    {
        // A fingerprint on an unfinalised form would assert that its contents are settled.
        $form = $this->generate();

        $html = view('sheq.statutory-form', [
            'form' => $form, 'company' => 'Test Employer',
        ])->render();

        $this->assertStringNotContainsString('Content fingerprint', $html);
    }

    public function test_a_blank_field_says_it_is_blank_rather_than_looking_answered(): void
    {
        $form = $this->generate();

        $html = view('sheq.statutory-form', ['form' => $form, 'company' => 'Test Employer'])->render();

        if ($form['gap_count'] === 0) {
            $this->markTestSkipped('This fixture produced no gaps to render.');
        }

        // An empty box reads as "nothing to report" to a claims assessor. It has to read as
        // "we did not supply this", which is a different statement entirely.
        $this->assertStringContainsString('NOT SUPPLIED', $html);
        $this->assertStringContainsString('not ready for submission', $html);
    }

    public function test_the_document_states_which_pack_version_produced_it(): void
    {
        $form = $this->generate();

        $html = view('sheq.statutory-form', ['form' => $form, 'company' => 'Test Employer'])->render();

        // Classification rules change between pack versions. Months later, the question "why was
        // this reported as a section 24?" is only answerable if the document says what it was
        // assessed against.
        $this->assertStringContainsString($form['pack'], $html);
    }

    public function test_the_form_is_scoped_to_the_tenant(): void
    {
        $form = $this->generate();

        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'slug' => 'other-'.Str::random(8),
            'name' => 'Other Employer', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Asserted against the service rather than over HTTP. A second company created in a test
        // has no plan, so an HTTP request from its user is turned away by the entitlement gate
        // before tenancy is ever consulted — the test would pass while proving nothing about the
        // boundary it is named for.
        //
        // A statutory form carries the injured person's name and injury. Reaching it from another
        // tenant would be both a POPIA breach and disclosure of another employer's incident.
        $this->expectException(\RuntimeException::class);

        app(StatutoryFormService::class)->view($otherCompany, $form['uuid']);
    }

    public function test_the_filename_identifies_the_form_and_the_record(): void
    {
        $form = $this->generate();

        $response = $this->actingAs($this->user)->get("/sheq/forms/{$form['uuid']}/pdf");

        // These are filed by hand at the other end. "document.pdf" arriving in an inbox alongside
        // forty others is a form that gets lost.
        $this->assertStringContainsString($form['form_code'],
            $response->headers->get('Content-Disposition'));
    }
}
