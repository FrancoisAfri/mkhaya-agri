<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A recorded value, with a copy of the limits it was judged against. */
class InspectionResult extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_inspection_results';

    protected $fillable = [
        'company_id', 'inspection_id', 'characteristic_id', 'sample_no', 'name', 'kind', 'uom', 'target',
        'lower_limit', 'upper_limit', 'is_critical', 'measured_value', 'passed', 'notes', 'recorded_by', 'recorded_at',
        'defect_code_id',
    ];

    protected $casts = [
        'sample_no' => 'integer', 'target' => 'decimal:6', 'lower_limit' => 'decimal:6', 'upper_limit' => 'decimal:6',
        'is_critical' => 'boolean', 'measured_value' => 'decimal:6', 'passed' => 'boolean', 'recorded_at' => 'datetime',
    ];

    public function inspection(): BelongsTo { return $this->belongsTo(Inspection::class, 'inspection_id'); }
}
