<?php

namespace App\Core\Storage;

use App\Modules\Tenancy\Services\StorageQuota;
use Illuminate\Support\Facades\DB;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;

/**
 * Flysystem adapter that stores files in the `tenant_files` DB table. Rows are tenant-scoped by
 * Postgres RLS + the company_id session-var default, so reads/writes are automatically isolated per
 * company and per-tenant usage = SUM(size).
 *
 * This lets the whole app keep using the normal Storage:: API while every file lands in the
 * database instead of on disk.
 *
 * Bytes are stored raw in a `bytea` column and encrypted by {@see DocumentEncryption}. They were
 * previously base64 text, which cost about 33% on every file — real money in table size, backups and
 * replicas — and left a database dump readable as documents.
 *
 * `size` stays the true plaintext length, because it meters the tenant's quota: a customer should be
 * billed for the 10 MB they uploaded, not for whatever the envelope happened to add.
 *
 * One trap is worth naming, because it costs nothing to get wrong and everything to discover late:
 * binding a binary string to a bytea column through the normal query builder **silently corrupts
 * it**. The insert succeeds, and the bytes come back different. Every write here therefore goes
 * through PDO's LOB binding, which is the only form that round-trips, and reads come back as a
 * stream resource rather than a string.
 */
class DatabaseFilesystemAdapter implements FlysystemAdapter
{
    public function __construct(private string $disk = 'local')
    {
    }

    private function row(string $path): ?object
    {
        return DB::table('tenant_files')->where('disk', $this->disk)->where('path', $path)->first();
    }

    public function fileExists(string $path): bool
    {
        return DB::table('tenant_files')->where('disk', $this->disk)->where('path', $path)->exists();
    }

    public function directoryExists(string $path): bool
    {
        $prefix = rtrim($path, '/') . '/';

        return DB::table('tenant_files')->where('disk', $this->disk)->where('path', 'like', $prefix . '%')->exists();
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $size = strlen($contents);

        // Enforce the tenant's storage quota on the way in. Measured on the plaintext, so the
        // encryption envelope is never billed to the customer.
        try {
            app(StorageQuota::class)->guardWrite($size, $path);
        } catch (\Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage());
        }

        $cipher = app(DocumentEncryption::class);

        try {
            $stored = $cipher->encrypt($contents);
        } catch (\Throwable $e) {
            // Better to refuse the upload than to store a document in the clear after being told
            // to encrypt it — a silent fallback is how a store ends up half-protected.
            throw UnableToWriteFile::atLocation($path, $e->getMessage());
        }

        $columns = [
            'name'         => basename($path),
            // Sniffed from the plaintext: ciphertext has no recognisable type.
            'mime'         => $this->guessMime($path, $contents),
            'size'         => $size,
            'visibility'   => (string) $config->get('visibility', 'private'),
            'encryption'   => $cipher->method(),
            // Of the plaintext, so a restored file can be checked against what was stored
            // regardless of which key encrypted it.
            'content_hash' => hash('sha256', $contents),
            'created_by'   => auth()->id(),
            'updated_at'   => now(),
        ];

        $this->putBytes($path, $stored, $columns);
    }

    /**
     * Writes the row, binding the bytes as a LOB.
     *
     * The query builder cannot do this — see the class comment — so the statement is assembled by
     * hand. Column names come from this file only, never from a caller, and every value is bound.
     *
     * `company_id` is deliberately left out of the insert so the column's default fills it from the
     * tenant session variable, which is the same thing RLS reads. Setting it here would let a
     * mismatch exist between the row's owner and the policy that guards it.
     *
     * @param  array<string,mixed>  $columns
     */
    private function putBytes(string $path, string $bytes, array $columns): void
    {
        $pdo = DB::connection()->getPdo();
        $exists = $this->fileExists($path);

        if ($exists) {
            $assignments = implode(', ', array_map(fn ($c) => "\"{$c}\" = ?", array_keys($columns)));
            $sql = "update tenant_files set {$assignments}, contents = ? where disk = ? and path = ?";
            $tail = [$this->disk, $path];
        } else {
            $columns['disk'] = $this->disk;
            $columns['path'] = $path;
            $columns['created_at'] = now();

            $names = implode(', ', array_map(fn ($c) => "\"{$c}\"", array_keys($columns)));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = "insert into tenant_files ({$names}, contents) values ({$placeholders}, ?)";
            $tail = [];
        }

        $statement = $pdo->prepare($sql);

        $position = 1;
        foreach ($columns as $value) {
            $statement->bindValue($position++, $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value);
        }

        // The one binding that has to be a LOB. A stream rather than a string keeps the driver from
        // treating it as text and mangling the high bytes.
        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, $bytes);
        rewind($handle);
        $statement->bindParam($position++, $handle, \PDO::PARAM_LOB);

        foreach ($tail as $value) {
            $statement->bindValue($position++, $value);
        }

        try {
            $statement->execute();
        } finally {
            // PDO binds the LOB by reference and replaces the variable with the read bytes while
            // executing, so by this point $handle is usually no longer a stream.
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, stream_get_contents($contents) ?: '', $config);
    }

    public function read(string $path): string
    {
        $row = $this->row($path);
        if (! $row) {
            throw UnableToReadFile::fromLocation($path, 'Not found in database storage.');
        }

        // Postgres hands bytea back as a stream, so this is not the redundant branch it looks like.
        $stored = is_resource($row->contents) ? stream_get_contents($row->contents) : (string) $row->contents;

        try {
            return app(DocumentEncryption::class)->decrypt($stored);
        } catch (\Throwable $e) {
            // Flysystem's own failure type, so callers handle a missing key the same way they
            // already handle a missing file instead of seeing a raw crypto exception.
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        DB::table('tenant_files')->where('disk', $this->disk)->where('path', $path)->delete();
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = rtrim($path, '/') . '/';
        DB::table('tenant_files')->where('disk', $this->disk)->where('path', 'like', $prefix . '%')->delete();
    }

    public function createDirectory(string $path, Config $config): void
    {
        // No directories in DB storage — a no-op keeps Storage::makeDirectory happy.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        DB::table('tenant_files')->where('disk', $this->disk)->where('path', $path)->update(['visibility' => $visibility]);
    }

    public function visibility(string $path): FileAttributes
    {
        $row = $this->row($path) ?? throw UnableToRetrieveMetadata::visibility($path);

        return new FileAttributes($path, visibility: $row->visibility);
    }

    public function mimeType(string $path): FileAttributes
    {
        $row = $this->row($path) ?? throw UnableToRetrieveMetadata::mimeType($path);

        return new FileAttributes($path, mimeType: $row->mime);
    }

    public function lastModified(string $path): FileAttributes
    {
        $row = $this->row($path) ?? throw UnableToRetrieveMetadata::lastModified($path);

        return new FileAttributes($path, lastModified: strtotime((string) $row->updated_at) ?: null);
    }

    public function fileSize(string $path): FileAttributes
    {
        $row = $this->row($path) ?? throw UnableToRetrieveMetadata::fileSize($path);

        return new FileAttributes($path, fileSize: (int) $row->size);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $path === '' ? '' : rtrim($path, '/') . '/';
        $rows = DB::table('tenant_files')->where('disk', $this->disk)
            ->when($prefix !== '', fn ($q) => $q->where('path', 'like', $prefix . '%'))
            ->get(['path', 'size', 'mime', 'updated_at']);
        foreach ($rows as $r) {
            yield new FileAttributes($r->path, fileSize: (int) $r->size, lastModified: strtotime((string) $r->updated_at) ?: null, mimeType: $r->mime);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        DB::table('tenant_files')->where('disk', $this->disk)->where('path', $source)
            ->update(['path' => $destination, 'name' => basename($destination), 'updated_at' => now()]);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->write($destination, $this->read($source), $config);
    }

    /**
     * Public URL for a stored file — points at the /dbfiles serving route, which
     * streams the blob (RLS-scoped to the tenant). Laravel's FilesystemAdapter
     * calls this before falling back to the disk's configured `url`.
     */
    public function getUrl(string $path): string
    {
        return url('/dbfiles/' . $this->disk . '/' . ltrim($path, '/'));
    }

    private function guessMime(string $path, string $contents): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = [
            'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'csv' => 'text/csv',
            'txt' => 'text/plain', 'json' => 'application/json', 'zip' => 'application/zip',
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        $f = new \finfo(FILEINFO_MIME_TYPE);

        return $f->buffer($contents) ?: null;
    }
}
