<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmObjectType extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'crm_object_types';

    protected $fillable = [
        'uuid', 'name', 'slug', 'icon', 'record_label', 'fields', 'is_active', 'created_by',
    ];

    protected $casts = [
        'fields'    => 'array',
        'is_active' => 'boolean',
    ];

    const FIELD_TYPES = ['text', 'number', 'date', 'boolean', 'select', 'multiselect'];

    public function records(): HasMany
    {
        return $this->hasMany(CrmObjectRecord::class, 'object_type_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
