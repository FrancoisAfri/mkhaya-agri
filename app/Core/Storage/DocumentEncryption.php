<?php

namespace App\Core\Storage;

use RuntimeException;

/**
 * Encrypts file bytes on the way into the database and decrypts them on the way out.
 *
 * Every file in the platform is stored in Postgres rather than on the filesystem, which means
 * anyone holding a database dump — a backup, a replica, a restored snapshot on a laptop — holds the
 * documents. Encrypting at the application layer is what separates "has the database" from "has the
 * contracts", and it is the reason this exists rather than relying on disk-level encryption, which
 * protects a stolen drive and nothing else.
 *
 * Why not Laravel's Crypt facade: it base64-encodes and JSON-wraps its output, which would add
 * roughly 33% back onto every file — the exact overhead this storage change exists to remove. This
 * writes raw bytes instead, so a 10 MB PDF costs 10 MB plus a 32-byte header.
 *
 * The envelope is self-describing, so a file carries the information needed to decrypt it and no
 * lookup table has to stay in sync with the bytes:
 *
 *   "MKE1"  | key-id length | key id | IV (12) | GCM tag (16) | ciphertext
 *   4 bytes | 1 byte        | n      | 12      | 16           | rest
 *
 * GCM rather than CBC because it authenticates: a modified ciphertext fails to decrypt instead of
 * returning plausible garbage. For a document store that difference matters — silently serving
 * corrupted bytes as a signed contract is worse than an error.
 */
class DocumentEncryption
{
    /** Marks an envelope this class wrote, and pins the format version. */
    private const MAGIC = 'MKE1';

    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    /** Recorded against a row so a plain byte string is never mistaken for a decryption failure. */
    public const METHOD_NONE = 'none';
    public const METHOD_GCM = 'aes-256-gcm';

    /**
     * Whether new writes are encrypted.
     *
     * Off leaves existing encrypted files perfectly readable — decryption is driven by the envelope,
     * not by this flag. That asymmetry is deliberate: turning encryption off must never make a
     * document unreadable, or the switch becomes one nobody dares touch.
     *
     * Note that this deliberately does not also require a usable key. Being switched on with no key
     * configured is a misconfiguration, and the honest response is for the write to fail — see
     * currentKey(). Folding the key check in here would instead turn it into a silent fallback to
     * plaintext, which is the worst of the three outcomes: the setting reads as on, the operator
     * believes documents are protected, and nothing is.
     */
    public function enabled(): bool
    {
        return (bool) config('storage.encryption.enabled', true);
    }

    /**
     * Wraps plaintext bytes in an encrypted envelope.
     *
     * Returns the bytes unchanged when encryption is off, so callers do not branch; they record
     * `method()` alongside and let the read side work it out.
     */
    public function encrypt(string $plaintext): string
    {
        if (! $this->enabled()) {
            return $plaintext;
        }

        [$keyId, $key] = $this->currentKey();

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt file contents.');
        }

        return self::MAGIC . chr(strlen($keyId)) . $keyId . $iv . $tag . $ciphertext;
    }

    /**
     * Unwraps an envelope, or returns the input untouched if it is not one.
     *
     * The magic-prefix check is what lets encrypted and plain rows coexist in the same table while a
     * backfill runs, and what lets a file written before encryption was switched on keep working
     * forever after.
     */
    public function decrypt(string $payload): string
    {
        if (! $this->looksEncrypted($payload)) {
            return $payload;
        }

        $keyIdLength = ord($payload[4]);
        $offset = 5 + $keyIdLength;

        $keyId = substr($payload, 5, $keyIdLength);
        $iv = substr($payload, $offset, self::IV_LENGTH);
        $tag = substr($payload, $offset + self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($payload, $offset + self::IV_LENGTH + self::TAG_LENGTH);

        $key = $this->keyring()[$keyId] ?? null;

        if ($key === null) {
            // Naming the key rather than the failure: an operator seeing this has rotated or dropped
            // a key, and the fix is to put it back in the keyring, not to debug the file.
            throw new RuntimeException("Unable to decrypt file: encryption key [{$keyId}] is not configured.");
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt file: the contents failed their integrity check.');
        }

        return $plaintext;
    }

    /** True when the bytes carry our envelope header. */
    public function looksEncrypted(string $payload): bool
    {
        return strlen($payload) > 5 + self::IV_LENGTH + self::TAG_LENGTH
            && str_starts_with($payload, self::MAGIC);
    }

    /** What to record in the row's `encryption` column for bytes written now. */
    public function method(): string
    {
        return $this->enabled() ? self::METHOD_GCM : self::METHOD_NONE;
    }

    /**
     * The key used for new writes.
     *
     * @return array{0:string,1:string} id and raw 32-byte key
     */
    private function currentKey(): array
    {
        $keyring = $this->keyring();
        $id = (string) config('storage.encryption.key_id', 'app');

        if (! isset($keyring[$id])) {
            /*
             * Encryption is switched on and there is no key to do it with.
             *
             * Refusing the write is the point. Falling back to another key would encrypt under one
             * the operator did not choose, and falling back to plaintext would leave a store that
             * reports itself as encrypted while quietly filling up with readable documents — a
             * thing nobody discovers until it matters.
             */
            throw new RuntimeException(
                "Storage encryption is enabled but key [{$id}] is not configured. Set "
                . 'STORAGE_ENCRYPTION_KEY (or APP_KEY), or set STORAGE_ENCRYPTION_ENABLED=false.'
            );
        }

        return [$id, $keyring[$id]];
    }

    /**
     * Every key we can decrypt with, by id.
     *
     * Rotation works by adding a new key, pointing `key_id` at it, and leaving the old one here:
     * files already written stay readable, and new ones use the new key. Nothing has to be
     * re-encrypted at the moment of rotation, which is what makes rotating it realistic.
     *
     * @return array<string,string>
     */
    private function keyring(): array
    {
        $keys = [];

        foreach ((array) config('storage.encryption.keys', []) as $id => $material) {
            $key = $this->normaliseKey((string) $material);
            if ($key !== null) {
                $keys[(string) $id] = $key;
            }
        }

        return $keys;
    }

    /**
     * Turns configured key material into 32 raw bytes.
     *
     * Accepts Laravel's `base64:` form so an installation can point at APP_KEY without a second
     * secret to manage. Anything else is hashed to length rather than truncated, so a short or
     * oddly-sized key is still a full-strength AES key instead of a quietly weak one.
     */
    private function normaliseKey(string $material): ?string
    {
        if (trim($material) === '') {
            return null;
        }

        if (str_starts_with($material, 'base64:')) {
            $decoded = base64_decode(substr($material, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
            $material = $decoded === false ? $material : $decoded;
        }

        return strlen($material) === 32 ? $material : hash('sha256', $material, true);
    }
}
