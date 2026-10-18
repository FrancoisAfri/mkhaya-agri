<?php

namespace App\Modules\Policies\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyDocument extends Model
{
    use StoresFileBytes;

    use HasFactory;

    protected $table = 'policy_documents';

    protected $fillable = [
        'policy_id',
        'version_id',
        'filename',
        'disk',
        'path',
        'mime_type',
        'size_bytes',
        'extraction_status',
        'extraction_provider',
        'extraction_model',
        'raw_ai_output',
        'extracted_text',
        'extraction_error',
        'extracted_at',
        'uploaded_by',
    ];

    protected $casts = [
        'raw_ai_output' => 'array',
        'extracted_at' => 'datetime',
    ];

    protected $hidden = [
        'path',
    ];

    /**
     * Ensure raw AI payload is always valid UTF-8 before JSON encoding.
     * This prevents JsonEncodingException on malformed provider output bytes.
     */
    public function setRawAiOutputAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['raw_ai_output'] = null;

            return;
        }

        $sanitized = $this->sanitizeForJson($value);
        $encoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->attributes['raw_ai_output'] = $encoded === false ? '{}' : $encoded;
    }

    private function sanitizeForJson($value)
    {
        if (is_string($value)) {
            return $this->sanitizeUtf8String($value);
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $safeKey = is_string($key) ? $this->sanitizeUtf8String($key) : $key;
                $sanitized[$safeKey] = $this->sanitizeForJson($item);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return $this->sanitizeForJson((array) $value);
        }

        return $value;
    }

    private function sanitizeUtf8String(string $value): string
    {
        if (! preg_match('//u', $value)) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = $converted === false ? '' : $converted;
        }

        // Remove ASCII control characters that can still break downstream handling.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $value) ?? $value;

        return trim($value);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'version_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function fileBlobColumn(): ?string
    {
        return 'path';
    }

    protected function fileFolder(): string
    {
        return 'policies';
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
