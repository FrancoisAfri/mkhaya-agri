<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class LmsCourseMaterial extends Model
{
    use StoresFileBytes;

    protected $table = 'lms_course_materials';

    protected $fillable = [
        'course_id',
        'lesson_id',
        'title',
        'type',
        'disk',
        'path',
        'file_name',
        'mime_type',
        'size_bytes',
        'sort_order',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $appends = ['url', 'size_mb'];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(LmsLesson::class, 'lesson_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getUrlAttribute(): string
    {
        return route('lms.materials.serve', ['material' => $this->id]);
    }

    public function getSizeMbAttribute(): float
    {
        return round($this->size_bytes / (1024 * 1024), 2);
    }

    // ── Database storage helpers (same pattern as LeaveDocument) ─────────────

    /**
     * Encode a file to the base64 JSON blob format used for database storage.
     * Returns the JSON string to store in the `path` column (disk = 'database').
     */
    public static function encodeForDatabase(UploadedFile $file): string
    {
        $binary = file_get_contents($file->getRealPath());

        return json_encode([
            'uuid'          => (string) Str::uuid(),
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getMimeType() ?? 'application/octet-stream',
            'type'          => 'document',
            'data'          => base64_encode($binary),
            'size'          => strlen($binary),
        ]);
    }

    public function toResponse(bool $inline = true): \Illuminate\Http\Response
    {
        // Course material is served to learners to read, so the trait's caching-free headers are
        // relaxed for this one — but not the type: it is inert unless it is something a browser
        // can safely render, and addslashes() never stopped a filename escaping the header.
        return $this->fileResponse(
            $this->file_name ?: $this->legacyEnvelopeValue('original_name') ?: 'file',
            $this->mime_type ?: $this->legacyEnvelopeValue('mime'),
            inline: $inline,
        )->header('Cache-Control', 'private, max-age=3600');
    }

    // ── Type classification ───────────────────────────────────────────────────

    public static function typeFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if (in_array($mime, [
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ])) {
            return 'presentation';
        }
        if (in_array($mime, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ])) {
            return 'document';
        }
        return 'other';
    }

    /**
     * Whether this material's content is stored as a base64 JSON blob in the DB.
     */
    public function isStoredInDatabase(): bool
    {
        return $this->disk === 'database';
    }

    protected function fileBlobColumn(): ?string
    {
        return 'path';
    }

    protected function fileFolder(): string
    {
        return 'lms/materials';
    }

    /**
     * The legacy bytes, but only when `path` actually holds them.
     *
     * This column is named `path` and, depending on when the row was written, holds one of two
     * entirely different things: the JSON envelope from the upload trait, or a real path on a
     * Laravel disk from before that. The trait cannot tell them apart on its own, and guessing
     * wrong is not a harmless error — a real path is a short ASCII string, so a permissive decode
     * hands it back as though it were the file, and the user downloads a document containing the
     * words "policies/abc.pdf".
     *
     * The envelope is the only shape this returns. A disk path is left to the caller that knows how
     * to resolve it.
     */
    protected function legacyBytes(): ?string
    {
        $raw = (string) ($this->{$this->fileBlobColumn()} ?? '');

        if (! str_starts_with(ltrim($raw), '{')) {
            return null;
        }

        return $this->decodeLegacyBlob($raw);
    }
}
