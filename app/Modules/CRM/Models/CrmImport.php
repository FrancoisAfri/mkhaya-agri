<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmImport extends Model
{
    use LogsActivity;

    protected $table = 'crm_imports';

    protected $fillable = [
        'uuid', 'entity_type', 'file_name', 'source', 'mapping', 'dedup_strategy',
        'total_rows', 'created_count', 'updated_count', 'skipped_count', 'duplicate_count',
        'errors', 'status', 'created_by', 'reversed_at', 'reversed_by',
    ];

    protected $casts = [
        'mapping'     => 'array',
        'errors'      => 'array',
        'reversed_at' => 'datetime',
    ];

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
