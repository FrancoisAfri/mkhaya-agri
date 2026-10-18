<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmCustomFieldValue extends Model
{
    protected $table = 'crm_custom_field_values';

    protected $fillable = [
        'custom_field_id', 'entity_type', 'entity_id', 'value',
    ];

    public function field(): BelongsTo
    {
        return $this->belongsTo(CrmCustomField::class, 'custom_field_id');
    }
}
