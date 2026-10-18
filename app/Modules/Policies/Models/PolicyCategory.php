<?php

namespace App\Modules\Policies\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PolicyCategory extends Model
{
    use LogsActivity;
    protected $table = 'policy_category';

    protected $fillable = [
        'name',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function policies()
    {
        return $this->hasMany(Policy::class, 'category_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('PolicyCategory')
            ->logOnly(['name', 'description', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor = Auth::user()?->name ?? 'System';
        $name  = $this->name ?? 'Unnamed category';
        return match ($eventName) {
            'created' => "{$actor} created policy category \"{$name}\".",
            'updated' => "{$actor} updated policy category \"{$name}\".",
            'deleted' => "{$actor} deleted policy category \"{$name}\".",
            default   => "{$actor} triggered \"{$eventName}\" on policy category \"{$name}\".",
        };
    }
}
