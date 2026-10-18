<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One scheduling run: the rule it used and what it could not fit. */
class ScheduleRun extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_schedule_runs';

    protected $fillable = ['company_id', 'rule', 'horizon_days', 'summary', 'exceptions', 'run_by'];

    protected $casts = ['horizon_days' => 'integer', 'summary' => 'array', 'exceptions' => 'array'];
}
