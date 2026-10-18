<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentRequest;
use App\Modules\Operations\DocumentManagement\Notifications\DocumentRequestNotification;
use App\Modules\Operations\DocumentManagement\Services\DocumentRequestService;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Asking somebody for a document.
 *
 * The opposite direction from every other request in this module. Those are somebody asking to be
 * let in to something that exists; this is somebody asking for something that does not exist yet.
 *
 * The collection happens over email today and fails the same way every time: the request goes out,
 * the file comes back as an attachment, somebody files it by hand, and nobody can say which of the
 * forty people asked have replied. The list of who still owes what is the thing being built. The
 * upload is the easy part.
 */
class DocumentRequestsTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'req+' . Str::random(8) . '@example.test',
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

    private function asker(): User
    {
        return $this->user('document.view', 'document.upload');
    }

    private function pdf(string $name = 'id-copy.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\nid\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function service(): DocumentRequestService
    {
        return app(DocumentRequestService::class);
    }

    // ── Asking ───────────────────────────────────────────────────────────────

    public function test_asking_forty_people_creates_forty_requests(): void
    {
        /*
         * One row per person, deliberately. The queue this exists to produce is "who still owes
         * what", and a single row covering several people cannot be half-answered — it would be
         * useless the moment the first person replied.
         */
        $asker = $this->asker();
        $people = [$this->user()->id, $this->user()->id, $this->user()->id];

        $this->actingAs($asker);
        $created = $this->service()->ask('Certified ID copy', $people);

        $this->assertCount(3, $created);
        $this->assertSame(3, DocumentRequest::where('title', 'Certified ID copy')->count());
    }

    public function test_asking_the_same_person_for_the_same_thing_twice_is_one_request(): void
    {
        /*
         * Otherwise a chase-up creates a second row, the queue shows them owing two documents, and
         * one of the two can never be answered.
         */
        $asker = $this->asker();
        $person = $this->user();

        $this->actingAs($asker);
        $this->service()->ask('Tax clearance', [$person->id]);
        $this->service()->ask('Tax clearance', [$person->id]);

        $this->assertSame(1, DocumentRequest::where('requested_from', $person->id)->count());
    }

    public function test_the_person_asked_is_told(): void
    {
        // A request nobody is told about is a row in a table.
        Notification::fake();

        $asker = $this->asker();
        $person = $this->user();

        $this->actingAs($asker);
        $this->service()->ask('Proof of address', [$person->id]);

        Notification::assertSentTo($person, DocumentRequestNotification::class);
    }

    public function test_asking_needs_the_right_to_file_a_document(): void
    {
        // Asking for a document is asking for one to be filed.
        $this->actingAs($this->user('document.view'))
            ->post(route('document-management.requests.store'), [
                'title' => 'Something',
                'people' => [$this->user()->id],
            ])
            ->assertForbidden();
    }

    // ── Answering ────────────────────────────────────────────────────────────

    public function test_answering_files_the_document_where_the_request_said(): void
    {
        /*
         * The point of carrying the filing on the request: the person answering should not have to
         * learn the structure. They press upload and it lands where it belongs, named for what was
         * asked for rather than for whatever the file was called on somebody's phone.
         */
        $asker = $this->asker();
        $person = $this->user('document.view', 'document.upload');
        $folder = app(FolderService::class)->create(['name' => 'Employee files ' . Str::random(4)]);

        $this->actingAs($asker);
        [$request] = $this->service()->ask('Certified ID copy', [$person->id], ['folder_id' => $folder->id]);

        $this->actingAs($person);
        $result = $this->service()->fulfil($request->fresh(), $this->pdf('IMG_4471.pdf'));

        $this->assertNotNull($result['document'], $result['error'] ?? '');
        $this->assertSame($folder->id, (int) $result['document']->folder_id);
        $this->assertSame('Certified ID copy', $result['document']->title);
    }

    public function test_answering_closes_the_request_and_links_the_document(): void
    {
        $asker = $this->asker();
        $person = $this->user('document.view', 'document.upload');

        $this->actingAs($asker);
        [$request] = $this->service()->ask('Signed lease', [$person->id]);

        $this->actingAs($person);
        $result = $this->service()->fulfil($request->fresh(), $this->pdf());

        $request = $request->fresh();

        $this->assertSame(DocumentRequest::FULFILLED, $request->status);
        $this->assertSame($result['document']->id, (int) $request->document_id);
        $this->assertNotNull($request->fulfilled_at);
    }

    public function test_an_answered_request_cannot_be_answered_again(): void
    {
        $asker = $this->asker();
        $person = $this->user('document.view', 'document.upload');

        $this->actingAs($asker);
        [$request] = $this->service()->ask('Signed lease', [$person->id]);

        $this->actingAs($person);
        $this->service()->fulfil($request->fresh(), $this->pdf());
        $second = $this->service()->fulfil($request->fresh(), $this->pdf());

        $this->assertNull($second['document']);
        $this->assertNotNull($second['error']);
    }

    public function test_only_the_person_asked_can_answer(): void
    {
        /*
         * Somebody else uploading on their behalf would close a request the person still believes
         * is open, and the queue would be quietly wrong.
         */
        $asker = $this->asker();
        $person = $this->user('document.view', 'document.upload');
        $bystander = $this->user('document.view', 'document.upload');

        $this->actingAs($asker);
        [$request] = $this->service()->ask('Signed lease', [$person->id]);

        $this->actingAs($bystander)
            ->post(route('document-management.requests.fulfil', $request->uuid), [
                'file' => $this->pdf(),
            ])
            ->assertForbidden();

        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_a_request_can_be_answered_with_something_already_filed(): void
    {
        // Somebody asked for the signed lease and the signed lease is already there. Uploading it
        // again would put a second copy in the system and the two would drift.
        $asker = $this->asker();
        $person = $this->user('document.view', 'document.upload');

        $this->actingAs($asker);
        [$request] = $this->service()->ask('Signed lease', [$person->id]);

        $document = Document::create([
            'title' => 'Lease, signed',
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
        ]);

        $this->actingAs($person);
        $this->service()->fulfilWith($request->fresh(), $document);

        $this->assertSame(DocumentRequest::FULFILLED, $request->fresh()->status);
        $this->assertSame($document->id, (int) $request->fresh()->document_id);
    }

    public function test_an_answered_document_goes_through_the_ordinary_upload_path(): void
    {
        /*
         * Scanned, versioned, extracted and audited exactly like a document filed by hand. A second
         * way into the repository that skipped any of that would be the way to get an unscanned
         * file in.
         */
        $asker = $this->asker();
        $person = $this->user('document.view', 'document.upload');

        $this->actingAs($asker);
        [$request] = $this->service()->ask('Certificate', [$person->id]);

        $this->actingAs($person);
        $result = $this->service()->fulfil($request->fresh(), $this->pdf());

        $this->assertSame(1, (int) $result['document']->version_count);
        $this->assertNotNull($result['document']->currentVersion, 'It has to have a version like any other upload.');
    }

    public function test_a_file_the_repository_would_refuse_is_still_refused(): void
    {
        $asker = $this->asker();
        $person = $this->user('document.view', 'document.upload');

        $this->actingAs($asker);
        [$request] = $this->service()->ask('Certificate', [$person->id]);

        $this->actingAs($person);
        $result = $this->service()->fulfil(
            $request->fresh(),
            UploadedFile::fake()->create('empty.pdf', 4, 'application/pdf'),
        );

        $this->assertNull($result['document']);
        $this->assertTrue($request->fresh()->isPending(), 'A refused upload must not close the request.');
    }

    // ── Chasing ──────────────────────────────────────────────────────────────

    public function test_overdue_is_a_date_that_has_passed_and_not_a_status(): void
    {
        /*
         * Treating it as a status would mean a request stops being pending on a Tuesday without
         * anybody doing anything — and then it is no longer in the queue of things owed.
         */
        $asker = $this->asker();
        $person = $this->user();

        $this->actingAs($asker);
        [$request] = $this->service()->ask('Late thing', [$person->id], ['due_at' => now()->subWeek()->toDateString()]);

        $request = $request->fresh();

        $this->assertTrue($request->isPending());
        $this->assertTrue($request->isOverdue());
        $this->assertSame(1, DocumentRequest::query()->overdue()->count());
    }

    public function test_asking_again_is_counted(): void
    {
        // "I have asked them three times" is the sentence that precedes escalating it, and nobody
        // should have to reconstruct it from their sent items.
        $asker = $this->asker();
        $person = $this->user();

        $this->actingAs($asker);
        [$request] = $this->service()->ask('Proof of address', [$person->id]);

        $this->post(route('document-management.requests.remind', $request->uuid))->assertRedirect();
        $this->post(route('document-management.requests.remind', $request->uuid))->assertRedirect();

        $this->assertSame(2, (int) $request->fresh()->reminders_sent);
    }

    public function test_only_the_person_waiting_can_chase_or_withdraw(): void
    {
        $asker = $this->asker();
        $person = $this->user('document.view');

        $this->actingAs($asker);
        [$request] = $this->service()->ask('Proof of address', [$person->id]);

        $this->actingAs($person)
            ->post(route('document-management.requests.cancel', $request->uuid))
            ->assertForbidden();

        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_a_withdrawn_request_leaves_the_queue(): void
    {
        $asker = $this->asker();
        $person = $this->user();

        $this->actingAs($asker);
        [$request] = $this->service()->ask('No longer needed', [$person->id]);

        $this->service()->cancel($request, 'Superseded');

        $this->assertSame(0, $this->service()->owedBy($person)->count());
    }

    // ── The two queues ───────────────────────────────────────────────────────

    public function test_what_you_owe_and_what_you_are_waiting_on_are_kept_apart(): void
    {
        $asker = $this->asker();
        $person = $this->user('document.view');

        $this->actingAs($asker);
        $this->service()->ask('From them', [$person->id]);

        $this->actingAs($person);
        $this->assertSame(1, $this->service()->owedBy($person)->count());
        $this->assertSame(0, $this->service()->askedBy($person)->count());

        $this->assertSame(0, $this->service()->owedBy($asker)->count());
        $this->assertSame(1, $this->service()->askedBy($asker)->count());
    }

    public function test_the_screen_opens_for_anybody_who_can_use_the_module(): void
    {
        // Being able to see what you owe cannot require a permission — the people who owe documents
        // are usually the ones with the fewest.
        $this->actingAs($this->user('document.view'))
            ->get(route('document-management.requests'))
            ->assertOk();
    }
}
