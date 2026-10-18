<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ContractAppraisal extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes;

    protected $table = 'contract_appraisals';

    protected $fillable = [
        'uuid',
        'contract_id',
        'template_id',
        'user_id',
        'status',
        'total_score',
        'appraisal_date',
    ];

    protected $casts = [
        'total_score' => 'decimal:2',
        'appraisal_date' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Contract Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (ContractAppraisal $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractAppraisalTemplate::class, 'template_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ContractAppraisalResponse::class, 'appraisal_id');
    }
}
