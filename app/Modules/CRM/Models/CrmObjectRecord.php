<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmObjectRecord extends Model
{
    use SoftDeletes;

    protected $table = 'crm_object_records';

    protected $fillable = [
        'uuid', 'object_type_id', 'data', 'created_by',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function objectType(): BelongsTo
    {
        return $this->belongsTo(CrmObjectType::class, 'object_type_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
