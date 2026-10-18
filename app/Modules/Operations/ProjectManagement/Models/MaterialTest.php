<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sample taken, and eventually its result (§23).
 *
 * Not a snag, because the record has to exist while the answer is still unknown: cubes are crushed
 * at 28 days, and the pour they came from is buried long before then.
 */
class MaterialTest extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_material_tests';

    public const KINDS = ['concrete_cube', 'compaction', 'weld', 'soil', 'asphalt', 'steel', 'other'];

    public const RESULTS = ['pending', 'pass', 'fail'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'reference', 'kind', 'element', 'sampled_on',
        'result_on', 'specified_value', 'result_value', 'unit', 'result', 'lab', 'notes', 'snag_id',
    ];

    protected $casts = [
        'sampled_on'      => 'date',
        'result_on'       => 'date',
        'specified_value' => 'decimal:3',
        'result_value'    => 'decimal:3',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function scopeAwaiting(Builder $query): Builder
    {
        return $query->where('result', 'pending');
    }

    /**
     * Whether the result meets what was specified.
     *
     * Only ever advisory — the lab's own pass or fail is what is recorded. A comparison that
     * silently overrode the certificate would be this module deciding a question it has no standing
     * to answer.
     */
    public function meetsSpecification(): ?bool
    {
        if ($this->specified_value === null || $this->result_value === null) {
            return null;
        }

        return (float) $this->result_value >= (float) $this->specified_value;
    }
}
