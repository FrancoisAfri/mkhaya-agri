<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One party's signature — or refusal — against one version of a contract.
 *
 * `content_sha256` is copied from the version at the moment of signing and never recomputed. That
 * single rule is what makes the record honest: amend a signed contract and this hash stops matching
 * the current wording, so the screen can say "signed on version 2" rather than showing a tick
 * against text that person never read.
 */
class ContractSignature extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_contract_signatures';

    public const STATUSES = ['pending', 'signed', 'declined', 'withdrawn'];

    /** Who is signing. The contractor goes first; we countersign. */
    public const PARTIES = ['contractor', 'company'];

    protected $fillable = [
        'uuid', 'company_id', 'contract_id', 'version_id', 'envelope_uuid',
        'sign_order', 'party', 'user_id', 'signer_name', 'signer_email',
        'status', 'signed_at', 'typed_name', 'signature_type', 'signature_data', 'signature_path', 'decline_reason',
        'content_sha256', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'sign_order' => 'integer',
        'signed_at'  => 'datetime',
    ];

    /**
     * A contract with no tenant is a bug, never a record shared with everybody.
     *
     * Row level security matches `company_id = <session>`, and NULL never equals anything — so an
     * unstamped contract is invisible to every tenant user while still being visible to a platform
     * admin who has not picked a company. Two live contracts reached exactly that state: they
     * looked fine to the person who made them and did not exist for anyone else.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'contract_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ContractVersion::class, 'version_id');
    }

    /** Whether this signature was given against the wording now held. */
    public function matchesContent(?string $currentSha): bool
    {
        return $this->content_sha256 !== null
            && $currentSha !== null
            && hash_equals($this->content_sha256, $currentSha);
    }

    /** @return array<string, mixed> */
    public function toPayload(?string $currentSha = null): array
    {
        return [
            'id'         => $this->uuid,
            // Which round of signing this belongs to. A contract that has been amended or
            // reopened has more than one, and stacking them all in the signature block is what
            // makes the same person appear three times with different answers.
            'envelope'   => $this->envelope_uuid,
            'party'      => $this->party,
            'order'      => $this->sign_order,
            'name'       => $this->signer_name,
            'email'      => $this->signer_email,
            'status'     => $this->status,
            'signed_at'  => optional($this->signed_at)->toDateTimeString(),
            'typed_name' => $this->typed_name,
            'signature_type' => $this->signature_type,
            // The drawing itself, so a signed contract shows the signature rather than describing it.
            'signature_data' => $this->status === 'signed' ? $this->signatureImage() : null,
            'reason'     => $this->decline_reason,
            // Only meaningful once signed, and the reason the whole hash exists.
            'stale'      => $this->status === 'signed' && ! $this->matchesContent($currentSha),
            // A pending signature with nobody behind it can never be given: signing is keyed to a
            // user account. The contract would otherwise sit at "awaiting" for ever, looking
            // healthy, with no indication that the other side literally cannot act.
            'unreachable' => $this->status === 'pending' && $this->user_id === null,
        ];
    }

    /**
     * The signature as something a PDF can draw.
     *
     * Drawn signatures are stored on the shared disk, but a PDF renderer cannot fetch a URL — it
     * needs the bytes in the document — so they come back as a data URI here and nowhere else. That
     * is a read per signature while a certificate renders, which is a handful, rather than per row
     * in a listing.
     *
     * A typed signature is a name, not an image, and passes through unchanged.
     */
    public function signatureImage(): ?string
    {
        if (! blank($this->signature_path)) {
            $bytes = \App\Core\Storage\StoredImage::bytes($this->signature_path);

            if ($bytes !== null) {
                return 'data:image/png;base64,' . base64_encode($bytes);
            }
        }

        return $this->signature_data;
    }
}
