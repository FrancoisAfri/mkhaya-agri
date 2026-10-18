<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A contract between the practice and somebody working on a project.
 *
 * The body is not here. It lives on `versions`, and this row points at the current one — because a
 * contract is amended, and a register that keeps only the latest wording cannot answer the only
 * question that matters in a dispute: what did the person who signed actually see.
 */
class ProjectContract extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_contracts';

    /**
     * The lifecycle, in order.
     *
     * `in_review` is the approval engine's; `out_for_signature` is signing's. They are separate
     * states because a company may design no approval flow at all, in which case a draft goes
     * straight to signature — which is what a small practice actually wants.
     */
    public const STATUSES = [
        'draft', 'in_review', 'approved', 'out_for_signature', 'signed',
        'rejected', 'declined', 'cancelled',
    ];

    /** States from which the wording may still be changed without withdrawing signatures. */
    public const EDITABLE = ['draft', 'rejected'];

    /** Nothing may change a contract that has reached one of these. */
    public const CLOSED = ['signed', 'cancelled'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'project_member_id', 'template_id',
        'contract_number', 'title', 'summary', 'status',
        'contract_value', 'currency', 'start_date', 'end_date',
        'current_version', 'created_by',
        'document_data', 'document_path', 'document_name', 'document_sha256', 'document_generated_at',
        'signed_document_data', 'signed_document_path', 'signed_document_sha256', 'signed_document_at',
    ];

    protected $casts = [
        'start_date'      => 'date',
        'end_date'        => 'date',
        'current_version'       => 'integer',
        'document_generated_at' => 'datetime',
        'signed_document_at'    => 'datetime',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProjectMember::class, 'project_member_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class, 'contract_id')->orderByDesc('version_no');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ContractSignature::class, 'contract_id')->orderBy('sign_order');
    }

    /** The wording as it currently stands. */
    public function currentVersion(): ?ContractVersion
    {
        return $this->versions()->where('version_no', $this->current_version)->first();
    }

    /** Is there a document to sign or download yet? */
    public function hasDocument(): bool
    {
        return filled($this->document_path) || filled($this->document_data);
    }

    /** The executed copy — the one with signatures burned into the page. */
    public function hasSignedDocument(): bool
    {
        return filled($this->signed_document_path) || filled($this->signed_document_data);
    }

    /**
     * The issued document's bytes.
     *
     * A contract row holds two distinct files, so this reads them by name rather than through the
     * single-file trait the rest of the platform uses. They stay separate because the hash of each
     * answers a different question: has the issued wording changed, and has the executed copy been
     * altered since it was signed.
     */
    public function documentBytes(): ?string
    {
        return $this->storedBytes($this->document_path, $this->document_data);
    }

    /** The executed copy's bytes. */
    public function signedDocumentBytes(): ?string
    {
        return $this->storedBytes($this->signed_document_path, $this->signed_document_data);
    }

    /** Path first, column second — so a row that has not been migrated still opens. */
    private function storedBytes(?string $path, ?string $base64): ?string
    {
        if (filled($path)) {
            try {
                $disk = \Illuminate\Support\Facades\Storage::disk('documents');

                if ($disk->exists($path)) {
                    return $disk->get($path);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Contract document could not be read.', [
                    'contract' => $this->uuid ?? null, 'error' => $e->getMessage(),
                ]);
            }
        }

        return blank($base64) ? null : base64_decode((string) $base64);
    }

    /** Writes one of this contract's two files to the shared disk and returns its path. */
    public function writeDocument(string $binary, bool $signed = false): string
    {
        $path = 'project-management/contracts/' . \Illuminate\Support\Str::uuid()
            . ($signed ? '-signed' : '') . '.pdf';

        \Illuminate\Support\Facades\Storage::disk('documents')->put($path, $binary);

        return $path;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    /**
     * Whether amending now would tear up signatures already given.
     *
     * True is not a refusal — a contract out for signature is exactly the one a contractor asks to
     * change. It is the warning the screen has to show before doing it.
     */
    public function amendmentWouldWithdrawSignatures(): bool
    {
        return $this->status === 'out_for_signature'
            && $this->signatures()->where('status', 'pending')->exists();
    }
}
