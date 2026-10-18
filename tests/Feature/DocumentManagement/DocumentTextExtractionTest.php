<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\DocumentSearch;
use App\Modules\Operations\DocumentManagement\Services\TextExtractor;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reading what is inside a file.
 *
 * The failure worth preventing is not "extraction crashed" — that is loud and gets fixed. It is
 * extraction quietly returning nothing, which makes a document that plainly contains a phrase report
 * no results, and lets an assistant answer questions about a page it never read. So most of these
 * tests are about the difference between the several ways of having no text.
 */
class DocumentTextExtractionTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $user;

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

        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ext+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->user);

        Storage::fake('documents');
    }

    private function extractor(): TextExtractor
    {
        return app(TextExtractor::class);
    }

    /** A document whose current version really holds these bytes. */
    private function document(string $bytes, string $extension, string $mime, string $title = 'A document'): Document
    {
        $document = Document::create([
            'title' => $title, 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ]);

        $path = 'extract/' . Str::random(12) . '.' . $extension;
        Storage::disk('documents')->put($path, $bytes);

        $version = DocumentVersion::create([
            'uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'version' => 1, 'major' => 1, 'minor' => 0,
            'disk' => 'documents', 'path' => $path,
            'original_name' => 'file.' . $extension, 'stored_name' => basename($path),
            'extension' => $extension, 'mime' => $mime, 'size' => strlen($bytes),
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        return $document->fresh();
    }

    /** A real PNG with real words on it, so an OCR engine has something genuine to read. */
    private function imageBytes(string $line): string
    {
        $image = imagecreatetruecolor(700, 160);
        imagefilledrectangle($image, 0, 0, 700, 160, imagecolorallocate($image, 255, 255, 255));

        if ($line !== '') {
            imagestring($image, 5, 20, 60, $line, imagecolorallocate($image, 0, 0, 0));
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** A real .docx — a zip with one named part, which is what the reader opens. */
    private function docxBytes(string $text): string
    {
        $file = tempnam(sys_get_temp_dir(), 'test_docx_');
        $zip = new \ZipArchive;
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="x"><w:body><w:p><w:r><w:t>'
            . htmlspecialchars($text) . '</w:t></w:r></w:p></w:body></w:document>',
        );
        $zip->close();

        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function test_a_plain_text_file_is_read(): void
    {
        $document = $this->document("Indemnity clause seventeen\nSecond line", 'txt', 'text/plain');

        $extraction = $this->extractor()->extractCurrent($document);

        $this->assertSame(TextExtractor::EXTRACTED, $extraction->status);
        $this->assertStringContainsString('Indemnity clause seventeen', $extraction->text);
        $this->assertGreaterThan(0, $extraction->word_count);
    }

    public function test_a_word_document_is_read_without_its_markup(): void
    {
        $document = $this->document($this->docxBytes('Retention schedule five years'), 'docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $extraction = $this->extractor()->extractCurrent($document);

        $this->assertSame(TextExtractor::EXTRACTED, $extraction->status);
        $this->assertSame('docx', $extraction->method);
        $this->assertStringContainsString('Retention schedule five years', $extraction->text);
        $this->assertStringNotContainsString('<w:', $extraction->text, 'XML tags in the text would be indexed and searched as words.');
    }

    // ── The several ways of having no text ───────────────────────────────────

    public function test_an_image_is_never_written_off_as_an_unreadable_file_type(): void
    {
        /*
         * The distinction this whole table exists for. A scanned invoice contains its words, and
         * what happens next depends entirely on the server: with an OCR engine it is read, without
         * one it is flagged as needing one. Neither answer is "unsupported", which would tell an
         * administrator the file is the wrong sort rather than that something is missing.
         */
        $document = $this->document($this->imageBytes('DELIVERY NOTE 4471'), 'png', 'image/png');

        $extraction = $this->extractor()->extractCurrent($document);

        $this->assertNotSame(TextExtractor::UNSUPPORTED, $extraction->status);

        if ($this->extractor()->ocrAvailable()) {
            $this->assertSame(TextExtractor::EXTRACTED, $extraction->status);
            $this->assertSame('ocr', $extraction->method);
            $this->assertStringContainsStringIgnoringCase('DELIVERY', (string) $extraction->text);

            return;
        }

        // No engine here: say so, because that is a thing somebody can fix.
        $this->assertSame(TextExtractor::OCR_UNAVAILABLE, $extraction->status);
        $this->assertTrue($extraction->needsAttention());
    }

    public function test_an_image_with_nothing_written_on_it_is_empty_rather_than_a_problem(): void
    {
        // A blank page read correctly is not something for anybody's attention list.
        if (! $this->extractor()->ocrAvailable()) {
            $this->markTestSkipped('No OCR engine installed on this machine.');
        }

        $document = $this->document($this->imageBytes(''), 'png', 'image/png');

        $extraction = $this->extractor()->extractCurrent($document);

        $this->assertSame(TextExtractor::EMPTY, $extraction->status);
        $this->assertFalse($extraction->needsAttention());
    }

    public function test_an_unreadable_file_type_is_unsupported_rather_than_failed(): void
    {
        // Nobody expected to search inside a video, so this is not worth anybody's attention.
        $document = $this->document('binary rubbish', 'mp4', 'video/mp4');

        $extraction = $this->extractor()->extractCurrent($document);

        $this->assertSame(TextExtractor::UNSUPPORTED, $extraction->status);
        $this->assertFalse($extraction->needsAttention());
    }

    public function test_a_genuinely_blank_file_is_empty_and_not_a_failure(): void
    {
        $document = $this->document('   ', 'txt', 'text/plain');

        $extraction = $this->extractor()->extractCurrent($document);

        $this->assertSame(TextExtractor::EMPTY, $extraction->status);
        $this->assertFalse($extraction->hasText());
    }

    public function test_a_row_is_written_even_when_the_file_cannot_be_read(): void
    {
        // A version with no extraction row is indistinguishable from one nobody has tried yet, so
        // the backfill would either keep picking it up forever or never look at it again.
        $document = $this->document('x', 'txt', 'text/plain');
        Storage::disk('documents')->delete($document->currentVersion->path);

        $extraction = $this->extractor()->extractCurrent($document);

        $this->assertNotNull($extraction);
        $this->assertSame(TextExtractor::FAILED, $extraction->status);
    }

    // ── One extraction per version ───────────────────────────────────────────

    public function test_re_reading_a_version_updates_its_row_rather_than_adding_another(): void
    {
        $document = $this->document('First reading', 'txt', 'text/plain');

        $this->extractor()->extractCurrent($document);
        $this->extractor()->extractCurrent($document);

        $this->assertSame(
            1,
            DB::table('document_extractions')->where('version_id', $document->current_version_id)->count(),
            'A pile of extractions for one version is a pile nobody can choose between.',
        );
    }

    // ── Searching by what a document says ────────────────────────────────────

    public function test_a_document_is_found_by_a_phrase_that_appears_only_inside_the_file(): void
    {
        // The point of the whole feature: nobody put "indemnity" in the title.
        $document = $this->document('The indemnity clause survives termination.', 'txt', 'text/plain', 'Agreement 2026');
        $this->extractor()->extractCurrent($document);

        $found = app(DocumentSearch::class)->query($this->user, ['q' => 'indemnity'])->pluck('title');

        $this->assertTrue($found->contains('Agreement 2026'));
    }

    public function test_an_old_versions_words_do_not_keep_matching_after_the_file_is_replaced(): void
    {
        /*
         * Otherwise a document surfaces for a phrase it no longer contains — which is worse than
         * not finding it, because the person opens it and cannot see why it matched.
         */
        $document = $this->document('Original wording mentions asbestos.', 'txt', 'text/plain', 'Method statement');
        $this->extractor()->extractCurrent($document);

        $newPath = 'extract/' . Str::random(12) . '.txt';
        Storage::disk('documents')->put($newPath, 'Revised wording mentions scaffolding.');

        $newVersion = DocumentVersion::create([
            'uuid' => (string) Str::uuid(), 'document_id' => $document->id,
            'version' => 2, 'major' => 2, 'minor' => 0,
            'disk' => 'documents', 'path' => $newPath,
            'original_name' => 'file.txt', 'stored_name' => basename($newPath),
            'extension' => 'txt', 'mime' => 'text/plain', 'size' => 36,
        ]);
        $document->forceFill(['current_version_id' => $newVersion->id, 'version_count' => 2])->save();

        $this->extractor()->extract($newVersion);

        $search = app(DocumentSearch::class);

        $this->assertTrue($search->query($this->user, ['q' => 'scaffolding'])->pluck('title')->contains('Method statement'));
        $this->assertFalse(
            $search->query($this->user, ['q' => 'asbestos'])->pluck('title')->contains('Method statement'),
            'The superseded version\'s text must not answer searches about the current document.',
        );
    }

    public function test_searching_still_matches_a_title_when_nothing_has_been_extracted(): void
    {
        // The content search is an addition, not a replacement. An installation that has not run
        // the backfill yet must not lose the search it already had.
        $this->document('', 'mp4', 'video/mp4', 'Site induction recording');

        $found = app(DocumentSearch::class)->query($this->user, ['q' => 'induction'])->pluck('title');

        $this->assertTrue($found->contains('Site induction recording'));
    }

    // ── Finding the ones nobody can search ───────────────────────────────────

    public function test_the_unreadable_listing_finds_scans_and_leaves_readable_documents_out(): void
    {
        /*
         * Without a screen for these, the only way to discover a document is unsearchable is to
         * search for a phrase you know is in it and get nothing back — at which point the search
         * looks broken rather than the document.
         */
        $readable = $this->document('Plenty of words here.', 'txt', 'text/plain', 'Readable');
        $this->extractor()->extractCurrent($readable);

        // A file whose bytes have gone: read, failed, and worth somebody's attention.
        $broken = $this->document('anything', 'txt', 'text/plain', 'Broken file');
        Storage::disk('documents')->delete($broken->currentVersion->path);
        $this->extractor()->extractCurrent($broken);

        $neverRead = $this->document('Not looked at yet.', 'txt', 'text/plain', 'Never read');

        $found = app(DocumentSearch::class)->query($this->user, ['unreadable' => true])->pluck('title');

        $this->assertTrue($found->contains('Broken file'));
        $this->assertTrue($found->contains('Never read'), 'A document nobody has read yet is also unsearchable.');
        $this->assertFalse($found->contains('Readable'));
    }

    public function test_replacing_an_unreadable_file_takes_it_off_the_list(): void
    {
        $document = $this->document('anything', 'txt', 'text/plain', 'Was a scan');
        Storage::disk('documents')->delete($document->currentVersion->path);
        $this->extractor()->extractCurrent($document);

        $this->assertTrue(
            app(DocumentSearch::class)->query($this->user, ['unreadable' => true])->pluck('title')->contains('Was a scan'),
        );

        $path = 'extract/' . Str::random(12) . '.txt';
        Storage::disk('documents')->put($path, 'Now it has real text in it.');

        $version = DocumentVersion::create([
            'uuid' => (string) Str::uuid(), 'document_id' => $document->id,
            'version' => 2, 'major' => 2, 'minor' => 0,
            'disk' => 'documents', 'path' => $path,
            'original_name' => 'file.txt', 'stored_name' => basename($path),
            'extension' => 'txt', 'mime' => 'text/plain', 'size' => 27,
        ]);
        $document->forceFill(['current_version_id' => $version->id, 'version_count' => 2])->save();
        $this->extractor()->extract($version);

        $this->assertFalse(
            app(DocumentSearch::class)->query($this->user, ['unreadable' => true])->pluck('title')->contains('Was a scan'),
            'The list is about the current version, so fixing a document has to remove it.',
        );
    }
}
