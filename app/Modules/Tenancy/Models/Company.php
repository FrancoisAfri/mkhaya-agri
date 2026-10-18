<?php

namespace App\Modules\Tenancy\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A SaaS tenant. Its id is written as `company_id` across the domain tables. In
 * the self-hosted edition there is exactly one company; in the SaaS edition each
 * customer is a company with its own users, data and subscription.
 */
class Company extends Model
{
    /*
     * The company's own documents — CIPC registration, tax clearance, B-BBEE certificate. Registered
     * as a document relation from the start; without the trait the picker offered it, the link was
     * written, and the company could never show what had been filed against it.
     */
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;

    protected $table = 'companies';

    protected $fillable = [
        'uuid', 'slug', 'name', 'registration_number', 'vat_number', 'contact_person',
        'contact_email', 'contact_phone', 'status', 'plan', 'monthly_total',
        'ai_token_quota', 'sms_quota', 'founding_customer', 'price_locked_until',
        'trial_ends_at', 'approved_at', 'suspended_at', 'suspended_reason', 'created_by',
        'white_label', 'reseller_id', 'trial_alert_stage', 'trial_alert_ends_at',
    ];

    // Archive fields (archived_at, purge_after, purged_at, converted_to_reseller_id) are set only by
    // CompanyToReseller and the purge, through forceFill — never from a request.

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'trial_alert_ends_at' => 'date',
        'trial_alert_stage' => 'integer',
        'approved_at' => 'datetime',
        'suspended_at' => 'datetime',
        'price_locked_until' => 'date',
        'archived_at' => 'datetime',
        'purge_after' => 'date',
        'purged_at' => 'datetime',
        'monthly_total' => 'decimal:2',
        'ai_token_quota' => 'integer',
        'sms_quota' => 'integer',
        'founding_customer' => 'boolean',
        'white_label' => 'boolean',
    ];

    protected $attributes = ['status' => 'trial', 'plan' => 'starter'];

    public const STATUSES = ['trial', 'active', 'suspended', 'cancelled', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (Company $c) {
            $c->uuid ??= (string) Str::uuid();
            $c->slug ??= Str::slug($c->name).'-'.Str::lower(Str::random(4));
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'company_id');
    }

    /** The reseller that brought this client, if any. */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'reseller_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['trial', 'active'], true);
    }

    public function isSuspended(): bool
    {
        return in_array($this->status, ['suspended', 'cancelled', 'archived'], true);
    }

    /** Converted to a reseller: no longer a tenant, kept only until its purge date. Never reactivated. */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /** The reseller this company became, when it was converted. */
    public function becameReseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'converted_to_reseller_id');
    }

    public function trialExpired(): bool
    {
        return $this->status === 'trial' && $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    /**
     * Whole days until the trial ends — negative once it has lapsed, null when the
     * company is not on a trial at all. Counted from the start of today so every run
     * of tenancy:trial-reminders on the same day agrees on the number.
     */
    public function trialDaysLeft(): ?int
    {
        if ($this->status !== 'trial' || ! $this->trial_ends_at) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->trial_ends_at->copy()->startOfDay(), false);
    }
}
