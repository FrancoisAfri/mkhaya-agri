<?php

namespace App\Modules\PerformanceAppraisal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaApprovalRequest extends Model
{
    use LogsActivity;
    protected $table = 'pa_approval_requests';
    protected $guarded = [];

    protected $casts = [
        'current_step' => 'integer',
        'total_steps'  => 'integer',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(PaApprovalWorkflow::class, 'workflow_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PaApprovalAction::class, 'request_id')->orderBy('step_number');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'Approval Request raised',
                'updated' => 'Approval Request updated',
                'deleted' => 'Approval Request deleted',
                default   => $e,
            });
    }

    public function progressPct(): int
    {
        if ($this->total_steps === 0) return 0;
        return (int) round((($this->current_step - 1) / $this->total_steps) * 100);
    }
}
