<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A certification signature on an account.

 * The form carries two: the consulting engineer and the programme manager. `content_sha256` binds
 * the signature to the bytes that were on screen, exactly as pm_document_signatures does — a
 * signature that no longer describes the document it is on is worse than no signature.
 */
class InvoiceSignature extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_invoice_signatures';

    public const ROLES = ['consulting_engineer', 'programme_manager'];
    protected $fillable = [
        'uuid', 'company_id', 'invoice_id', 'role', 'signer_name', 'registration_number',
        'user_id', 'signed_at', 'content_sha256',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** Whether this signature was given against the bytes now held. */
    public function matchesContent(?string $currentSha): bool
    {
        return $this->content_sha256 !== null
            && $currentSha !== null
            && hash_equals($this->content_sha256, $currentSha);
    }
}
