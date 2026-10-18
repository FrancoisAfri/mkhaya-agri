<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A priced line of the bill of quantities (EPIC-100).
 *
 * `amount` is stored rather than computed on read, because a BOQ is a priced document — the figure
 * was agreed, and recomputing it from quantity × rate would silently restate the contract the day
 * somebody corrects a rate.
 *
 * `measured_quantity` is cumulative to date, not per period. A payment certificate certifies work
 * *to date* less what was certified before, so a per-period figure would have to be summed to mean
 * anything and would drift the first time one was corrected.
 */
class BoqItem extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_boq_items';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'task_id', 'item_no', 'section', 'description',
        'unit', 'quantity', 'rate', 'amount', 'measured_quantity', 'is_provisional', 'sort_order',
            // The tenant's own coding scheme, free text on purpose (§8).
        'cost_code',
    ];

    protected $casts = [
        'quantity'          => 'decimal:3',
        'rate'              => 'decimal:2',
        'amount'            => 'decimal:2',
        'measured_quantity' => 'decimal:3',
        'is_provisional'    => 'boolean',
        'sort_order'        => 'integer',
    ];

    public const UNITS = ['m', 'm2', 'm3', 'kg', 't', 'no', 'item', 'sum', 'hr', 'day', 'week'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /** What has been earned on this line: measured quantity at the agreed rate. */
    public function measuredValue(): float
    {
        return round((float) $this->measured_quantity * (float) $this->rate, 2);
    }

    /** How far through this line the work is. Capped: over-measure is a variation, not 140% done. */
    public function percentComplete(): float
    {
        $quantity = (float) $this->quantity;

        return $quantity > 0 ? min(100.0, round(((float) $this->measured_quantity / $quantity) * 100, 1)) : 0.0;
    }
}
