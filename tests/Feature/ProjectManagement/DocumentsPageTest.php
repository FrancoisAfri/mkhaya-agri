<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\DocumentBlob;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Project documents (EPIC-050).
 *
 * PM never holds the bytes — it holds a reference to whatever the installed DocumentStore gave
 * back. These tests are about that boundary holding: files reach the store, links are short-lived,
 * and removing a document from a project does not reach into the store and delete it.
 */
class DocumentsPageTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
        Storage::fake('local');
    }

    private function upload(string $name = 'drawing-a1.pdf'): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.documents.store', $this->project->uuid), [
                'file' => UploadedFile::fake()->createWithContent($name, 'DRAWING'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_a_document_reaches_the_store_and_appears_on_the_page(): void
    {
        $this->upload();

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.documents', $this->project->uuid))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('drawing-a1.pdf', $props['documents'][0]['name']);
        $this->assertSame('database', $props['store'], 'The Documents module is not installed in this test.');
    }

    /**
     * The bytes are rows in a tenant-scoped table, not a file on a disk.
     *
     * That is the property worth a test of its own: a file on a shared disk is outside row-level
     * security, so one tenant's site photographs would sit in a directory beside another's, and a
     * database restore would bring back the index of a drawing without the drawing.
     */
    public function test_the_bytes_go_to_the_database_and_never_to_a_disk(): void
    {
        $this->upload();

        $blob = DocumentBlob::first();

        $this->assertNotNull($blob, 'The upload did not reach pm_document_blobs.');
        $this->assertSame('drawing-a1.pdf', $blob->original_name);
        $this->assertSame('DRAWING', $blob->bytes());

        // The platform's own shape, written by App\Core\User\Trait\FileStorage: base64 in `data`,
        // with the type it decided. One convention on this system rather than a PM-specific one.
        $this->assertSame('document', $blob->type);
        $this->assertSame(base64_encode('DRAWING'), $blob->payload());
        $this->assertSame(7, $blob->size);

        $this->assertSame([], Storage::disk('local')->allFiles(), 'Nothing may be written to a disk.');
    }

    /** Served through the application, under the caller's own authorisation — no URL to leak. */
    public function test_downloading_streams_the_bytes_rather_than_handing_out_a_link(): void
    {
        $this->upload();

        $id = ExternalRef::where('source_entity', 'document')->value('uuid');

        $response = $this->actingAs($this->manager)
            ->get(route('project-management.documents.show', [$this->project->uuid, $id]))
            ->assertOk();

        $this->assertSame('DRAWING', $response->streamedContent());
        // Uploaded by a user, so the browser must not be allowed to decide what it is.
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * Removing a document now removes the bytes too.
     *
     * The old store kept them: they lived on a disk it did not own, and deleting another module's
     * file on a guess was the wrong trade. This store owns them, so "removed" that only hid a row
     * would leak storage forever and leave a tenant's document readable after they deleted it.
     */
    public function test_removing_a_document_deletes_the_bytes(): void
    {
        $this->upload();

        $id = ExternalRef::where('source_entity', 'document')->value('uuid');

        $this->actingAs($this->manager)
            ->delete(route('project-management.documents.destroy', [$this->project->uuid, $id]))
            ->assertRedirect();

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.documents', $this->project->uuid))
            ->viewData('page')['props'];

        $this->assertSame([], $props['documents']);
        $this->assertSame(0, DocumentBlob::count(), 'The bytes are still in the database.');
    }

    public function test_a_field_worker_can_read_but_not_upload(): void
    {
        $this->upload();

        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.documents', $this->project->uuid))
            ->assertOk();

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.documents.store', $this->project->uuid), [
                'file' => UploadedFile::fake()->createWithContent('sneaky.pdf', 'X'),
            ])
            ->assertForbidden();

        $this->assertSame(1, ExternalRef::where('source_entity', 'document')->count());
    }

    public function test_another_projects_document_is_not_reachable_through_this_one(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);

        $this->actingAs($this->manager)
            ->post(route('project-management.documents.store', $other->uuid), [
                'file' => UploadedFile::fake()->createWithContent('theirs.pdf', 'THEIRS'),
            ]);

        $theirs = ExternalRef::where('source_entity', 'document')->value('uuid');

        // 404, not a quiet no-op: a document uuid that belongs to another job does not resolve
        // through this one, for reading or for deleting.
        $this->actingAs($this->manager)
            ->delete(route('project-management.documents.destroy', [$this->project->uuid, $theirs]))
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->get(route('project-management.documents.show', [$this->project->uuid, $theirs]))
            ->assertNotFound();

        $this->assertSame(1, ExternalRef::where('source_entity', 'document')->count());
        $this->assertSame(1, DocumentBlob::count());
    }
}
