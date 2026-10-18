<?php

namespace Tests\Feature\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\ArchiveBrowser;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Looking inside a zip without taking the whole thing.
 *
 * A reseller pack arrives as one archive of forty images and somebody wants the third one. The
 * only route was to download the lot, unpack it, find the file — and the document system then
 * knew that a zip had been fetched and nothing about what was actually wanted.
 *
 * An archive is also a file format that declares how much data to produce, which is not the same
 * as how much it contains, so most of what follows is about what this refuses.
 */
class LookingInsideAnArchiveTest extends TestCase
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

    /**
     * A real zip, stored the way an uploaded one is.
     *
     * @param  array<string, string>  $files
     */
    private function archive(array $files, string $extension = 'zip'): DocumentVersion
    {
        $path = tempnam(sys_get_temp_dir(), 'dm_test_zip_');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);

        $document = Document::create([
            'title' => 'Reseller pack', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ]);

        $version = DocumentVersion::create([
            'company_id' => $this->companyId, 'document_id' => $document->id,
            'version' => 1, 'major' => 1, 'minor' => 0,
            'original_name' => "pack.{$extension}", 'stored_name' => Str::random(20).".{$extension}",
            'disk' => 'documents', 'path' => 'documents/'.Str::random(12),
            'extension' => $extension, 'mime' => 'application/zip',
            'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes),
        ]);

        // Written the way contents() reads it, so the browser opens a real stored file.
        Storage::disk($version->disk)->put($version->path, $bytes);

        $document->update(['current_version_id' => $version->id]);

        return $version->fresh();
    }

    private function browser(): ArchiveBrowser
    {
        return app(ArchiveBrowser::class);
    }

    public function test_the_files_inside_are_listed_without_unpacking_anything(): void
    {
        $version = $this->archive([
            'images/one.png' => 'first',
            'images/two.png' => 'second',
            'readme.txt' => 'hello',
        ]);

        $listing = $this->browser()->listing($version);

        $this->assertTrue($listing['supported']);
        $this->assertSame(['one.png', 'two.png', 'readme.txt'], array_column($listing['entries'], 'basename'));
    }

    public function test_each_entry_says_what_it_is_and_how_big(): void
    {
        $entry = $this->browser()->listing($this->archive(['images/one.png' => str_repeat('x', 2048)]))['entries'][0];

        $this->assertSame('images', $entry['folder']);
        $this->assertSame('png', $entry['extension']);
        $this->assertSame(2048, $entry['size']);
        $this->assertNotEmpty($entry['size_label']);
        $this->assertTrue($entry['previewable']);
    }

    public function test_the_rubbish_an_archiver_leaves_behind_is_not_shown(): void
    {
        $listing = $this->browser()->listing($this->archive([
            'real.png' => 'x',
            '__MACOSX/._real.png' => 'junk',
            '.DS_Store' => 'junk',
        ]));

        // Nobody opening a reseller pack wants to be shown what the Mac that built it left in it.
        $this->assertSame(['real.png'], array_column($listing['entries'], 'basename'));
    }

    public function test_one_file_can_be_taken_out_on_its_own(): void
    {
        $version = $this->archive(['images/one.png' => 'the first image', 'images/two.png' => 'the second']);

        $entry = $this->browser()->entry($version, 0);

        $this->assertNotNull($entry);
        $this->assertSame('one.png', $entry['name']);
        $this->assertSame('the first image', $entry['bytes']);
    }

    // ── What it refuses ─────────────────────────────────────────────────────────────────────────

    public function test_an_entry_that_tries_to_escape_is_stripped_to_its_name(): void
    {
        $version = $this->archive(['../../../../etc/passwd' => 'root:x:0:0']);

        $entry = $this->browser()->entry($version, 0);

        /*
         * Nothing here writes to disk, but that name would go into a Content-Disposition header
         * and then into whatever the browser does with it. The archive does not get to choose
         * where the file lands.
         */
        $this->assertNotNull($entry);
        $this->assertSame('passwd', $entry['name']);
        $this->assertStringNotContainsString('..', $entry['name']);
    }

    public function test_a_file_claiming_to_be_enormous_is_not_expanded(): void
    {
        // A megabyte of zeroes compresses to almost nothing: the ratio is what distinguishes a
        // bomb from an ordinary well-compressed file before you expand it.
        $version = $this->archive(['bomb.txt' => str_repeat("\0", 5_000_000)]);

        $this->assertNull(
            $this->browser()->entry($version, 0),
            'An entry whose claimed size dwarfs its compressed size was expanded anyway.'
        );
    }

    public function test_listing_a_bomb_is_still_safe(): void
    {
        $version = $this->archive(['bomb.txt' => str_repeat("\0", 5_000_000)]);

        // Reading the directory does not expand anything, so the file is listed — and marked.
        $listing = $this->browser()->listing($version);

        $this->assertTrue($listing['supported']);
        $this->assertSame(5_000_000, $listing['entries'][0]['size']);
    }

    public function test_a_document_that_is_not_an_archive_is_left_alone(): void
    {
        $version = $this->archive(['a.txt' => 'x'], extension: 'pdf');

        $listing = $this->browser()->listing($version);

        $this->assertFalse($listing['supported']);
        $this->assertSame([], $listing['entries']);
    }

    public function test_an_office_file_is_not_treated_as_an_archive(): void
    {
        // A .docx is a zip, and listing its XML parts to somebody who opened a contract would be
        // showing them the machinery rather than the document.
        $this->assertFalse($this->browser()->isBrowsable('docx'));
        $this->assertTrue($this->browser()->isBrowsable('zip'));
    }
}
