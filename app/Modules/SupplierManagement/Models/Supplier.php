<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Supplier extends Model
{
    use HasDocuments;

    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'suppliers';

    protected $fillable = [
        'uuid', 'portal_token', 'portal_token_expires_at', 'portal_token_rotated_at',
        'supplier_number', 'company_name', 'trading_name',
        'company_registration_number', 'tax_reference_number', 'vat_registration_number',
        'bbbee_status_level', 'business_entity_type', 'physical_address', 'postal_address',
        'phone_number', 'email', 'website_url', 'bank_name', 'account_holder_name',
        'account_type', 'bank_account_number', 'branch_code', 'payment_terms',
        'years_in_operation', 'conflict_of_interest_declaration',
        'fraud_corruption_policy_agreement', 'public_liability_insurance',
        'health_safety_compliance', 'references_or_client_list', 'is_active', 'notes',
        'created_by', 'segment', 'parent_id', 'is_preferred',
        'tcs_pin', 'tcs_pin_expires_at',
        'risk_score', 'risk_band', 'risk_tier', 'tier_factors', 'risk_calculated_at',
    ];

    protected $casts = [
        // The supplier's own authorisation for a third party to check their tax compliance status.
        // Encrypted because it is a credential, and never shown back in full.
        'tcs_pin'                           => 'encrypted',
        'tcs_pin_expires_at'                => 'date',
        // A cache of the newest risk snapshot, for sorting and filtering the directory. The
        // snapshots in supplier_risk_scores remain authoritative.
        'risk_score'                        => 'decimal:2',
        'risk_tier'                         => 'integer',
        'tier_factors'                      => 'array',
        'risk_calculated_at'                => 'datetime',
        'fraud_corruption_policy_agreement' => 'boolean',
        'public_liability_insurance'        => 'boolean',
        'health_safety_compliance'          => 'boolean',
        'years_in_operation'                => 'integer',
        'is_active'                         => 'integer',
        'portal_token_expires_at'           => 'datetime',
        'portal_token_rotated_at'           => 'datetime',
        /*
         * Both written as Carbon by the monitoring sweep and read back as dates everywhere, but
         * never declared — so Eloquent handed back the raw strings Postgres gave it. The monitoring
         * screen fataled on `last_monitored_at?->toDateString()`, where the null-safe operator is no
         * help because a string is not null, and `ContinuousMonitor::isDue()` fataled the same way
         * on `monitoring_due_at->isPast()` — which is in the nightly sweep, and only after a
         * supplier had been monitored once, since the first pass exits early on null.
         */
        'last_monitored_at'                 => 'datetime',
        'monitoring_due_at'                 => 'datetime',
    ];

    /**
     * Two credentials that were travelling to the browser in every payload.
     *
     * `portal_token` IS the supplier portal's credential — the portal has no login, and the token in
     * the URL is the whole of the authentication. The supplier list renders every supplier, so the
     * directory page was handing any internal user who could open it a working key to every
     * supplier's portal, where they can change banking details.
     *
     * `tcs_pin` is cast `encrypted`, which protects it at rest and not at all on the way out: a
     * serialised model decrypts it. It is the supplier's own authorisation for a third party to pull
     * their SARS tax compliance status, and this model's own comment says it is never shown back in
     * full.
     *
     * Nothing in the UI reads either — the PIN form writes one and never displays it — and hiding
     * affects serialisation only, so the portal link and the verification call still read them
     * server-side exactly as before.
     */
    protected $hidden = ['portal_token', 'tcs_pin'];

    /**
     * Suppliers are addressed by uuid, never by the row id.
     *
     * `/suppliers/1` tells anybody who sees it how many suppliers this tenant has and invites them
     * to try `/suppliers/2`. The authorisation check behind each page is what actually stops them,
     * but a sequential id makes a guess cheap and makes an accidental leak — a pasted link, a
     * screenshot, a referrer header — carry information it did not need to.
     *
     * The column has existed and been populated on create since the model was written; nothing
     * addressed by it until now.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (Supplier $supplier) {
            $supplier->uuid ??= (string) Str::uuid();
            $supplier->portal_token ??= Str::random(48);
        });

        static::observe(\App\Modules\SupplierManagement\Observers\SupplierObserver::class);

        // Banking edited on a Tuesday afternoon is the change no external source will ever report,
        // and the most consequential one on the record.
        static::observe(\App\Modules\SupplierManagement\Observers\SupplierChangeObserver::class);
    }

    public function riskFlags(): HasMany
    {
        return $this->hasMany(SupplierRiskFlag::class, 'supplier_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class, 'supplier_id');
    }

    public function directors(): HasMany
    {
        return $this->hasMany(SupplierDirector::class, 'supplier_id');
    }

    /**
     * The people attached to this supplier — directors, members, shareholders, beneficial owners.
     *
     * Distinct from `directors()`, which is the older, thinner table that the onboarding screens still
     * write to. This is the one carrying the identifiers a screening needs, and the two exist side by
     * side deliberately: nothing that reads directors today has to change for this to be added.
     */
    public function persons(): HasMany
    {
        return $this->hasMany(SupplierPerson::class, 'supplier_id');
    }

    /** Every due-diligence check ever run against this supplier, newest first. */
    public function verifications(): HasMany
    {
        return $this->hasMany(SupplierVerification::class, 'supplier_id')->orderByDesc('checked_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SupplierDocument::class, 'supplier_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class, 'supplier_id');
    }

    public function industries(): BelongsToMany
    {
        return $this->belongsToMany(
            SupplierIndustryType::class,
            'supplier_industry',
            'supplier_id',
            'supplier_industry_type_id'
        )->withTimestamps();
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            SupplierCategory::class,
            'supplier_category_supplier',
            'supplier_id',
            'supplier_category_id'
        )->withTimestamps();
    }

    public function appraisals(): HasMany
    {
        return $this->hasMany(SupplierAppraisal::class, 'supplier_id');
    }

    public function kycApprovals(): HasMany
    {
        return $this->hasMany(SupplierKycApproval::class, 'supplier_id');
    }

    public function scars(): HasMany
    {
        return $this->hasMany(SupplierScar::class, 'supplier_id');
    }

    public function screening(): HasOne
    {
        return $this->hasOne(SupplierScreening::class, 'supplier_id');
    }

    public function bbbee(): HasOne
    {
        return $this->hasOne(SupplierBbbee::class, 'supplier_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Supplier::class, 'parent_id');
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s))
            ->when($filters['company_name'] ?? null, fn ($q, $name) =>
                $q->where('company_name', 'like', "%{$name}%"))
            ->when($filters['registration'] ?? null, fn ($q, $reg) =>
                $q->where('company_registration_number', 'like', "%{$reg}%"))
            // `unrated` is a filter in its own right: the suppliers nobody has assessed are the ones
            // worth finding, and they are invisible to a band filter that only matches bands.
            ->when($filters['risk_band'] ?? null, fn ($q, $band) => $band === 'unrated'
                ? $q->whereNull('risk_band')
                : $q->where('risk_band', $band));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
