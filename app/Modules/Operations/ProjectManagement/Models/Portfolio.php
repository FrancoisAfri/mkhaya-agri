<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A set of programmes and projects competing for the same money (§2).
 *
 * The question a portfolio answers is "are we spending on the right things", which is why it
 * carries a budget of its own: without a denominator, a portfolio is a folder.
 */
class Portfolio extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_portfolios';

    protected $fillable = [
        'uuid', 'company_id', 'code', 'name', 'description',
        'owner_user_id', 'budget_amount', 'currency', 'is_active',
    ];

    protected $casts = ['budget_amount' => 'decimal:2', 'is_active' => 'boolean'];

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class, 'portfolio_id');
    }

    /** Projects hung directly off the portfolio, without a programme in between. */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'portfolio_id');
    }
}
