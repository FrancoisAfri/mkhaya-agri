<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\DocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who has read the procedure, and what a printed copy says.
 *
 * `requires_acknowledgement` was a flag the register displayed and could not act on. The service
 * had `acknowledgementStatus()` to answer it and nothing called it, because it has to be told who
 * is expected and nothing turned a document's audience into a list of people. So a company could
 * publish a procedure, require everybody to confirm they had read it, and have no way to find out
 * who had not — the only way to chase it was to ask.
 *
 * `watermark()` was unreached for a related reason: its only caller was `readerView()`, which
 * nothing called. SHEQ-DOC-3 requires every copy to carry its version, its publish date and an
 * uncontrolled-copy marking, and the library showed a generic sentence about watermarking with no
 * actual watermark on any document.
 */
class DocumentAssuranceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $author;

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
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ack-'.Str::random(8).'@example.test',
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

    private function publish(array $attributes = []): string
    {
        $service = app(DocumentService::class);

        $document = $service->create($this->companyId, array_merge([
            'doc_number' => 'DOC-'.Str::random(6),
            'title' => 'Working at height',
            'doc_type' => 'PROCEDURE',
            'requires_acknowledgement' => true,
            'applies_to' => ['mode' => 'EVERYONE'],
        ], $attributes), $this->author->id);

        $service->draftVersion($this->companyId, $document['uuid'], [], $this->author->id);
        $service->publish($this->companyId, $document['uuid'], 1, $this->author->id);

        return $document['uuid'];
    }

    private function registerRow(string $uuid): array
    {
        $rows = $this->actingAs($this->author)->get('/sheq/documents')
            ->viewData('page')['props']['rows'];

        return collect($rows)->firstWhere('uuid', $uuid);
    }

    // ── A procedure can actually be put in force ──────────────────────────────────────────────

    public function test_a_document_authored_on_the_register_can_be_published_from_it(): void
    {
        $this->actingAs($this->author)->post('/sheq/documents', [
            'doc_number' => 'DOC-'.Str::random(6),
            'title' => 'Working at height',
            'doc_type' => 'PROCEDURE',
            'requires_acknowledgement' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $uuid = DB::table('sheq_documents')
            ->where('company_id', $this->companyId)->orderByDesc('id')->value('uuid');

        /*
         * `publish()` was complete and had no route, so every procedure authored here stayed a
         * draft for ever. The reader library shows published documents only, so it was permanently
         * empty; `requires_acknowledgement` could never be satisfied; and the review reminder had
         * nothing to find. The module could author procedures and could not issue one.
         */
        $this->assertSame('DRAFT', DB::table('sheq_documents')
            ->where('uuid', $uuid)->value('status'));

        $this->actingAs($this->author)->post("/sheq/documents/{$uuid}/publish", ['version' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('PUBLISHED', DB::table('sheq_documents')
            ->where('uuid', $uuid)->value('status'));
    }

    public function test_publishing_puts_it_in_front_of_the_people_it_applies_to(): void
    {
        $reader = $this->userWith([]);

        $this->actingAs($this->author)->post('/sheq/documents', [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'Lifting plan',
            'doc_type' => 'PROCEDURE',
        ])->assertRedirect();

        $uuid = DB::table('sheq_documents')
            ->where('company_id', $this->companyId)->orderByDesc('id')->value('uuid');

        // A draft is a procedure nobody is following, and the library says so by omission.
        $this->assertSame([], collect($this->actingAs($reader)->get('/sheq/library')
            ->viewData('page')['props']['documents'])->where('uuid', $uuid)->all());

        $this->actingAs($this->author)->post("/sheq/documents/{$uuid}/publish", ['version' => 1]);

        $this->assertCount(1, collect($this->actingAs($reader)->get('/sheq/library')
            ->viewData('page')['props']['documents'])->where('uuid', $uuid)->all());
    }

    public function test_publishing_is_audited_with_the_version_it_replaced(): void
    {
        $uuid = $this->publish();

        $entry = DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)->where('action', 'document.published')
            ->orderByDesc('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->author->id, (int) $entry->actor_id);
        $this->assertSame(1, json_decode((string) $entry->after, true)['version']);
    }

    public function test_publishing_needs_the_document_entitlement(): void
    {
        $this->actingAs($this->author)->post('/sheq/documents', [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'Gated', 'doc_type' => 'PROCEDURE',
        ])->assertRedirect();

        $uuid = DB::table('sheq_documents')
            ->where('company_id', $this->companyId)->orderByDesc('id')->value('uuid');

        $this->actingAs($this->userWith([]))
            ->post("/sheq/documents/{$uuid}/publish", ['version' => 1])
            ->assertForbidden();

        $this->assertSame('DRAFT', DB::table('sheq_documents')
            ->where('uuid', $uuid)->value('status'));
    }

    public function test_a_version_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->author)->post('/sheq/documents', [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'One draft only',
            'doc_type' => 'PROCEDURE',
        ])->assertRedirect();

        $uuid = DB::table('sheq_documents')
            ->where('company_id', $this->companyId)->orderByDesc('id')->value('uuid');

        // Publishing the wrong text is the one mistake this screen must not make easy, so the
        // version is named and a name that does not exist is a refusal the person is shown.
        $this->actingAs($this->author)
            ->post("/sheq/documents/{$uuid}/publish", ['version' => 7])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame('DRAFT', DB::table('sheq_documents')
            ->where('uuid', $uuid)->value('status'));
    }

    public function test_the_register_lists_the_drafts_waiting_to_be_issued(): void
    {
        $this->actingAs($this->author)->post('/sheq/documents', [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'Not in force',
            'doc_type' => 'PROCEDURE',
        ])->assertRedirect();

        $statuses = array_column($this->actingAs($this->author)->get('/sheq/documents')
            ->viewData('page')['props']['rows'], 'status');

        // The screen builds its "awaiting publish" list from this: a procedure written and never
        // issued is indistinguishable, in a status column, from one that is simply new.
        $this->assertContains('DRAFT', $statuses);
    }

    public function test_the_register_says_how_many_of_the_audience_have_read_it(): void
    {
        $uuid = $this->publish();
        $reader = $this->userWith([]);

        $row = $this->registerRow($uuid);

        // Two accounts with personnel records exist, and the document is addressed to everybody.
        $this->assertGreaterThanOrEqual(2, $row['acknowledgement']['expected']);
        $this->assertSame(0, $row['acknowledgement']['acknowledged']);
        $this->assertSame(0.0, $row['acknowledgement']['percent']);
        $this->assertGreaterThan(0, $row['acknowledgement']['outstanding']);

        $this->actingAs($reader)->post("/sheq/library/{$uuid}/acknowledge")->assertRedirect();

        $after = $this->registerRow($uuid);

        $this->assertSame(1, $after['acknowledgement']['acknowledged']);
        $this->assertGreaterThan(0.0, $after['acknowledgement']['percent']);
    }

    public function test_a_document_that_asks_for_no_acknowledgement_reports_none(): void
    {
        // A percentage against a document nobody has to confirm is a number with nothing behind
        // it, and it would make the register look like it had a backlog.
        $uuid = $this->publish(['requires_acknowledgement' => false]);

        $this->assertNull($this->registerRow($uuid)['acknowledgement']);
    }

    public function test_a_draft_reports_no_acknowledgement_figure(): void
    {
        $service = app(DocumentService::class);

        $document = $service->create($this->companyId, [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'Not issued yet',
            'doc_type' => 'PROCEDURE', 'requires_acknowledgement' => true,
        ], $this->author->id);

        $this->assertNull($this->registerRow($document['uuid'])['acknowledgement']);
    }

    public function test_a_document_addressed_to_a_role_nobody_holds_expects_nobody(): void
    {
        $uuid = $this->publish(['applies_to' => ['mode' => 'ROLES', 'roles' => ['Rigger']]]);

        $row = $this->registerRow($uuid);

        // Reporting the whole company as outstanding on a document addressed to a role nobody
        // holds would be a backlog that does not exist.
        $this->assertSame(0, $row['acknowledgement']['expected']);
        $this->assertSame(0, $row['acknowledgement']['outstanding']);
    }

    public function test_the_expected_audience_and_the_library_agree(): void
    {
        $uuid = $this->publish(['applies_to' => ['mode' => 'EVERYONE']]);
        $reader = $this->userWith([]);

        $visible = collect($this->actingAs($reader)->get('/sheq/library')
            ->viewData('page')['props']['documents'])->pluck('uuid');

        // The register counts this reader as owing an acknowledgement, so the library has to show
        // them the document. The two directions of the audience rule must not disagree.
        $this->assertTrue($visible->contains($uuid));
        $this->assertGreaterThan(0, $this->registerRow($uuid)['acknowledgement']['expected']);
    }

    public function test_the_audience_rule_is_never_sent_to_the_browser(): void
    {
        $uuid = $this->publish(['applies_to' => ['mode' => 'ROLES', 'roles' => ['Rigger']]]);

        // The register needs the audience to count against; the browser does not need the rule
        // itself, and a props payload is the easiest place to leak one.
        $this->assertArrayNotHasKey('applies_to', $this->registerRow($uuid));
    }

    public function test_every_document_in_the_library_carries_its_own_watermark(): void
    {
        $uuid = $this->publish();
        $reader = $this->userWith([]);

        $document = collect($this->actingAs($reader)->get('/sheq/library')
            ->viewData('page')['props']['documents'])->firstWhere('uuid', $uuid);

        // SHEQ-DOC-3: version, publish date, and that a print is uncontrolled. A reader could
        // previously print a procedure with nothing on the page saying which version it was.
        $this->assertStringContainsString('v1', $document['watermark']);
        $this->assertStringContainsString(Carbon::today()->toDateString(), $document['watermark']);
        $this->assertStringContainsString('uncontrolled when printed', $document['watermark']);
    }

    public function test_the_indexing_backlog_is_reported_rather_than_passed_over(): void
    {
        $uuid = $this->publish();

        DB::table('sheq_documents')
            ->where('company_id', $this->companyId)->where('uuid', $uuid)
            ->update(['is_indexed_for_ai' => true, 'indexed_at' => null]);

        /*
         * Document text extraction is not built, which is a stated decision. What was not stated
         * was the consequence: a tenant could tick "index this for AI" on fifty procedures, watch
         * the nightly job report success every night, and never learn that the assistant could not
         * cite one of them.
         */
        $this->artisan('sheq:knowledge:index', ['--company' => $this->companyId])
            ->expectsOutputToContain('waiting')
            ->assertExitCode(0);
    }
}
