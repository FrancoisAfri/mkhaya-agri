<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class ContractType extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes, KeepsDatabaseDefaults;

    protected $table = 'contract_types';

    protected $fillable = ['uuid', 'name', 'description', 'status'];

    protected $casts = ['status' => 'boolean'];

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
        static::creating(function (ContractType $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'contract_type_id');
    }
}
