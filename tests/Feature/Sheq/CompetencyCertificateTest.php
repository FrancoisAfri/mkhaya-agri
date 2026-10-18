<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A ticket earned somewhere else (SHEQ-COMP-7).
 *
 * `CompetencyService::recordManual()` had no route, so the only competency a person could hold was
 * one an integrated LMS had issued. A first-aid certificate from St John, a rigging ticket from a
 * training provider, a forklift licence — none of it could be captured. A worker who was genuinely
 * competent read as not competent, and the permit gates that check competency turned them away.
 */
class CompetencyCertificateTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $manager;

    private User $onlooker;

    private int $personId;

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
        $this->manager = $this->userWith(['sheq.competency.manage']);
        $this->onlooker = $this->userWith([]);
        $this->personId = $this->person($this->companyId);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'cert-'.Str::random(8).'@example.test',
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

    private function person(int $companyId): int
    {
        return (int) DB::table('hr_people')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
            'first_name' => 'Thandi', 'surname' => Str::random(6), 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function competency(array $overrides = []): string
    {
        $code = 'C'.Str::random(6);

        DB::table('sheq_competencies')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => $code, 'title' => 'First aid level 1',
            'competency_type' => 'TRAINING', 'validity_months' => 24,
            'blocks_work_when_expired' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $code;
    }

    public function test_a_certificate_earned_elsewhere_becomes_a_competency_record(): void
    {
        $code = $this->competency();

        $this->actingAs($this->manager)->post('/sheq/competency/records', [
            'hr_person_id' => $this->personId,
            'competency_code' => $code,
            'achieved_at' => '2026-01-15',
            'certificate_number' => 'SJ-99812',
            'issuing_body' => 'St John Ambulance',
            'score' => 88,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $record = DB::table('sheq_competency_records as r')
            ->join('sheq_competencies as c', 'c.id', '=', 'r.competency_id')
            ->where('r.company_id', $this->companyId)->where('r.hr_person_id', $this->personId)
            ->first(['r.status', 'r.expires_at', 'r.certificate_number', 'r.issuing_body', 'r.source']);

        $this->assertNotNull($record);
        $this->assertSame('COMPLIANT', $record->status);
        $this->assertSame('SJ-99812', $record->certificate_number);
        $this->assertSame('MANUAL', $record->source);
    }

    public function test_a_blank_expiry_takes_the_competencys_own_validity_period(): void
    {
        $code = $this->competency(['validity_months' => 24]);

        $this->actingAs($this->manager)->post('/sheq/competency/records', [
            'hr_person_id' => $this->personId, 'competency_code' => $code,
            'achieved_at' => '2026-01-15',
        ])->assertSessionHasNoErrors();

        $expires = DB::table('sheq_competency_records')
            ->where('company_id', $this->companyId)->where('hr_person_id', $this->personId)
            ->value('expires_at');

        // Nobody should have to work out the date: a two-year ticket earned in January 2026 lapses
        // in January 2028, and it lapses on the same machinery as an LMS-issued one.
        $this->assertSame('2028-01-15', Carbon::parse($expires)->toDateString());
    }

    public function test_the_certificate_file_is_stored_against_the_record(): void
    {
        $code = $this->competency();

        $this->actingAs($this->manager)->post('/sheq/competency/records', [
            'hr_person_id' => $this->personId, 'competency_code' => $code,
            'evidence' => UploadedFile::fake()->createWithContent('ticket.pdf', $this->pdfBytes()),
        ])->assertSessionHasNoErrors();

        $recordId = (int) DB::table('sheq_competency_records')
            ->where('company_id', $this->companyId)->where('hr_person_id', $this->personId)
            ->value('id');

        $file = DB::table('sheq_files')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'COMPETENCY')->where('subject_id', $recordId)
            ->first(['category', 'upload_status', 'storage_path']);

        // An expiry date nobody can produce the paper for is not evidence of competency, and an
        // inspector asks for the paper.
        $this->assertNotNull($file, 'The certificate must be attached to the record.');
        $this->assertSame('CERTIFICATE', $file->category);
        $this->assertSame('STORED', $file->upload_status);
        $this->assertNotNull($file->storage_path);
    }

    public function test_an_unknown_competency_code_is_told_to_the_person_not_thrown(): void
    {
        // CompetencyService refuses an unknown code with a sentence. Caught at the field, the
        // person is told what to fix instead of being shown a stack trace.
        $this->actingAs($this->manager)->post('/sheq/competency/records', [
            'hr_person_id' => $this->personId, 'competency_code' => 'NOPE-404',
        ])->assertSessionHasErrors('competency_code');
    }

    public function test_a_person_from_another_tenant_cannot_be_given_a_certificate(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'cert-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $stranger = $this->person($otherCompany);
        $code = $this->competency();

        $this->actingAs($this->manager)->post('/sheq/competency/records', [
            'hr_person_id' => $stranger, 'competency_code' => $code,
        ])->assertSessionHasErrors('hr_person_id');

        $this->assertDatabaseMissing('sheq_competency_records', [
            'company_id' => $this->companyId, 'hr_person_id' => $stranger,
        ]);
    }

    public function test_recording_a_certificate_needs_the_competency_entitlement(): void
    {
        $code = $this->competency();

        $this->actingAs($this->onlooker)->post('/sheq/competency/records', [
            'hr_person_id' => $this->personId, 'competency_code' => $code,
        ])->assertForbidden();
    }

    public function test_the_register_screen_offers_the_people_and_codes_the_dialog_needs(): void
    {
        $code = $this->competency();

        $props = $this->actingAs($this->manager)->get('/sheq/competency')
            ->viewData('page')['props'];

        // Without both lists the dialog is two free-text boxes, and a mistyped code is a refusal
        // rather than a record.
        $this->assertContains($this->personId, array_column($props['people'], 'id'));
        $this->assertContains($code, array_column(
            array_map(fn ($c) => (array) $c, $props['competencies']), 'code',
        ));
    }

    /** A real PDF: the file store detects the type from the content, not the extension. */
    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 72 72]>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
