<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A variable-rate prescription: one product on one field at a rate per zone. It is an instruction
 * to a machine, so one person drafts it and another approves it before it can be sent; any change
 * after that takes it back to a draft with a new version.
 */
class Prescription extends FarmOwnedModel
{
    public const OPERATIONS = ['seeding', 'fertiliser', 'lime', 'crop_protection'];
    public const UNITS = ['kg_ha', 'l_ha', 'seeds_ha'];
    public const METHODS = ['equal_interval', 'quantile'];

    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const SENT = 'sent';
    public const APPLIED = 'applied';
    public const CANCELLED = 'cancelled';

    /** The field activity type each operation is planned as. */
    public const ACTIVITY_TYPES = ['seeding' => 'planting', 'fertiliser' => 'fertilising', 'lime' => 'fertilising', 'crop_protection' => 'spraying'];

    protected $table = 'agri_prescriptions';

    protected $casts = ['flip' => 'boolean', 'base_rate' => 'float', 'step_pct' => 'float', 'flat_rate' => 'float', 'area_ha' => 'float', 'total_quantity' => 'float',
        'total_cost' => 'float', 'per_stock_unit' => 'float', 'unit_cost' => 'float', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'planned_on' => 'date'];

    public function zones(): HasMany
    {
        return $this->hasMany(PrescriptionZone::class, 'prescription_id')->orderBy('position');
    }

    public function files(): HasMany
    {
        return $this->hasMany(PrescriptionFile::class, 'prescription_id');
    }

    /** Approved for the version it now is, so the machine gets exactly what was approved. */
    public function isApproved(): bool
    {
        return in_array($this->status, [self::APPROVED, self::SENT, self::APPLIED], true) && (int) $this->approved_version === (int) $this->version;
    }
}
