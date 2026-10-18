<?php

namespace App\Modules\HR\Leave\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Trait\FileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LeaveDocument extends Model
{
    use StoresFileBytes;

    use SoftDeletes;
    use SoftDeletes, FileStorage;

    protected $table = 'leave_documents';

    protected $fillable = [
        'uuid', 'leave_request_id', 'uploader_id', 'document_type',
        'original_filename', 'stored_filename', 'storage_path',
        'path', 'disk',
        'file_size_bytes', 'mime_type', 'is_encrypted',
        'access_log_enabled', 'uploaded_at', 'expires_at',
    ];

    protected $casts = [
        'is_encrypted'       => 'boolean',
        'access_log_enabled' => 'boolean',
        'is_clean'           => 'boolean',
        'virus_scanned_at'   => 'datetime',
        'uploaded_at'        => 'datetime',
        'expires_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn(self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /** Decode the stored JSON blob and return a binary HTTP response. */
    public function toResponse(bool $inline = false): \Illuminate\Http\Response
    {
        return $this->fileResponse(
            $this->original_filename ?: $this->legacyEnvelopeValue('original_name'),
            $this->mime_type ?: $this->legacyEnvelopeValue('mime'),
            inline: $inline,
        );
    }

    protected function fileBlobColumn(): ?string
    {
        return 'path';
    }

    protected function fileFolder(): string
    {
        return 'leave';
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
