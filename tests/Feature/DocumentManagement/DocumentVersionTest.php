<?php

namespace Tests\Feature\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Version control.
 *
 * The requirement is that every change is tracked and an earlier version can be put back. The part
 * worth being careful about is what "put back" means: the history has to stay a record of what
 * happened, so restoring is a new version rather than a pointer moved backwards.
 */
class DocumentVersionTest extends TestCase
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

        // Versions record who uploaded them, so these run as somebody rather than as nobody.
        $this->actingAs(\App\Core\User\Models\User::forceCreate([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'email' => 'ver+' . \Illuminate\Support\Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]));
    }

    private function documents(): DocumentService
    {
        return app(DocumentService::class);
    }

    private function file(string $contents, string $name = 'contract.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ver');
        file_put_contents($path, "%PDF-1.4\n" . $contents . "\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function document(): Document
    {
        return $this->documents()->store($this->file('original'), ['title' => 'Employment contract'])['document'];
    }

    // ── Numbering ────────────────────────────────────────────────────────────

    public function test_a_correction_is_a_minor_version_and_a_new_edition_is_major(): void
    {
        $document = $this->document();

        $this->assertSame('1.0', $document->currentVersion->label());

        $this->documents()->replace($document, $this->file('typo fixed'), 'Corrected a date');
        $this->assertSame('1.1', $document->fresh()->currentVersion->label());

        // Only the uploader knows which they did — nothing can infer it from the bytes.
        $this->documents()->replace($document->fresh(), $this->file('renegotiated'), 'New terms', major: true);
        $this->assertSame('2.0', $document->fresh()->currentVersion->label());
    }

    public function test_every_version_records_who_and_when_and_why(): void
    {
        $document = $this->document();
        $this->documents()->replace($document, $this->file('second'), 'Salary revised');

        $version = $document->fresh()->currentVersion;

        $this->assertSame('Salary revised', $version->change_note);
        $this->assertNotNull($version->created_at);
        $this->assertNotNull($version->uploaded_by);
        // The chain reads backwards without inferring it from the number, which matters because a
        // restore is not the version numerically before it.
        $this->assertNotNull($version->supersedes_id);
    }

    public function test_replacing_keeps_every_earlier_file(): void
    {
        $document = $this->document();
        $first = $document->currentVersion;

        $this->documents()->replace($document, $this->file('second'), 'Second');

        $this->assertSame(2, $document->fresh()->versions()->count());
        // The old file is still there, not just the row describing it.
        $this->assertTrue(Storage::disk('documents')->exists($first->path));
        $this->assertStringContainsString('original', Storage::disk('documents')->get($first->path));
    }

    // ── Restoring ────────────────────────────────────────────────────────────

    public function test_restoring_makes_an_earlier_version_current_again(): void
    {
        $document = $this->document();
        $original = $document->currentVersion;

        $this->documents()->replace($document, $this->file('unwanted change'), 'Oops');
        $this->documents()->restoreVersion($document->fresh(), $original, 'Reverted the change');

        $current = $document->fresh()->currentVersion;

        $this->assertStringContainsString('original', Storage::disk('documents')->get($current->path));
        $this->assertSame($original->id, $current->restored_from_id);
    }

    public function test_restoring_adds_to_the_history_rather_than_rewinding_it(): void
    {
        $document = $this->document();
        $original = $document->currentVersion;

        $this->documents()->replace($document, $this->file('second'), 'Second');
        $this->documents()->restoreVersion($document->fresh(), $original);

        // Three versions: the original, the replacement, and the restoration. Rewinding a pointer
        // would erase both the restoration and what it replaced, and the history has to remain a
        // record of what happened.
        $this->assertSame(3, $document->fresh()->versions()->count());
        $this->assertSame('1.2', $document->fresh()->currentVersion->label());
    }

    public function test_a_restored_version_owns_its_own_file(): void
    {
        $document = $this->document();
        $original = $document->currentVersion;

        $this->documents()->replace($document, $this->file('second'), 'Second');
        $this->documents()->restoreVersion($document->fresh(), $original);

        $restored = $document->fresh()->currentVersion;

        // Sharing the file would mean deleting the original later emptied the restoration.
        $this->assertNotSame($original->path, $restored->path);
        $this->assertSame(
            Storage::disk('documents')->get($original->path),
            Storage::disk('documents')->get($restored->path),
        );
    }

    public function test_restoring_is_recorded_as_a_restoration(): void
    {
        $document = $this->document();
        $original = $document->currentVersion;
        $this->documents()->replace($document, $this->file('second'), 'Second');

        $this->documents()->restoreVersion($document->fresh(), $original, 'Wrong file uploaded');

        $event = $document->fresh()->events()->where('action', DocumentEvent::VERSION_RESTORED)->first();

        // Presented as a restoration rather than an ordinary upload, because those are different
        // things to read in an audit trail.
        $this->assertNotNull($event);
        $this->assertSame('1.0', $event->detail['restored_from'] ?? null);
    }

    public function test_the_current_version_cannot_be_restored_onto_itself(): void
    {
        $document = $this->document();

        $result = $this->documents()->restoreVersion($document, $document->currentVersion);

        // A no-op that added a version would grow the history every time somebody clicked it.
        $this->assertNull($result['version']);
        $this->assertSame(1, $document->fresh()->versions()->count());
    }

    public function test_a_version_from_another_document_is_refused(): void
    {
        $mine = $this->document();
        $theirs = $this->document();

        $result = $this->documents()->restoreVersion($mine, $theirs->currentVersion);

        $this->assertNull($result['version']);
    }

    // ── Expiry meets versions ────────────────────────────────────────────────

    public function test_replacing_an_expired_document_clears_the_expired_mark(): void
    {
        $document = $this->document();
        $document->update(['expiry_date' => now()->subDay(), 'expired_at' => now()]);

        $this->documents()->replace($document, $this->file('renewed'), 'Renewed certificate');

        // Renewing is how an expired document comes back. The date itself is left alone — only the
        // person uploading knows the new one, and the stale date on screen is the prompt to set it.
        $this->assertNull($document->fresh()->expired_at);
    }
}
