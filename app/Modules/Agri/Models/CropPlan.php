<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What will be grown on a field in a season, and how it went (FR-CROP-001..).
 *
 * The lifecycle is a state machine (CropPlanService holds the rules):
 *
 *     draft ──approve──▶ approved ──plant──▶ planted ──harvest──▶ harvesting ──complete──▶ completed
 *       └──────cancel──────┴──cancel
 */
class CropPlan extends FarmOwnedModel
{
    public const DRAFT = 'draft';
    public const APPROVED = 'approved';
    public const PLANTED = 'planted';
    public const HARVESTING = 'harvesting';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::APPROVED, self::PLANTED, self::HARVESTING, self::COMPLETED, self::CANCELLED];

    /** Plans that hold the field: an overlapping plan on the same field cannot exceed its area. */
    public const LIVE = [self::DRAFT, self::APPROVED, self::PLANTED, self::HARVESTING];

    /** Plans field work may be recorded against. */
    public const WORKABLE = [self::APPROVED, self::PLANTED, self::HARVESTING];

    protected $table = 'crop_plans';

    protected $casts = [
        'planned_area' => 'decimal:4', 'expected_yield' => 'decimal:3', 'budget_amount' => 'decimal:2',
        'expected_price' => 'decimal:2',
        'planned_planting_on' => 'date', 'planned_harvest_on' => 'date', 'planted_on' => 'date',
        'harvest_completed_on' => 'date', 'approved_at' => 'datetime',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(CropSeason::class, 'season_id');
    }

    public function cropType(): BelongsTo
    {
        return $this->belongsTo(CropType::class);
    }

    public function variety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class, 'variety_id');
    }

    public function fieldActivities(): HasMany
    {
        return $this->hasMany(FieldActivity::class);
    }

    public function harvests(): HasMany
    {
        return $this->hasMany(HarvestRecord::class);
    }

    public function hectares(): float
    {
        return (float) Farm::hectares((float) $this->planned_area, $this->area_unit);
    }
}
