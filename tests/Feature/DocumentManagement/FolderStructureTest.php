<?php

namespace Tests\Feature\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Building and rearranging the filing structure.
 *
 * Every operation here fails quietly when it fails at all — a folder moved into itself detaches a
 * branch while leaving plausible-looking paths behind, and a rename that skips its descendants
 * leaves them describing a place that no longer exists. None of it throws on its own.
 */
class FolderStructureTest extends TestCase
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
    }

    private function folders(): FolderService
    {
        return app(FolderService::class);
    }

    private function make(string $name, ?DocumentFolder $parent = null, array $extra = []): DocumentFolder
    {
        return $this->folders()->create(array_merge([
            'name' => $name,
            'parent_id' => $parent?->uuid,
        ], $extra));
    }

    // ── Structure ────────────────────────────────────────────────────────────

    public function test_folders_nest_and_carry_their_ancestors_in_the_path(): void
    {
        $hr = $this->make('Human Resources');
        $files = $this->make('Employee Files', $hr);

        $this->assertSame('Human Resources', $hr->path);
        $this->assertSame('Human Resources/Employee Files', $files->path);
        $this->assertSame(1, $files->depth);
    }

    public function test_renaming_a_folder_repairs_every_path_beneath_it(): void
    {
        $hr = $this->make('Human Resources');
        $files = $this->make('Employee Files', $hr);
        $archive = $this->make('2024', $files);

        $this->folders()->rename($hr, 'People');

        // Without the cascade these would still say "Human Resources/…", describing a folder that
        // no longer exists — and every breadcrumb and subtree query would read from it.
        $this->assertSame('People/Employee Files', $files->fresh()->path);
        $this->assertSame('People/Employee Files/2024', $archive->fresh()->path);
    }

    public function test_two_folders_in_one_place_cannot_share_a_name(): void
    {
        $parent = $this->make('Finance');
        $this->make('Invoices', $parent);
        $second = $this->make('Invoices', $parent);

        // The path identifies a folder, so a duplicate name would make one of them unaddressable.
        $this->assertSame('Invoices (2)', $second->name);
    }

    public function test_the_same_name_is_fine_in_different_places(): void
    {
        $hr = $this->make('Human Resources');
        $legal = $this->make('Legal');

        $this->assertSame('Contracts', $this->make('Contracts', $hr)->name);
        $this->assertSame('Contracts', $this->make('Contracts', $legal)->name);
    }

    // ── Moving ───────────────────────────────────────────────────────────────

    public function test_moving_a_folder_rewrites_its_subtree(): void
    {
        $hr = $this->make('Human Resources');
        $legal = $this->make('Legal');
        $contracts = $this->make('Contracts', $hr);
        $signed = $this->make('Signed', $contracts);

        $this->folders()->move($contracts, $legal->uuid);

        $this->assertSame('Legal/Contracts', $contracts->fresh()->path);
        $this->assertSame('Legal/Contracts/Signed', $signed->fresh()->path);
        $this->assertSame(2, $signed->fresh()->depth);
    }

    public function test_a_folder_cannot_be_moved_into_itself(): void
    {
        $folder = $this->make('Finance');

        $this->expectException(RuntimeException::class);
        $this->folders()->move($folder, $folder->uuid);
    }

    public function test_a_folder_cannot_be_moved_into_its_own_descendant(): void
    {
        $hr = $this->make('Human Resources');
        $files = $this->make('Employee Files', $hr);
        $year = $this->make('2024', $files);

        // The one that matters: this detaches the whole branch from the tree, and afterwards the
        // paths still look plausible, so nobody notices until a folder cannot be found.
        $this->expectExceptionMessageMatches('/sub-folder/');
        $this->folders()->move($hr, $year->uuid);
    }

    public function test_a_folder_can_be_moved_to_the_root(): void
    {
        $hr = $this->make('Human Resources');
        $files = $this->make('Employee Files', $hr);

        $this->folders()->move($files, null);

        $this->assertNull($files->fresh()->parent_id);
        $this->assertSame('Employee Files', $files->fresh()->path);
        $this->assertSame(0, $files->fresh()->depth);
    }

    // ── Copying ──────────────────────────────────────────────────────────────

    public function test_copying_a_folder_duplicates_its_structure(): void
    {
        $source = $this->make('Templates');
        $this->make('Contracts', $source);
        $this->make('Letters', $source);

        $copy = $this->folders()->copy($source, null, 'Templates 2025');

        $this->assertSame('Templates 2025', $copy->name);
        $this->assertSame(2, DocumentFolder::where('parent_id', $copy->id)->count());
        // The original is untouched.
        $this->assertSame(2, DocumentFolder::where('parent_id', $source->id)->count());
    }

    public function test_a_copied_document_gets_its_own_file(): void
    {
        $source = $this->make('Templates');
        $document = app(DocumentService::class)->store($this->pdf(), ['folder_id' => $source->id])['document'];

        $copy = $this->folders()->copy($source, null, 'Templates Copy');
        $copied = Document::where('folder_id', $copy->id)->first();

        $this->assertNotNull($copied);
        // Sharing one file between two documents would mean replacing the copy silently replaced
        // the original, and deleting either would take the file from under its twin.
        $this->assertNotSame($document->currentVersion->path, $copied->currentVersion->path);
        $this->assertSame(
            Storage::disk('documents')->get($document->currentVersion->path),
            Storage::disk('documents')->get($copied->currentVersion->path),
        );
    }

    public function test_a_folder_cannot_be_copied_into_its_own_descendant(): void
    {
        $parent = $this->make('Finance');
        $child = $this->make('Invoices', $parent);

        $this->expectException(RuntimeException::class);
        $this->folders()->copy($parent, $child->uuid);
    }

    // ── Archiving ────────────────────────────────────────────────────────────

    public function test_archiving_a_folder_takes_its_contents_with_it(): void
    {
        $parent = $this->make('Old Projects');
        $child = $this->make('2019', $parent);
        $document = app(DocumentService::class)->store($this->pdf(), ['folder_id' => $child->id])['document'];

        $this->folders()->archive($parent);

        // A child left visible under an archived parent is reachable through a folder that, as far
        // as the tree is concerned, no longer exists.
        $this->assertNotNull($child->fresh()->archived_at);
        $this->assertSame(Document::STATUS_ARCHIVED, $document->fresh()->status);
    }

    public function test_restoring_a_folder_brings_back_its_ancestors_too(): void
    {
        $parent = $this->make('Old Projects');
        $child = $this->make('2019', $parent);

        $this->folders()->archive($parent);
        $this->folders()->restore($child);

        // Otherwise the child is visible in a place that is not.
        $this->assertNull($parent->fresh()->archived_at);
        $this->assertNull($child->fresh()->archived_at);
    }

    // ── Deleting ─────────────────────────────────────────────────────────────

    public function test_a_folder_holding_documents_is_not_deleted(): void
    {
        $folder = $this->make('Payroll');
        app(DocumentService::class)->store($this->pdf(), ['folder_id' => $folder->id]);

        // Deleting a folder is a statement about the structure. Deleting what people filed in it is
        // a different decision, and merging the two means one click destroys both.
        $this->expectExceptionMessageMatches('/still holds/');
        $this->folders()->delete($folder);
    }

    public function test_an_empty_folder_and_its_empty_children_are_removed(): void
    {
        $parent = $this->make('Scratch');
        $child = $this->make('Temp', $parent);

        $this->folders()->delete($parent);

        $this->assertNull(DocumentFolder::find($parent->id));
        $this->assertNull(DocumentFolder::find($child->id));
    }

    private function pdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, "%PDF-1.4\ncontent\n%%EOF");

        return new UploadedFile($path, 'report.pdf', 'application/pdf', null, true);
    }
}
