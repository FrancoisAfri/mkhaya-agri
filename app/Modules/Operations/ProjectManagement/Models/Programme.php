<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A set of projects delivering one outcome together (§2).
 *
 * `outcome` is the field that makes this more than a folder: a programme without a single stated
 * outcome is a portfolio somebody has mislabelled, and the distinction is what decides whether a
 * total belongs at this level or the one above.
 */
class Programme extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_programmes';

    protected $fillable = [
        'uuid', 'company_id', 'portfolio_id', 'code', 'name', 'description', 'outcome',
        'owner_user_id', 'starts_on', 'ends_on', 'budget_amount', 'currency', 'is_active',
    ];

    protected $casts = [
        'starts_on'     => 'date',
        'ends_on'       => 'date',
        'budget_amount' => 'decimal:2',
        'is_active'     => 'boolean',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class, 'portfolio_id');
    }

    public function projects(): HasMany
    {
        // `program_id` is the column Sprint 1 created; the model is spelled the long way.
        return $this->hasMany(Project::class, 'program_id');
    }
}
