<?php

namespace Tests\Feature\Storage;

use App\Core\Storage\Concerns\StoresFileBytes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The shared trait every module's file-owning model adopts.
 *
 * Around twenty tables each grew their own base64 blob column, and with them roughly forty-eight
 * places that decode one and hand it to a browser. This is the single path that replaces them, so
 * the properties below are the ones the whole migration rests on — particularly that a row which
 * has not been migrated yet still reads, which is what allows one module at a time.
 */
class StoresFileBytesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);
    }

    /** A stand-in for the real models, so this tests the trait rather than any one module. */
    private function subject(?string $path = null, ?string $blob = null): Model
    {
        return new class($path, $blob) extends Model
        {
            use StoresFileBytes;

            public $timestamps = false;
            protected $table = 'tender_documents';

            public function __construct($path = null, $blob = null)
            {
                parent::__construct();
                $this->storage_path = $path;
                $this->data = $blob;
            }

            protected function fileBlobColumn(): ?string
            {
                return 'data';
            }

            protected function fileFolder(): string
            {
                return 'tests/bytes';
            }
        };
    }

    public function test_a_write_puts_the_bytes_on_the_disk_and_records_the_path(): void
    {
        $model = $this->subject();
        $path = $model->writeBytes('%PDF-1.4 board pack', 'pdf');

        try {
            $this->assertSame($path, $model->storage_path);
            $this->assertTrue(Storage::disk('documents')->exists($path));
            $this->assertSame('%PDF-1.4 board pack', $model->bytes());
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    public function test_the_path_is_generated_never_taken_from_the_caller(): void
    {
        $model = $this->subject();
        $path = $model->writeBytes('x', '../../../etc/passwd');

        try {
            // The extension is caller-supplied and is the one part of a path an attacker controls.
            $this->assertStringNotContainsString('..', $path);
            $this->assertStringNotContainsString('/etc/', $path);
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    public function test_a_row_that_has_not_been_migrated_still_reads_from_its_blob(): void
    {
        // The state of every row in a module that has not been switched yet. This fallback is the
        // only reason the migration can proceed one module at a time.
        $this->assertSame('legacy bytes', $this->subject(null, base64_encode('legacy bytes'))->bytes());
    }

    public function test_a_row_holding_both_prefers_the_disk(): void
    {
        $model = $this->subject();
        $path = $model->writeBytes('current bytes', 'txt');
        $model->data = base64_encode('stale bytes');

        try {
            // The state between migrating and pruning. Preferring the path makes the prune a no-op
            // for readers.
            $this->assertSame('current bytes', $model->bytes());
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    public function test_a_dangling_pointer_falls_back_rather_than_losing_the_file(): void
    {
        $model = $this->subject('tests/bytes/does-not-exist.pdf', base64_encode('still here'));

        // A missing file is a reason to try the other copy, not a reason to fail while one exists.
        $this->assertSame('still here', $model->bytes());
    }

    public function test_a_blob_that_was_written_as_raw_binary_is_not_mangled(): void
    {
        // Some rows were written by code that skipped the base64 encode. A strict decode fails on
        // those, and returning null would lose a file that is perfectly intact.
        $raw = "\x00\x01\xFF binary that is not valid base64 \xC3";

        $this->assertSame($raw, $this->subject(null, $raw)->bytes());
    }

    public function test_nothing_stored_reads_as_nothing(): void
    {
        $this->assertNull($this->subject()->bytes());
        $this->assertFalse($this->subject()->hasBytes());
        $this->assertTrue($this->subject(null, base64_encode('x'))->hasBytes());
    }

    public function test_binary_survives_the_round_trip(): void
    {
        $model = $this->subject();
        $binary = random_bytes(4096);
        $path = $model->writeBytes($binary, 'bin');

        try {
            // Goes through the encrypted, bytea-backed adapter, where a naive binding would
            // silently corrupt it.
            $this->assertSame($binary, $model->bytes());
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    public function test_deleting_removes_the_file(): void
    {
        $model = $this->subject();
        $path = $model->writeBytes('temporary', 'txt');

        $model->deleteBytes();

        $this->assertFalse(Storage::disk('documents')->exists($path));
    }

    public function test_a_json_envelope_blob_is_read_correctly(): void
    {
        // Several modules stored the whole array returned by the shared FileStorage trait rather
        // than just its bytes. A plain base64 decode of that returns the JSON, not the document.
        $envelope = json_encode([
            'data' => base64_encode('%PDF-1.4 invoice'),
            'mime' => 'application/pdf',
            'original_name' => 'invoice.pdf',
        ]);

        $this->assertSame('%PDF-1.4 invoice', $this->subject(null, $envelope)->bytes());
    }

    public function test_a_document_that_merely_starts_with_a_brace_is_not_mistaken_for_an_envelope(): void
    {
        // A JSON file is a perfectly ordinary upload, and it has no `data` key of ours.
        $json = base64_encode('{"invoice_number": 42}');

        $this->assertSame('{"invoice_number": 42}', $this->subject(null, $json)->bytes());
    }

    public function test_a_column_holding_a_real_disk_path_is_not_served_as_file_contents(): void
    {
        // Three tables call their blob column `path`, and depending on when the row was written it
        // holds either the JSON envelope or an actual path on a Laravel disk. A permissive decode
        // hands the path back as though it were the file, and the user downloads a document
        // containing the words "policies/abc.pdf". Those models override legacyBytes() to return
        // only the envelope; this asserts the trait's own permissive behaviour is what they are
        // overriding, so the override is not quietly redundant.
        $this->assertSame('policies/abc.pdf', $this->subject(null, 'policies/abc.pdf')->bytes());
    }
}
