<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ContractAppraisalTemplate extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes;

    protected $table = 'contract_appraisal_templates';

    protected $fillable = ['uuid', 'name', 'status'];

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
        static::creating(function (ContractAppraisalTemplate $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ContractAppraisalTemplateQuestion::class, 'template_id');
    }
}
