<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Core\User\Trait\FileStorage;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Every file SHEQ stores goes through here (SHEQ-OFF-7/8, SHEQ-SEC-4).
 *
 * The encoding, compression, size limit and permitted types are the platform's, borrowed wholesale
 * from `FileStorage` — the same trait User profiles and Contract Management use. SHEQ adds only what
 * the domain needs on top:
 *
 * - **EXIF survives compression.** The trait recompresses an image to 1920px, which strips EXIF.
 *   The capture time and GPS are read *before* that and stored as columns, because when and where a
 *   photograph was taken is what makes it evidence rather than a picture. Losing it quietly during
 *   compression is how photo evidence becomes worthless without anyone noticing.
 * - **Upload state is explicit.** A row exists from the moment a device announces a file, so a
 *   record with media still in flight can say so (SHEQ-OFF-9: never fake success).
 * - **Chunks, so an upload survives an app kill** (SHEQ-OFF-7). A device on a bad line uploads what
 *   it can and resumes; nothing is assembled until every part is present.
 */
class FileService
{
    use FileStorage;

    /** Subjects a file may attach to. Anything else is a caller mistake worth failing on. */
    /**
     * What a SHEQ file may attach to.
     *
     * Public so a request can validate against the same list the service enforces. Validated at
     * the edge *and* asserted in the service: the edge turns a wrong subject into a field error the
     * device can act on, and the assertion is what makes it impossible to get past by another
     * route.
     *
     * @return list<string>
     */
    public static function subjects(): array
    {
        return self::SUBJECTS;
    }

    /*
     * CONTRACTOR_WORKER was missing, and the add-worker form has had a photograph field the whole
     * time. Every attempt to use it threw "'CONTRACTOR_WORKER' is not something a SHEQ file can
     * attach to" out of `storeWorker` as a 500, so no worker photograph was ever stored — which is
     * why the avatar on the contractor's crew list and the face on the access card were always
     * blank. The field, the avatar and the card were all reading a file nothing could write.
     */
    private const SUBJECTS = [
        'INCIDENT', 'HAZARD', 'INSPECTION', 'CONTRACTOR_DOCUMENT', 'CONTRACTOR_WORKER',
        'DOCUMENT_VERSION', 'COMPETENCY', 'PERMIT', 'ACTION', 'RISK_ASSESSMENT',
        /*
         * OBS-2 asks for a photograph, and a photograph is most of the value of a behavioural
         * observation: "not clipped on" is an assertion, and a picture of somebody not clipped
         * on is a conversation that does not need to be had twice.
         */
        'OBSERVATION',
        /*
         * MOC-2 attaches the evidence that a required control was completed — the revised
         * drawing, the signed-off procedure, the retraining register.
         */
        'CHANGE', 'CHANGE_IMPACT',
    ];

    /**
     * Store a file in one request — the browser path.
     *
     * @param  array{subject_type:string, subject_id:int, category?:string, caption?:string, client_id?:string}  $context
     * @return array<string,mixed>
     */
    public function store(int $companyId, UploadedFile $file, array $context, ?int $userId = null): array
    {
        $this->assertSubject($context['subject_type'] ?? '');

        $clientId = $context['client_id'] ?? (string) Str::uuid();

        // SHEQ-OFF-2 again: a retried upload is the same file, not a second copy of it.
        $existing = DB::table('sheq_files')
            ->where('company_id', $companyId)->where('client_id', $clientId)
            ->first(['uuid', 'upload_status', 'original_name', 'size']);

        if ($existing !== null && $existing->upload_status === 'STORED') {
            return (array) $existing + ['replayed' => true];
        }

        // Read before the trait recompresses: imagejpeg() drops every EXIF segment.
        $exif = $this->exifFrom($file);

        // Throws on an oversized or disallowed file — the trait's own rules, unchanged.
        $stored = $this->saveFileToDatabase($file);

        $id = DB::table('sheq_files')->insertGetId([
            'uuid' => (string) $stored['uuid'],
            'company_id' => $companyId,
            'subject_type' => strtoupper($context['subject_type']),
            'subject_id' => (int) $context['subject_id'],
            'category' => strtoupper($context['category'] ?? 'EVIDENCE'),
            'caption' => $context['caption'] ?? null,
            'original_name' => $stored['original_name'],
            'mime' => $stored['mime'],
            'type' => $stored['type'],
            'size' => $stored['size'],
            // Bytes to the shared disk, where encryption at rest and the tenant quota apply. The
            // hash is of the plaintext, so it stays comparable regardless of how it is stored.
            'storage_path' => $this->putBytes(base64_decode($stored['data']), (string) $stored['original_name']),
            'sha256' => hash('sha256', (string) base64_decode($stored['data'])),
            'captured_at' => $exif['captured_at'],
            'captured_lat' => $exif['lat'],
            'captured_lng' => $exif['lng'],
            'exif' => $exif['raw'] === null ? null : json_encode($exif['raw']),
            'upload_status' => 'STORED',
            'stored_at' => Timestamps::utc(Carbon::now()),
            'client_id' => $clientId,
            'device_id' => $context['device_id'] ?? null,
            'uploaded_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id, 'uuid' => $stored['uuid'], 'original_name' => $stored['original_name'],
            'size' => $stored['size'], 'type' => $stored['type'], 'status' => 'STORED',
            'captured_at' => $exif['captured_at']?->toIso8601String(),
        ];
    }

    /**
     * Announce a chunked upload (SHEQ-OFF-7). The row exists immediately, so the parent record can
     * show "media pending" from the first moment rather than looking complete.
     */
    public function beginUpload(int $companyId, array $context, int $chunksExpected, ?int $userId = null): array
    {
        $this->assertSubject($context['subject_type'] ?? '');

        $clientId = $context['client_id'] ?? (string) Str::uuid();

        $existing = DB::table('sheq_files')
            ->where('company_id', $companyId)->where('client_id', $clientId)->first();

        if ($existing !== null) {
            // Resuming: tell the device exactly which parts are already here so it sends the rest.
            return [
                'uuid' => $existing->uuid,
                'status' => $existing->upload_status,
                'received' => DB::table('sheq_file_chunks')
                    ->where('file_id', $existing->id)->pluck('sequence')->all(),
                'resumed' => true,
            ];
        }

        $uuid = (string) Str::uuid();

        DB::table('sheq_files')->insert([
            'uuid' => $uuid, 'company_id' => $companyId,
            'subject_type' => strtoupper($context['subject_type']),
            'subject_id' => (int) $context['subject_id'],
            'category' => strtoupper($context['category'] ?? 'EVIDENCE'),
            'caption' => $context['caption'] ?? null,
            'original_name' => $context['original_name'] ?? 'upload',
            'mime' => $context['mime'] ?? null,
            'type' => str_starts_with((string) ($context['mime'] ?? ''), 'image/') ? 'image' : 'document',
            'upload_status' => 'PENDING',
            'chunks_expected' => $chunksExpected,
            'captured_at' => isset($context['captured_at'])
                ? Timestamps::utc(Carbon::parse($context['captured_at'])) : null,
            'captured_lat' => $context['captured_lat'] ?? null,
            'captured_lng' => $context['captured_lng'] ?? null,
            'client_id' => $clientId,
            'device_id' => $context['device_id'] ?? null,
            'uploaded_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['uuid' => $uuid, 'status' => 'PENDING', 'received' => [], 'resumed' => false];
    }

    /** Accept one part. Out-of-order and repeated parts are both fine — that is the point. */
    public function putChunk(int $companyId, string $fileUuid, int $sequence, string $base64): array
    {
        $file = $this->find($companyId, $fileUuid);

        if ($file->upload_status === 'STORED') {
            return ['uuid' => $fileUuid, 'status' => 'STORED', 'note' => 'Already complete.'];
        }

        DB::table('sheq_file_chunks')->updateOrInsert(
            ['file_id' => $file->id, 'sequence' => $sequence],
            [
                'company_id' => $companyId, 'data' => $base64,
                'size' => strlen(base64_decode($base64)),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        $received = DB::table('sheq_file_chunks')->where('file_id', $file->id)->count();

        DB::table('sheq_files')->where('id', $file->id)
            ->update(['chunks_received' => $received, 'updated_at' => now()]);

        // Assembled only when every part is present. A gap leaves it PENDING, which is the honest
        // state — a partial file that reported itself stored would be corrupt evidence.
        if ($file->chunks_expected !== null && $received >= (int) $file->chunks_expected) {
            return $this->assemble($companyId, $file->id, $fileUuid);
        }

        return [
            'uuid' => $fileUuid, 'status' => 'PENDING',
            'received' => $received, 'expected' => (int) $file->chunks_expected,
        ];
    }

    /** @return array<string,mixed> */
    private function assemble(int $companyId, int $fileId, string $fileUuid): array
    {
        return DB::transaction(function () use ($fileId, $fileUuid) {
            $parts = DB::table('sheq_file_chunks')->where('file_id', $fileId)
                ->orderBy('sequence')->pluck('data');

            $binary = '';

            foreach ($parts as $part) {
                $binary .= base64_decode((string) $part);
            }

            DB::table('sheq_files')->where('id', $fileId)->update([
                'storage_path' => $this->putBytes($binary, (string) $fileUuid),
                'size' => strlen($binary),
                'sha256' => hash('sha256', $binary),
                'upload_status' => 'STORED',
                'stored_at' => Timestamps::utc(Carbon::now()),
                'updated_at' => now(),
            ]);

            // The parts have done their job; keeping them doubles the storage for nothing.
            DB::table('sheq_file_chunks')->where('file_id', $fileId)->delete();

            return ['uuid' => $fileUuid, 'status' => 'STORED', 'size' => strlen($binary)];
        });
    }

    /**
     * Files attached to a record, without their bytes.
     *
     * The `data` column is deliberately not selected: a list of six photographs would otherwise be
     * several megabytes of base64 in an Inertia payload for a page that only needs their names.
     *
     * @return list<array<string,mixed>>
     */
    public function forSubject(int $companyId, string $subjectType, int $subjectId): array
    {
        return DB::table('sheq_files')
            ->where('company_id', $companyId)
            ->where('subject_type', strtoupper($subjectType))
            ->where('subject_id', $subjectId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get([
                'uuid', 'original_name', 'mime', 'type', 'size', 'category', 'caption',
                'upload_status', 'chunks_received', 'chunks_expected',
                'captured_at', 'captured_lat', 'captured_lng', 'created_at',
            ])->map(fn ($f) => (array) $f)->all();
    }

    /**
     * SHEQ-OFF-7: a record whose evidence is mandatory must not close while media is still in
     * flight. Answered here rather than in each caller so every closure path asks the same question.
     */
    public function hasPendingMedia(int $companyId, string $subjectType, int $subjectId): bool
    {
        return DB::table('sheq_files')
            ->where('company_id', $companyId)
            ->where('subject_type', strtoupper($subjectType))
            ->where('subject_id', $subjectId)
            ->whereNull('deleted_at')
            ->where('upload_status', 'PENDING')
            ->exists();
    }

    /** The bytes, for a download response. Tenant-checked before anything is decoded. */
    public function download(int $companyId, string $fileUuid): Response
    {
        $file = $this->find($companyId, $fileUuid);

        if ($file->upload_status !== 'STORED') {
            throw new RuntimeException('That file has not finished uploading.');
        }

        $bytes = $this->readBytes($file);

        if ($bytes === null) {
            throw new RuntimeException('That file is no longer available.');
        }

        return $this->downloadResponse(base64_encode($bytes), $file->mime ?? 'application/octet-stream',
            $file->original_name);
    }

    /** Inline display payload for an image (data URI), via the trait. */
    public function display(int $companyId, string $fileUuid): array
    {
        $file = $this->find($companyId, $fileUuid);

        if ($file->upload_status !== 'STORED') {
            throw new RuntimeException('That file has not finished uploading.');
        }

        $bytes = $this->readBytes($file);

        if ($bytes === null) {
            throw new RuntimeException('That file is no longer available.');
        }

        $shown = $this->getFileForDisplay(base64_encode($bytes), $file->mime ?? 'application/octet-stream',
            $file->type ?? 'document');

        return is_array($shown) ? $shown + ['name' => $file->original_name] : ['url' => $shown];
    }

    private function find(int $companyId, string $uuid): object
    {
        $file = DB::table('sheq_files')
            ->where('company_id', $companyId)->where('uuid', $uuid)
            ->whereNull('deleted_at')->first();

        if ($file === null) {
            // 404 rather than 403: confirming a file exists in another tenant is itself a leak.
            throw new RuntimeException('File not found.');
        }

        return $file;
    }

    private function assertSubject(string $subjectType): void
    {
        if (! in_array(strtoupper($subjectType), self::SUBJECTS, true)) {
            throw new RuntimeException("'{$subjectType}' is not something a SHEQ file can attach to.");
        }
    }

    /**
     * Capture time and GPS, read before compression destroys them.
     *
     * @return array{captured_at:?Carbon, lat:?float, lng:?float, raw:?array<string,mixed>}
     */
    private function exifFrom(UploadedFile $file): array
    {
        $empty = ['captured_at' => null, 'lat' => null, 'lng' => null, 'raw' => null];

        if (! function_exists('exif_read_data') || ! str_starts_with((string) $file->getMimeType(), 'image/')) {
            return $empty;
        }

        // A phone photograph with no EXIF, or a corrupt header, is normal — not an error worth
        // failing an incident report over.
        $exif = @exif_read_data($file->getRealPath());

        if ($exif === false) {
            return $empty;
        }

        $captured = null;

        foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $key) {
            if (! empty($exif[$key])) {
                try {
                    $captured = Timestamps::utc(Carbon::parse($exif[$key]));
                    break;
                } catch (\Throwable) {
                    // Unparseable timestamp: keep looking rather than discarding the whole read.
                }
            }
        }

        return [
            'captured_at' => $captured,
            'lat' => $this->coordinate($exif, 'GPSLatitude', 'GPSLatitudeRef', 'S'),
            'lng' => $this->coordinate($exif, 'GPSLongitude', 'GPSLongitudeRef', 'W'),
            'raw' => array_intersect_key($exif, array_flip([
                'Make', 'Model', 'DateTimeOriginal', 'Orientation',
                'GPSLatitudeRef', 'GPSLongitudeRef', 'GPSAltitude',
            ])),
        ];
    }

    /** EXIF stores coordinates as degrees/minutes/seconds fractions plus a hemisphere reference. */
    private function coordinate(array $exif, string $key, string $refKey, string $negative): ?float
    {
        if (empty($exif[$key]) || ! is_array($exif[$key])) {
            return null;
        }

        $parts = array_map(function ($fraction) {
            if (! is_string($fraction) || ! str_contains($fraction, '/')) {
                return (float) $fraction;
            }

            [$numerator, $denominator] = array_pad(explode('/', $fraction, 2), 2, '1');

            return ((float) $denominator === 0.0) ? 0.0 : (float) $numerator / (float) $denominator;
        }, $exif[$key]);

        $degrees = ($parts[0] ?? 0) + (($parts[1] ?? 0) / 60) + (($parts[2] ?? 0) / 3600);

        return strtoupper((string) ($exif[$refKey] ?? '')) === $negative ? -$degrees : $degrees;
    }

    /**
     * Writes evidence bytes to the shared disk and returns the path to record.
     *
     * SHEQ reaches the table through the query builder rather than a model, so it cannot use the
     * StoresFileBytes trait — these two methods are that trait's read and write, kept deliberately
     * identical in behaviour so the module behaves like every other one.
     */
    private function putBytes(string $binary, string $nameHint): string
    {
        $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', pathinfo($nameHint, PATHINFO_EXTENSION)) ?: 'bin');

        // Generated from a uuid, never from the uploaded name: a client-supplied filename is the
        // usual route to writing outside the intended directory.
        $path = 'sheq/' . Str::uuid() . '.' . $extension;

        Storage::disk('documents')->put($path, $binary);

        return $path;
    }

    /**
     * The bytes behind a row, path first and blob second.
     *
     * The fallback is what lets this be deployed before the existing rows are migrated, and what
     * makes pruning the old column invisible to readers.
     */
    /**
     * The raw bytes of one stored file.
     *
     * Public so that something assembling several files — a safety file export, say — can read
     * them without a second implementation of "where do the bytes live". That question has a
     * complicated answer in this platform: the `documents` disk is database-backed, a pre-
     * consolidation row may still carry its bytes inline, and either can be missing.
     *
     * Null rather than an exception when a file cannot be read. A caller bundling thirty documents
     * must be able to report the one that is unreadable and still deliver the other twenty-nine.
     */
    public function bytes(int $companyId, string $fileUuid): ?string
    {
        $file = DB::table('sheq_files')
            ->where('company_id', $companyId)->where('uuid', $fileUuid)
            ->whereNull('deleted_at')
            ->first(['uuid', 'storage_path', 'data', 'upload_status', 'mime', 'original_name']);

        if ($file === null || $file->upload_status !== 'STORED') {
            return null;
        }

        return $this->readBytes($file);
    }

    private function readBytes(object $file): ?string
    {
        if (! blank($file->storage_path ?? null)) {
            try {
                $disk = Storage::disk('documents');

                if ($disk->exists($file->storage_path)) {
                    return $disk->get($file->storage_path);
                }
            } catch (\Throwable $e) {
                Log::warning('SHEQ evidence could not be read from the disk.', [
                    'uuid' => $file->uuid ?? null, 'error' => $e->getMessage(),
                ]);
            }
        }

        if (blank($file->data ?? null)) {
            return null;
        }

        $decoded = base64_decode((string) $file->data, true);

        return $decoded === false ? (string) $file->data : $decoded;
    }
}
