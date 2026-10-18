<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmSavedView extends Model
{
    use LogsActivity;

    protected $table = 'crm_saved_views';

    protected $fillable = [
        'uuid', 'user_id', 'entity', 'name', 'filters', 'is_shared', 'sort_order',
    ];

    protected $casts = [
        'filters'    => 'array',
        'is_shared'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Views the given user may see for an entity: their own + shared. */
    public function scopeVisibleTo($query, int $userId, string $entity)
    {
        return $query->where('entity', $entity)
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('is_shared', true));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
