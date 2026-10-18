<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use App\Shared\Traits\KeepsDatabaseDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmKpi extends Model
{
    use LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'crm_kpis';

    protected $fillable = [
        'uuid', 'name', 'entity', 'metric', 'field', 'filters', 'format', 'color', 'sort_order', 'created_by', 'pin_to_dashboard',
    ];

    protected $casts = [
        'filters'          => 'array',
        'sort_order'       => 'integer',
        'pin_to_dashboard' => 'boolean',
    ];


    const METRICS = ['count', 'sum', 'avg', 'min', 'max'];
    const FORMATS = ['number', 'currency', 'percent'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
