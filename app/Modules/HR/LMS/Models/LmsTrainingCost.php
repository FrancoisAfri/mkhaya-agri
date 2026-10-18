<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsTrainingCost extends Model
{
    use KeepsDatabaseDefaults;

    protected $table = 'lms_training_costs';

    protected $fillable = [
        'course_id', 'cost_per_employee', 'currency',
        'period_start', 'period_end', 'notes', 'created_by',
    ];

    protected $casts = [
        'cost_per_employee' => 'float',
        'period_start'      => 'date',
        'period_end'        => 'date',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
