<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What happens to a document after it arrives.
 *
 * The idea being defended here is that a document is not its file. Replacing the file leaves the
 * document's identity, description, sensitivity and history exactly where they were — which is what
 * makes "update this document" a different act from "upload another one".
 */
class DocumentLifecycleTest extends TestCase
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

        $this->actingAs(User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'dml+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => $this->companyId,
        ]));
    }

    private function service(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function pdf(string $name = 'contract.pdf', string $body = 'v1'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n{$body}\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function document(array $attributes = []): Document
    {
        return $this->service()->store($this->pdf(), $attributes)['document'];
    }

    // ── Versions ─────────────────────────────────────────────────────────────

    public function test_replacing_a_file_keeps_the_document_and_adds_a_version(): void
    {
        $document = $this->document(['description' => 'The lease', 'is_confidential' => true]);
        $originalUuid = $document->uuid;

        $result = $this->service()->replace($document, $this->pdf('contract-v2.pdf', 'v2'), 'Signed copy');

        $this->assertNull($result['error']);
        $document->refresh();

        // Same document, new file underneath it.
        $this->assertSame($originalUuid, $document->uuid);
        $this->assertSame('The lease', $document->description);
        $this->assertTrue($document->is_confidential);
        $this->assertSame(2, $document->version_count);
        $this->assertSame(2, (int) $document->currentVersion->version);
    }

    public function test_the_earlier_version_is_still_there_and_still_readable(): void
    {
        $document = $this->document();
        $this->service()->replace($document, $this->pdf('contract-v2.pdf', 'v2'));

        $first = $document->versions()->where('version', 1)->first();

        // The whole point of versioning rather than overwriting: March is still answerable.
        $this->assertNotNull($first);
        $this->assertStringContainsString('v1', (string) $this->service()->contents($first));
    }

    public function test_a_replacement_records_who_did_it_and_why(): void
    {
        $document = $this->document();
        $this->service()->replace($document, $this->pdf('v2.pdf', 'v2'), 'Countersigned');

        $event = DocumentEvent::where('document_id', $document->id)
            ->where('action', DocumentEvent::REPLACED)->first();

        $this->assertNotNull($event);
        $this->assertSame('Countersigned', $event->detail['note']);
        $this->assertNotNull($event->user_id);
    }

    public function test_a_bad_replacement_leaves_the_document_untouched(): void
    {
        $document = $this->document();
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, '<?php echo 1;');

        $result = $this->service()->replace($document, new UploadedFile($path, 'x.php', 'text/plain', null, true));

        $this->assertNotNull($result['error']);
        $this->assertSame(1, $document->fresh()->version_count);
    }

    // ── Archive and restore ──────────────────────────────────────────────────

    public function test_archiving_is_reversible_and_keeps_the_document_readable(): void
    {
        $document = $this->document();

        $this->service()->archive($document, 'Superseded by the 2027 policy');
        $document->refresh();

        $this->assertTrue($document->isArchived());
        $this->assertNotNull($document->archived_at);
        $this->assertSame('Superseded by the 2027 policy', $document->archive_reason);
        // Still there, still has its file — archived is not deleted.
        $this->assertNotNull($document->currentVersion);

        $this->service()->restore($document);
        $document->refresh();

        $this->assertFalse($document->isArchived());
        $this->assertNull($document->archived_at);
        $this->assertNull($document->archive_reason);
    }

    public function test_an_archived_document_cannot_have_its_file_replaced(): void
    {
        $document = $this->document();
        $this->service()->archive($document);

        $result = $this->service()->replace($document->fresh(), $this->pdf('v2.pdf', 'v2'));

        // Restoring first is one click; a new version silently appearing on an archived document
        // is a surprise nobody asked for.
        $this->assertStringContainsString('Restore this document', (string) $result['error']);
    }

    // ── Deletion, and the hold that outranks it ──────────────────────────────

    public function test_deleting_is_recoverable_and_purging_is_not(): void
    {
        $document = $this->document();
        $id = $document->id;

        $this->assertTrue($this->service()->delete($document)['ok']);
        $this->assertNull(Document::find($id));
        // Recoverable until somebody destroys it deliberately.
        $this->assertNotNull(Document::withTrashed()->find($id));
    }

    public function test_purging_removes_the_document_and_its_files(): void
    {
        $document = $this->document();
        $this->service()->replace($document, $this->pdf('v2.pdf', 'v2'));
        $id = $document->id;

        $this->assertTrue($this->service()->purge($document)['ok']);

        $this->assertNull(Document::withTrashed()->find($id));
        $this->assertSame(0, DocumentVersion::where('document_id', $id)->count());
    }

    public function test_the_record_that_a_document_was_destroyed_survives_the_document(): void
    {
        $document = $this->document();
        $id = $document->id;

        $this->service()->purge($document);

        // The only thing standing between a deletion and an allegation.
        $this->assertSame(1, DocumentEvent::where('document_id', $id)
            ->where('action', DocumentEvent::PURGED)->count());
    }

    public function test_a_legally_sensitive_document_cannot_be_deleted_or_purged(): void
    {
        $document = $this->document(['is_legally_sensitive' => true]);

        $delete = $this->service()->delete($document);
        $purge = $this->service()->purge($document->fresh());

        // Not a permission question. The hold exists precisely to stop this, and an administrator
        // must not be able to destroy the one thing it protects.
        $this->assertFalse($delete['ok']);
        $this->assertFalse($purge['ok']);
        $this->assertStringContainsString('legally sensitive', $delete['error']);
        $this->assertNotNull(Document::find($document->id));
    }

    public function test_lifting_a_hold_is_recorded_with_what_changed(): void
    {
        $document = $this->document(['is_legally_sensitive' => true]);

        $this->service()->setSensitivity($document, false, false, 'Matter closed 2026-08-01');

        $event = DocumentEvent::where('document_id', $document->id)
            ->where('action', DocumentEvent::SENSITIVITY_CHANGED)->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->detail['from']['legally_sensitive']);
        $this->assertFalse($event->detail['to']['legally_sensitive']);
        $this->assertSame('Matter closed 2026-08-01', $event->detail['note']);
        // And now it can be destroyed, which is the point of recording the change.
        $this->assertTrue($this->service()->delete($document->fresh())['ok']);
    }

    // ── Folders ──────────────────────────────────────────────────────────────

    public function test_confidentiality_is_inherited_from_the_folder(): void
    {
        $parent = DocumentFolder::create(['name' => 'Legal', 'is_confidential' => true]);
        $child = DocumentFolder::create(['name' => 'Litigation', 'parent_id' => $parent->id]);
        $document = $this->document(['folder_id' => $child->id]);

        // The document's own flag is false; it is confidential because of where it sits.
        $this->assertFalse($document->is_confidential);
        $this->assertTrue($document->fresh()->isConfidential());
    }

    public function test_a_folder_path_is_rebuilt_rather_than_typed(): void
    {
        $parent = DocumentFolder::create(['name' => 'Finance']);
        $child = DocumentFolder::create(['name' => 'Invoices', 'parent_id' => $parent->id]);

        $this->assertSame('Finance/Invoices', $child->path);
        $this->assertSame(1, $child->depth);
    }
}
