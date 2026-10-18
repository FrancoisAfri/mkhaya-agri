<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmCustomField extends Model
{
    use LogsActivity;

    protected $table = 'crm_custom_fields';

    protected $fillable = [
        'entity_type', 'name', 'label', 'field_type', 'options', 'is_required', 'sort_order', 'active',
    ];

    protected $casts = [
        'options'     => 'array',
        'is_required' => 'boolean',
        'active'      => 'boolean',
        'sort_order'  => 'integer',
    ];

    const ENTITY_TYPES = ['lead', 'contact', 'account', 'deal'];
    const FIELD_TYPES  = ['text', 'number', 'date', 'boolean', 'select', 'multiselect'];

    public function values(): HasMany
    {
        return $this->hasMany(CrmCustomFieldValue::class, 'custom_field_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
