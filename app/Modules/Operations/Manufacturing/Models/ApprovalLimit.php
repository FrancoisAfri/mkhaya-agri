<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Who may sign what, and when it escalates (MFG-001.07).
 *
 * A band rather than a ceiling: rows of 0-50 000 and 50 000-NULL together say who signs what, and
 * a NULL max means "and everything above". The approver is identified by a permission rather than
 * a role, so tenants keep designing their own roles.
 */
class ApprovalLimit extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity;

    /**
     * Confined to the factories the signed-in person is assigned to (MFG-001.09). Company
     * isolation underneath is untouched; this only narrows it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('factory_id', true));
    }

    protected $table = 'mfg_approval_limits';

    /** The documents a band is enforced on: production orders, when they are approved. */
    public const DOCUMENT_TYPES = ['PRODUCTION_ORDER'];

    /** The permissions that make sense as a band's approver, most common first. */
    public const APPROVER_PERMISSIONS = [
        RoleCatalog::APPROVE_ORDERS, RoleCatalog::RELEASE_ORDERS, RoleCatalog::APPROVE_COSTS,
        RoleCatalog::APPROVE_ACCOUNTING, RoleCatalog::APPROVE_PLAN, RoleCatalog::OVERRIDE_CONTROLS,
        RoleCatalog::ALL_FACTORIES, RoleCatalog::CONFIGURE,
    ];

    protected $fillable = [
        'uuid', 'company_id', 'factory_id', 'document_type', 'min_amount', 'max_amount',
        'required_permission', 'escalate_after_hours', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'escalate_after_hours' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    /** Does this band cover the amount? The upper bound is exclusive so bands can meet exactly. */
    public function covers(float $amount): bool
    {
        if ($amount < (float) $this->min_amount) {
            return false;
        }

        return $this->max_amount === null || $amount < (float) $this->max_amount;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
