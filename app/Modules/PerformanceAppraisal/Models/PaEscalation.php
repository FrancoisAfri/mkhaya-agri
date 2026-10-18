<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaEscalation extends Model
{
    protected $table = 'pa_escalations';
    protected $guarded = [];

    public function violation(): BelongsTo
    {
        return $this->belongsTo(PaPolicyViolation::class, 'violation_id');
    }
}
