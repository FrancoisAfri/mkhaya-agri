<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\DocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Where everybody else reads the procedures, and who gets to see which.
 *
 * The documents register is a practitioner's screen: it needs `sheq.document.manage`, shows drafts
 * and offers publishing. There was nowhere for the people a procedure is written for — a
 * controlled document nobody can find controls nothing — and `applies_to` had been stored on every
 * document since the table was written and read by nothing, so every published procedure was
 * visible to anyone who could reach the register.
 */
class DocumentLibraryTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $author;

    private User $reader;

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
        $this->author = $this->userWith(['sheq.document.manage']);
        $this->reader = $this->userWith([]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'lib-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('hr_people')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'user_id' => $userId,
            'first_name' => 'Reader', 'surname' => Str::random(5), 'status' => 1,
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

    /** A published document with the audience recorded on it. */
    private function publish(array $audience, bool $requiresAck = false): string
    {
        $service = app(DocumentService::class);

        $document = $service->create($this->companyId, [
            'doc_number' => 'DOC-'.Str::random(6),
            'title' => 'Working at height',
            'doc_type' => 'PROCEDURE',
            'requires_acknowledgement' => $requiresAck,
            'applies_to' => $audience,
        ], $this->author->id);

        $service->draftVersion($this->companyId, $document['uuid'], [
            'summary_of_changes' => 'First issue',
        ], $this->author->id);

        $service->publish($this->companyId, $document['uuid'], 1, $this->author->id);

        return $document['uuid'];
    }

    private function libraryFor(User $user): array
    {
        return $this->actingAs($user)->get('/sheq/library')
            ->viewData('page')['props']['documents'];
    }

    public function test_a_reader_without_document_permissions_can_reach_the_library(): void
    {
        $this->publish(['mode' => 'EVERYONE']);

        // The whole point: the register needs `sheq.document.manage`; this must not.
        $this->actingAs($this->reader)->get('/sheq/library')->assertOk();
        $this->assertCount(1, $this->libraryFor($this->reader));
    }

    public function test_only_the_published_version_is_offered(): void
    {
        $service = app(DocumentService::class);

        $document = $service->create($this->companyId, [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'Draft only', 'doc_type' => 'PROCEDURE',
        ], $this->author->id);

        $service->draftVersion($this->companyId, $document['uuid'], [], $this->author->id);

        // A draft is not a procedure; nobody can follow one they cannot see, and nobody should be
        // shown one that is not in force.
        $this->assertSame([], $this->libraryFor($this->reader));
    }

    public function test_a_document_restricted_to_roles_is_hidden_from_everybody_else(): void
    {
        $this->publish(['mode' => 'ROLES', 'roles' => ['SAFETY_OFFICER']]);

        $this->assertSame([], $this->libraryFor($this->reader),
            'applies_to was stored and read by nothing, so every procedure was visible to all.');
    }

    public function test_a_document_with_no_audience_recorded_applies_to_everybody(): void
    {
        // Every document written before this has a null `applies_to`. For a safety procedure the
        // safe reading of "nobody said" is "everyone", not "no one".
        $this->publish([]);

        $this->assertCount(1, $this->libraryFor($this->reader));
    }

    public function test_a_reader_can_acknowledge_and_it_is_recorded_against_the_person(): void
    {
        $uuid = $this->publish(['mode' => 'EVERYONE'], requiresAck: true);

        $this->assertFalse($this->libraryFor($this->reader)[0]['acknowledged']);

        $this->actingAs($this->reader)
            ->post("/sheq/library/{$uuid}/acknowledge")
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($this->libraryFor($this->reader)[0]['acknowledged']);

        // Against the employee, not the login — "which user clicked" is not the evidence an
        // inspector asks for.
        $hrPersonId = DB::table('hr_people')->where('user_id', $this->reader->id)->value('id');

        $this->assertTrue(DB::table('sheq_document_acknowledgements')
            ->where('company_id', $this->companyId)->where('hr_person_id', $hrPersonId)->exists());
    }

    public function test_a_document_that_does_not_apply_cannot_be_acknowledged(): void
    {
        $uuid = $this->publish(['mode' => 'ROLES', 'roles' => ['SAFETY_OFFICER']], requiresAck: true);

        // Otherwise a uuid from elsewhere is an acknowledgement of a document never shown.
        $this->actingAs($this->reader)
            ->post("/sheq/library/{$uuid}/acknowledge")
            ->assertForbidden();
    }

    public function test_a_zip_of_procedures_becomes_one_controlled_document_each(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docs-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach (['height.pdf', 'confined-space.pdf', 'hot-work.pdf'] as $name) {
            $zip->addFromString($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
        }

        $zip->close();

        $before = DB::table('sheq_documents')->where('company_id', $this->companyId)->count();

        $this->actingAs($this->author)->post('/sheq/documents', [
            'doc_number' => 'SOP-'.Str::random(5),
            'title' => 'Procedures',
            'doc_type' => 'PROCEDURE',
            'audience' => 'EVERYONE',
            'files' => [new UploadedFile($path, 'procedures.zip', 'application/zip', null, true)],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // One procedure carrying four unrelated PDFs is not a controlled document, it is an
        // envelope — so each file becomes a document in its own right.
        $this->assertSame(3,
            DB::table('sheq_documents')->where('company_id', $this->companyId)->count() - $before);
    }
}
