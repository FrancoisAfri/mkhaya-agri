<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ContractReview extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'contract_reviews';

    protected $fillable = [
        'contract_id',
        'reviewer_id',
        'reviewer_name',
        'comment',
        'status',
        'feedback',
        'feedback_category',
        'complexity_rating',
        'review_time_minutes',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'review_time_minutes' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Contract Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
