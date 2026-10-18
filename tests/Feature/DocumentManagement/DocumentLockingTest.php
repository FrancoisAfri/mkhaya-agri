<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Services\LockService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Two people cannot work on the same document at once.
 *
 * Versioning already meant no edit could destroy an earlier one, and it was never enough: the loss
 * happens before either person uploads. Two people take the contract on Monday, both spend the
 * afternoon on it, and the second upload buries the first person's work in a version history
 * nobody reads.
 *
 * A lock is a claim, not a permission, and everything below follows from that. It expires on its
 * own, it can be taken back, and it never lets somebody do something they could not already do.
 */
class DocumentLockingTest extends TestCase
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

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();
    }

    private function user(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'lock+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        $user = $user->fresh();

        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => ModuleHubs::SUPERUSER],
        );

        return $user;
    }

    private function editor(): User
    {
        return $this->user('document.view', 'document.update', 'document.replace');
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A contract ' . Str::random(4),
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
        ], $attributes));
    }

    private function pdf(string $name = 'revision.pdf', string $body = 'v2'): UploadedFile
    {
        // A real file rather than a sized blank: the type guard reads the bytes, so an empty fake
        // is refused before anything under test is reached — and a test that passes because the
        // upload was rejected for the wrong reason proves nothing.
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n{$body}\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function locks(): LockService
    {
        return app(LockService::class);
    }

    // ── Holding it ───────────────────────────────────────────────────────────

    public function test_a_document_starts_out_available(): void
    {
        $this->assertNull($this->locks()->holder($this->document()));
    }

    public function test_checking_out_stops_somebody_else_editing(): void
    {
        $mine = $this->editor();
        $theirs = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->assertTrue($this->locks()->checkOut($document, $mine)['ok']);

        $this->assertFalse($this->locks()->blocks($document, $mine), 'It must not block the person holding it.');
        $this->assertTrue($this->locks()->blocks($document, $theirs));
    }

    public function test_checking_out_twice_is_not_an_error_for_the_holder(): void
    {
        // Pressing it again, or opening the page in a second tab. Refusing would be confusing and
        // would say somebody else has it, which is untrue.
        $mine = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        $this->assertTrue($this->locks()->checkOut($document, $mine)['ok']);
    }

    public function test_somebody_else_cannot_check_out_what_is_held(): void
    {
        $mine = $this->editor();
        $theirs = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        $result = $this->locks()->checkOut($document->fresh(), $theirs);

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    // ── Letting go ───────────────────────────────────────────────────────────

    public function test_a_lock_lapses_on_its_own(): void
    {
        /*
         * Somebody checks a document out on Friday and goes on leave. Without this the document is
         * frozen until they come back, and the only remedy is an administrator who knows the lock
         * exists — which nobody does until the person who needed the document complains.
         */
        $mine = $this->editor();
        $theirs = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        $document->forceFill(['lock_expires_at' => now()->subHour()])->save();

        $this->assertNull($this->locks()->holder($document->fresh()), 'A lapsed lock is not a lock.');
        $this->assertFalse($this->locks()->blocks($document->fresh(), $theirs));
    }

    public function test_somebody_else_can_take_a_lapsed_lock(): void
    {
        $mine = $this->editor();
        $theirs = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);
        $document->forceFill(['lock_expires_at' => now()->subHour()])->save();

        $this->actingAs($theirs);
        $this->assertTrue($this->locks()->checkOut($document->fresh(), $theirs)['ok']);
    }

    public function test_checking_in_something_nobody_holds_is_not_a_failure(): void
    {
        // The lock lapsed, or somebody already released it. Either way the document is where the
        // person pressing the button wanted it, and an error would be pedantry.
        $mine = $this->editor();

        $this->actingAs($mine);

        $this->assertTrue($this->locks()->checkIn($this->document(), $mine)['ok']);
    }

    public function test_taking_somebody_elses_lock_needs_saying_so(): void
    {
        $mine = $this->editor();
        $theirs = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        $this->assertFalse($this->locks()->checkIn($document->fresh(), $theirs)['ok']);
        $this->assertTrue($this->locks()->checkIn($document->fresh(), $theirs, force: true)['ok']);
    }

    public function test_taking_a_lock_is_recorded_against_the_person_it_was_taken_from(): void
    {
        // The person who loses it deserves to know who took it. A lock that vanishes silently is
        // worse than no lock, because the work is already underway when it goes.
        $mine = $this->editor();
        $theirs = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        $this->actingAs($theirs);
        $this->locks()->checkIn($document->fresh(), $theirs, force: true);

        $this->assertTrue(
            DocumentEvent::where('document_id', $document->id)
                ->where('action', DocumentEvent::LOCK_BROKEN)
                ->exists(),
        );
    }

    // ── What it actually stops ───────────────────────────────────────────────

    public function test_the_lock_stops_the_upload_and_not_only_the_button(): void
    {
        /*
         * The check lives in the service, where the loss would happen. A lock the upload endpoint
         * honours and the service does not is a lock that every other caller — a job, an import,
         * the next controller somebody writes — walks straight through.
         */
        $mine = $this->editor();
        $theirs = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        $this->actingAs($theirs);
        $result = app(\App\Modules\Operations\DocumentManagement\Services\DocumentService::class)->replace(
            $document->fresh(),
            $this->pdf(),
        );

        $this->assertNull($result['version'], 'A held document must not accept somebody else\'s version.');
        $this->assertSame(
            __('document_management.lock.held_by_another'),
            $result['error'],
            'Refused because of the lock, not because the file was unacceptable.',
        );
    }

    public function test_the_holder_can_still_upload(): void
    {
        $mine = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        $result = app(\App\Modules\Operations\DocumentManagement\Services\DocumentService::class)->replace(
            $document->fresh(),
            $this->pdf(),
        );

        $this->assertNotNull($result['version'], $result['error'] ?? '');
    }

    public function test_uploading_the_new_version_puts_the_document_back(): void
    {
        /*
         * Uploading is what it was checked out for. Leaving the lock on afterwards means a document
         * unavailable for a day for a reason nobody can see, and the person holding it has already
         * moved on to something else.
         */
        $mine = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        app(\App\Modules\Operations\DocumentManagement\Services\DocumentService::class)->replace(
            $document->fresh(),
            $this->pdf(),
        );

        $this->assertNull($this->locks()->holder($document->fresh()), 'The claim is finished with.');
    }

    public function test_the_lock_stops_the_details_being_edited_too(): void
    {
        // The smaller half of the same collision. Letting it through would make the lock advisory.
        $mine = $this->editor();
        $theirs = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        $this->actingAs($theirs)
            ->put(route('document-management.documents.update', $document->uuid), ['title' => 'Renamed'])
            ->assertSessionHas('error');

        $this->assertNotSame('Renamed', $document->fresh()->title);
    }

    public function test_a_lock_does_not_let_somebody_do_what_they_could_not(): void
    {
        /*
         * A lock says who is working on something, not who may. If checking out widened anything,
         * it would be a permission with no approval behind it — and the fastest way into a document
         * would be to claim it first.
         */
        $reader = $this->user('document.view');
        $document = $this->document();

        $this->actingAs($reader);
        $this->locks()->checkOut($document, $reader);

        $this->assertFalse($reader->fresh()->can('update', $document));
    }

    // ── The routes ───────────────────────────────────────────────────────────

    public function test_checking_out_needs_the_right_to_change_it(): void
    {
        // Checking out something you could not edit anyway blocks colleagues to no purpose.
        $reader = $this->user('document.view');
        $document = $this->document();

        $this->actingAs($reader)
            ->post(route('document-management.documents.check-out', $document->uuid))
            ->assertForbidden();
    }

    public function test_breaking_a_lock_through_the_route_needs_more_than_editing(): void
    {
        $mine = $this->editor();
        $theirs = $this->editor();
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        $this->actingAs($theirs)
            ->post(route('document-management.documents.check-in', $document->uuid), ['force' => true])
            ->assertSessionHas('error');

        $this->assertNotNull($this->locks()->holder($document->fresh()), 'The lock has to survive that.');
    }

    public function test_somebody_who_manages_folders_can_take_it_back(): void
    {
        $mine = $this->editor();
        $manager = $this->user('document.view', 'document.update', 'document.folder.manage');
        $document = $this->document();

        $this->actingAs($mine);
        $this->locks()->checkOut($document, $mine);

        $this->actingAs($manager)
            ->post(route('document-management.documents.check-in', $document->uuid), ['force' => true])
            ->assertSessionHas('success');

        $this->assertNull($this->locks()->holder($document->fresh()));
    }
}
