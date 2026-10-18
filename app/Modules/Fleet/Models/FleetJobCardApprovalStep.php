<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One step in the job card approval process flow. Steps with a min_amount only
 * apply to cards at or above that total cost, so small jobs skip senior sign-off.
 */
class FleetJobCardApprovalStep extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $table = 'fleet_job_card_approval_steps';

    protected $fillable = [
        'uuid', 'company_id', 'name', 'sequence', 'approver_type', 'approver_user_id',
        'approver_role', 'min_amount', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean', 'min_amount' => 'decimal:2', 'sequence' => 'integer'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approver_user_id'); }

    public function scopeActive($q) { return $q->where('is_active', true); }

    /** Does this step apply to a card of the given total cost? */
    public function appliesTo(float $totalCost): bool
    {
        return $this->min_amount === null || $totalCost >= (float) $this->min_amount;
    }
}
