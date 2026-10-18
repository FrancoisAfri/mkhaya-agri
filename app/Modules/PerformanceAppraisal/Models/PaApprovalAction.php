<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaApprovalAction extends Model
{
    use LogsActivity;
    protected $table = 'pa_approval_actions';
    protected $guarded = [];

    protected $casts = [
        'actioned_at' => 'datetime',
        'step_number' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Approval Action recorded',
                'updated' => 'Approval Action updated',
                'deleted' => 'Approval Action deleted',
                default   => $e,
            });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PaApprovalRequest::class, 'request_id');
    }
}
