<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a loss detector saw (MFG-012). A detected variance until an investigator verifies it
 * as a loss -- or dismisses it -- with evidence.
 */
class LossFinding extends Model
{
    use \App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const DETECTED = 'DETECTED';
    public const INVESTIGATING = 'INVESTIGATING';
    public const VERIFIED = 'VERIFIED';
    public const DISMISSED = 'DISMISSED';
    public const ACTIONED = 'ACTIONED';
    public const CLOSED = 'CLOSED';

    /** Still somebody's job. */
    public const OPEN = [self::DETECTED, self::INVESTIGATING, self::VERIFIED, self::ACTIONED];

    public const CATEGORIES = [
        'EXCESS_MATERIAL', 'UNEXPLAINED_STOCK_LOSS', 'HIGH_SCRAP', 'REPEATED_REWORK', 'ABNORMAL_MACHINE',
        'EXCESS_DOWNTIME', 'EXCESS_SETUP', 'EXPIRED_OBSOLETE', 'OVERSTOCK', 'RUSH_ORDERS', 'SUPPLIER_QUALITY', 'OVER_STANDARD',
    ];

    /** Raised by the warehouse's detectors (WMS-110); investigated the same way. */
    public const WAREHOUSE_CATEGORIES = ['WH_REPEATED_ADJUSTMENTS', 'WH_HIGH_VALUE_ADJUSTMENT', 'WH_DUPLICATE_DOCUMENT', 'WH_UNUSUAL_RETURNS',
        'WH_REPEATED_SHORT_PICKS', 'WH_UNEXPECTED_LOCATION', 'WH_DISPATCH_MISMATCH', 'WH_EMERGENCY_PURCHASES', 'WH_OUT_OF_HOURS', 'WH_MANUAL'];

    protected $table = 'mfg_loss_findings';

    protected $fillable = ['uuid', 'company_id', 'number', 'category', 'status', 'factory_id', 'subject_type', 'subject_id', 'subject_label',
        'period_from', 'period_to', 'measure', 'unit', 'actual_value', 'baseline_value', 'baseline_basis', 'evidence', 'estimated_impact',
        'confidence', 'impact_basis', 'recommended_action', 'owner_id', 'due_date', 'escalation_level', 'escalated_at', 'investigation_notes',
        'verified_loss', 'verification_evidence', 'verified_by', 'verified_at', 'dismissal_reason', 'corrective_action', 'result_after_action',
        'realised_saving', 'saving_evidence', 'requires_approval', 'approved_by', 'approved_at', 'approval_notes', 'dedupe_key', 'detected_at', 'closed_at',
        'priority', 'rule_snapshot', 'origin', 'warehouse_id'];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'actual_value' => 'float', 'baseline_value' => 'float', 'evidence' => 'array', 'rule_snapshot' => 'array',
        'estimated_impact' => 'float', 'due_date' => 'date', 'escalation_level' => 'integer', 'escalated_at' => 'datetime', 'verified_loss' => 'float',
        'verified_at' => 'datetime', 'realised_saving' => 'float', 'requires_approval' => 'boolean', 'approved_at' => 'datetime',
        'detected_at' => 'datetime', 'closed_at' => 'datetime'];

    public function owner(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'owner_id'); }

    public function events(): HasMany { return $this->hasMany(LossEvent::class, 'finding_id')->orderBy('acted_at')->orderBy('id'); }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OPEN, true) && $this->due_date && $this->due_date->lt(today());
    }
}
