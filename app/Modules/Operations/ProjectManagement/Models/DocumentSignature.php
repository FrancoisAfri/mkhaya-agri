<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One person's signature on one revision of one document.
 *
 * `content_sha256` is written at signing time from the blob that was on screen. Comparing it to the
 * blob's current hash is what tells a reader whether the signature still describes what they are
 * looking at (see `matchesContent()`).
 */
class DocumentSignature extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_document_signatures';

    protected $fillable = [
        'uuid', 'company_id', 'document_uuid', 'blob_id', 'envelope_uuid', 'sign_order',
        'user_id', 'signer_name', 'signer_email', 'role', 'status', 'signed_at',
        'typed_name', 'decline_reason', 'content_sha256', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'sign_order' => 'integer',
        'blob_id'    => 'integer',
        'signed_at'  => 'datetime',
    ];

    public function blob()
    {
        return $this->belongsTo(DocumentBlob::class, 'blob_id');
    }

    /** Whether this signature was given against the bytes now held. */
    public function matchesContent(?string $currentSha): bool
    {
        return $this->content_sha256 !== null
            && $currentSha !== null
            && hash_equals($this->content_sha256, $currentSha);
    }
}
