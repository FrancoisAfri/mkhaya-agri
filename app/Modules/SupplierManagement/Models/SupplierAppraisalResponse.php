<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierAppraisalResponse extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'supplier_appraisal_responses';

    protected $fillable = [
        'appraisal_id', 'question_library_id', 'supplier_id', 'rating', 'response', 'score', 'comment',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'rating' => 'integer',
    ];

    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(SupplierAppraisal::class, 'appraisal_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(SupplierQuestionLibrary::class, 'question_library_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
