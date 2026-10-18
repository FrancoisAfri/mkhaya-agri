<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmComment extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'crm_comments';

    protected $fillable = [
        'uuid', 'entity_type', 'entity_id', 'entity_label', 'parent_id', 'body', 'mentioned', 'author_id',
    ];

    protected $casts = [
        'mentioned' => 'array',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CrmComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CrmComment::class, 'parent_id')->oldest();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
