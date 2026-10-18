<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** How sensitive one loss detector is, for one company (MFG-012). */
class LossRule extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany;

    protected $table = 'mfg_loss_rules';

    protected $fillable = ['company_id', 'category', 'is_active', 'threshold', 'min_count', 'min_impact', 'lookback_days', 'approval_over', 'due_days'];

    protected $casts = ['is_active' => 'boolean', 'threshold' => 'float', 'min_count' => 'integer', 'min_impact' => 'float',
        'lookback_days' => 'integer', 'approval_over' => 'float', 'due_days' => 'integer'];
}
