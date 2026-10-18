<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsEffectivenessReport extends Model
{
    protected $table = 'lms_effectiveness_reports';

    protected $fillable = [
        'title', 'report_type', 'scope_type', 'scope_id',
        'period_start', 'period_end', 'metrics', 'generated_by', 'generated_at',
    ];

    protected $casts = [
        'period_start'  => 'date',
        'period_end'    => 'date',
        'metrics'       => 'array',
        'generated_at'  => 'datetime',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
