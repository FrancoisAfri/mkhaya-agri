<?php

namespace Tests\Feature\Storage;

use App\Core\Storage\DocumentEncryption;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Files are stored in Postgres, so a database dump is a document dump unless the bytes are
 * encrypted before they get there. These tests cover the properties that make that true and keep it
 * true while plaintext and encrypted rows share a table.
 */
class DocumentEncryptionTest extends TestCase
{
    use DatabaseTransactions;

    private function cipher(): DocumentEncryption
    {
        config([
            'storage.encryption.enabled' => true,
            'storage.encryption.key_id'  => 'app',
            'storage.encryption.keys'    => ['app' => str_repeat('k', 32)],
        ]);

        return new DocumentEncryption();
    }

    // ── The envelope ─────────────────────────────────────────────────────────

    public function test_a_document_survives_a_round_trip_byte_for_byte(): void
    {
        $cipher = $this->cipher();
        // Binary with NULs and high bytes — a PDF is not text, and anything that treats it as text
        // corrupts it somewhere in the middle where nobody looks.
        $plaintext = "%PDF-1.4\x00\xFF\xC3\xA9" . random_bytes(4096) . "\x00%%EOF";

        $this->assertSame($plaintext, $cipher->decrypt($cipher->encrypt($plaintext)));
    }

    public function test_the_stored_bytes_do_not_contain_the_document(): void
    {
        $cipher = $this->cipher();

        $stored = $cipher->encrypt('Board resolution: the merger is approved.');

        // The whole point: someone reading the table sees nothing.
        $this->assertStringNotContainsString('merger', $stored);
        $this->assertStringNotContainsString('Board', $stored);
    }

    public function test_the_same_document_encrypts_differently_every_time(): void
    {
        $cipher = $this->cipher();

        // A fresh IV per write. Without it, identical files produce identical ciphertext, and the
        // table leaks which employees hold the same document without decrypting anything.
        $this->assertNotSame($cipher->encrypt('same input'), $cipher->encrypt('same input'));
    }

    public function test_overhead_is_a_fixed_header_not_a_percentage(): void
    {
        $cipher = $this->cipher();
        $plaintext = random_bytes(100_000);

        // The reason for raw AES-GCM rather than the Crypt facade, which base64s its output and
        // would put back the ~33% this storage change exists to remove.
        $this->assertLessThan(64, strlen($cipher->encrypt($plaintext)) - strlen($plaintext));
    }

    // ── Failing safely ───────────────────────────────────────────────────────

    public function test_tampered_contents_are_refused_rather_than_returned(): void
    {
        $cipher = $this->cipher();
        $stored = $cipher->encrypt('Amount due: R 10 000.00');

        // Flip a byte in the ciphertext body.
        $stored[strlen($stored) - 1] = chr(ord($stored[strlen($stored) - 1]) ^ 0xFF);

        // GCM authenticates, so this fails loudly. A cipher without a tag would hand back
        // plausible-looking rubbish, and a signed contract would be served altered and unremarked.
        $this->expectExceptionMessageMatches('/integrity/');
        $cipher->decrypt($stored);
    }

    public function test_a_missing_key_names_the_key_instead_of_failing_vaguely(): void
    {
        $stored = $this->cipher()->encrypt('contract');

        // The keyring no longer holds the key this file was written under.
        config(['storage.encryption.keys' => ['other' => str_repeat('z', 32)]]);

        $this->expectExceptionMessageMatches('/\[app\]/');
        (new DocumentEncryption())->decrypt($stored);
    }

    // ── Living alongside plaintext ───────────────────────────────────────────

    public function test_a_file_written_before_encryption_still_opens(): void
    {
        // Every existing row in the platform is this case. If it ever stops working, the oldest
        // documents in the system are the ones that disappear.
        $this->assertSame('an old plaintext file', $this->cipher()->decrypt('an old plaintext file'));
    }

    public function test_turning_encryption_off_does_not_lock_existing_files(): void
    {
        $stored = $this->cipher()->encrypt('still needed');

        config(['storage.encryption.enabled' => false]);
        $cipher = new DocumentEncryption();

        // Decryption follows the envelope, not the setting — otherwise the switch is one nobody can
        // ever safely touch.
        $this->assertSame('still needed', $cipher->decrypt($stored));
        $this->assertSame('written plainly', $cipher->encrypt('written plainly'));
        $this->assertSame(DocumentEncryption::METHOD_NONE, $cipher->method());
    }

    public function test_a_rotated_key_still_opens_what_the_old_one_wrote(): void
    {
        $old = $this->cipher()->encrypt('signed last year');

        // Rotation: a new key for new writes, the old one kept for reading.
        config([
            'storage.encryption.key_id' => 'v2',
            'storage.encryption.keys'   => ['app' => str_repeat('k', 32), 'v2' => str_repeat('n', 32)],
        ]);
        $cipher = new DocumentEncryption();

        $this->assertSame('signed last year', $cipher->decrypt($old));
        $this->assertSame('new', $cipher->decrypt($cipher->encrypt('new')));
    }

    // ── Through the real storage layer ───────────────────────────────────────

    public function test_a_file_written_through_the_disk_is_encrypted_in_the_table(): void
    {
        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $plaintext = "%PDF-1.4\x00 confidential settlement " . random_bytes(512);
        $path = 'tests/encryption-' . uniqid() . '.pdf';

        Storage::disk('documents')->put($path, $plaintext);

        try {
            $this->assertSame($plaintext, Storage::disk('documents')->get($path));

            $row = DB::table('tenant_files')->where('path', $path)->first();
            $raw = is_resource($row->contents) ? stream_get_contents($row->contents) : (string) $row->contents;

            $this->assertSame('aes-256-gcm', $row->encryption);
            $this->assertStringNotContainsString('confidential settlement', $raw);
            // Quota is metered on what the customer uploaded, not on the envelope.
            $this->assertSame(strlen($plaintext), (int) $row->size);
            $this->assertSame(hash('sha256', $plaintext), $row->content_hash);
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    public function test_binary_survives_the_bytea_column(): void
    {
        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        // Binding binary through the query builder succeeds and stores something else — the write
        // path uses PDO LOB binding for exactly this reason, and this is the test that would catch
        // anyone quietly simplifying it back.
        $plaintext = "\x00\x01\xFF" . random_bytes(8192) . "\x00'\";--";
        $path = 'tests/binary-' . uniqid() . '.bin';

        Storage::disk('documents')->put($path, $plaintext);

        try {
            $this->assertSame($plaintext, Storage::disk('documents')->get($path));
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    public function test_overwriting_a_file_replaces_its_bytes_and_its_hash(): void
    {
        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $path = 'tests/replace-' . uniqid() . '.txt';
        Storage::disk('documents')->put($path, 'version one');
        Storage::disk('documents')->put($path, 'version two');

        try {
            $this->assertSame('version two', Storage::disk('documents')->get($path));
            $this->assertSame(1, DB::table('tenant_files')->where('path', $path)->count());
            $this->assertSame(
                hash('sha256', 'version two'),
                DB::table('tenant_files')->where('path', $path)->value('content_hash')
            );
        } finally {
            Storage::disk('documents')->delete($path);
        }
    }

    public function test_being_switched_on_with_no_key_fails_the_write_instead_of_storing_plaintext(): void
    {
        config([
            'storage.encryption.enabled' => true,
            'storage.encryption.key_id'  => 'app',
            'storage.encryption.keys'    => [],
        ]);

        // The dangerous middle outcome: the setting reads as on, the operator believes documents
        // are protected, and every one of them goes in readable. Refusing the write is the only
        // honest answer.
        $this->expectExceptionMessageMatches('/enabled but key \[app\] is not configured/');
        (new DocumentEncryption())->encrypt('confidential');
    }
}
