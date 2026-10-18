<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Core\Storage\Concerns\StoresFileBytes;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document's bytes, in the database (PM-ADR-012).
 *
 * The column shape is the platform's own — `App\Core\User\Trait\FileStorage` writes
 * `uuid / original_name / mime / type / data / size`, with the payload base64-encoded in `data`,
 * and every other module on this system stores an uploaded file that way. PM matching it means one
 * convention rather than two, and the same helpers read both.
 *
 * `data` is deliberately not in $fillable and never eager-selected: a listing that pulled the bytes
 * of forty drawings to render forty filenames would be the slowest page in the module.
 */
class DocumentBlob extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;
    use StoresFileBytes;

    protected $table = 'pm_document_blobs';

    protected $fillable = [
        'uuid', 'company_id', 'project_uuid', 'original_name', 'mime',
        'type', 'size', 'sha256', 'uploaded_by',
        'version', 'supersedes_id', 'superseded_at', 'revision',
        'esign_envelope_uuid', 'signature_status',
    ];

    protected $casts = [
        'size'          => 'integer',
        'version'       => 'integer',
        'supersedes_id' => 'integer',
        'superseded_at' => 'datetime',
    ];

    /** Columns that are safe to select for a listing — everything except the payload. */
    public const INDEX_COLUMNS = [
        'id', 'uuid', 'company_id', 'project_uuid', 'original_name', 'mime', 'storage_path',
        'type', 'size', 'sha256', 'uploaded_by', 'created_at',
        'version', 'supersedes_id', 'superseded_at', 'revision',
        'esign_envelope_uuid', 'signature_status',
    ];

    /**
     * The base64 payload, re-read from the row.
     *
     * Fetched on demand rather than kept on the model: a download is the only thing that wants it,
     * and everything else that touches this record wants it not to be in memory.
     */
    public function payload(): string
    {
        return base64_encode($this->bytes());
    }

    /**
     * The decoded bytes.
     *
     * Overrides the trait only to keep this model's contract — it returns a string rather than a
     * null, because every caller here treats a missing file as empty rather than as an error.
     *
     * The legacy column is still re-read from the row rather than kept on the model: a download is
     * the only thing that wants those bytes, and everything else that touches this record wants
     * them not to be in memory. INDEX_COLUMNS exists for exactly that reason, which is also why
     * storage_path was added to it — a pointer is cheap, a payload is not.
     */
    public function bytes(): string
    {
        $stored = $this->diskBytes();

        if ($stored !== null) {
            return $stored;
        }

        $legacy = (string) static::withoutGlobalScopes()->whereKey($this->getKey())->value('data');

        return $legacy === '' ? '' : (string) $this->decodeLegacyBlob($legacy);
    }

    protected function fileBlobColumn(): ?string
    {
        return 'data';
    }

    protected function fileFolder(): string
    {
        return 'project-management/documents';
    }
}
