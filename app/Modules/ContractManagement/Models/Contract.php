<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Trait\FileStorage;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Contract extends Model
{
    use HasDocuments;

    use StoresFileBytes;

    use BelongsToCompany, FileStorage, LogsActivity, SoftDeletes;

    protected $table = 'contracts';

    protected $fillable = [
        'uuid',
        'contract_number',
        'title',
        'description',
        'contract_status',
        'approval_status',
        'payment_status',
        'start_date',
        'end_date',
        'contract_value',
        'currency',
        'final_contract_value',
        'periodicity',
        'governing_law',
        'jurisdiction',
        'contract_type_id',
        'contract_category_id',
        'contract_request_id',
        'supplier_id',
        // A contract raised from Project Management is for a contractor on a job rather than for a
        // supplier. Both are null on every contract written before that existed.
        'project_id',
        'project_member_id',
        'owner_id',
        'current_step_id',
        'our_recommendation',
        'rejection_reason',
        'file_path',
        'file_data',
        'file_name',
        'file_mime',
        'file_size',
        'esign_envelope_id',
        'signature_status',
        'deleted_by',
        'deleted_ip',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'contract_value' => 'decimal:2',
        'final_contract_value' => 'decimal:2',
    ];

    /**
     * Addressed by uuid, never by the row id.
     *
     * A contract is linked to from obligations, claims and the supplier it belongs to. A sequential id in that URL says how many a tenant has and invites a guess at the next
     * one; authorisation is what actually refuses it, but the id makes the guess free and makes an
     * accidental leak -- a pasted link, a screenshot, a referrer header -- carry more than it needs to.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Never ship the heavy base64 blob in JSON/Inertia payloads. */
    protected $hidden = ['file_data'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Contract Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function hasDocument(): bool
    {
        // Covers the path as well as the column: testing file_data alone would report "no
        // document" for every contract whose file has moved to the shared disk.
        return $this->hasBytes();
    }

    public function documentResponse(bool $inline = false): Response
    {
        return $this->fileResponse(
            $this->file_name ?: $this->legacyEnvelopeValue('original_name') ?: 'contract',
            $this->file_mime ?: $this->legacyEnvelopeValue('mime'),
            inline: $inline,
        );
    }

    protected static function booted(): void
    {
        static::creating(function (Contract $contract) {
            $contract->uuid ??= (string) Str::uuid();
        });
    }

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function type(): BelongsTo
    {
        return $this->belongsTo(ContractType::class, 'contract_type_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContractCategory::class, 'contract_category_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ContractRequest::class, 'contract_request_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ContractMilestone::class, 'contract_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ContractReview::class, 'contract_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ContractStatusHistory::class, 'contract_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(ContractNotification::class, 'contract_id');
    }

    public function appraisals(): HasMany
    {
        return $this->hasMany(ContractAppraisal::class, 'contract_id');
    }

    public function ai(): HasOne
    {
        return $this->hasOne(ContractAi::class, 'contract_id');
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(ContractObligation::class, 'contract_id');
    }

    public function stepStatuses(): HasMany
    {
        return $this->hasMany(ContractStepStatus::class, 'contract_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────────────────

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                // Postgres full-text over title/number/description/law/jurisdiction
                // (GIN-indexed), plus ILIKE fallbacks so partial words still match.
                $q->whereRaw(
                    "to_tsvector('english', coalesce(title,'') || ' ' || coalesce(contract_number,'') || ' ' || coalesce(description,'') || ' ' || coalesce(governing_law,'') || ' ' || coalesce(jurisdiction,'')) @@ websearch_to_tsquery('english', ?)",
                    [$search]
                )
                    ->orWhere('title', 'ilike', "%{$search}%")
                    ->orWhere('contract_number', 'ilike', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('contract_status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('contract_type_id', $filters['type']);
        }

        if (! empty($filters['category'])) {
            $query->where('contract_category_id', $filters['category']);
        }

        if (! empty($filters['supplier'])) {
            $query->where('supplier_id', $filters['supplier']);
        }

        return $query;
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'contracts';
    }
}
