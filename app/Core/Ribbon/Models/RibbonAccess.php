<?php

namespace App\Core\Ribbon\Models;

use App\Core\Ribbon\Models\Ribbon;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RibbonAccess extends Model
{
    use LogsActivity;

    protected $table = 'security_ribbons_access';

    protected $fillable = [
        'ribbon_id',
        'access_level',
        'user_id',
        'description'
    ];

    /**
     * Relationships
     */
    public function ribbon()
    {
        return $this->belongsTo(Ribbon::class, 'ribbon_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Activity Log Configuration
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('RibbonAccess')
            ->logOnly([
                'ribbon_id',
                'user_id',
                'access_level',
                'description'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    /**
     * Human-readable descriptions
     */
    protected function getDescriptionForEvent(string $eventName): string
    {
        $authUser = Auth::user();
        $targetUser = $this->user?->name ?? 'Unknown User';
        $ribbon = $this->ribbon?->ribbon_name ?? 'Unknown Ribbon';

        return match ($eventName) {
            'created' => "Ribbon access granted to '{$targetUser}' for '{$ribbon}' by " . ($authUser?->name ?? 'System'),
            'updated' => "Ribbon access updated for '{$targetUser}' on '{$ribbon}' by " . ($authUser?->name ?? 'System'),
            'deleted' => "Ribbon access revoked for '{$targetUser}' on '{$ribbon}' by " . ($authUser?->name ?? 'System'),
            default => "Ribbon access {$eventName}",
        };
    }

    /**
     * Add extra metadata
     */
    public function tapActivity(\Spatie\Activitylog\Models\Activity $activity, string $eventName)
    {
        $authUser = Auth::user();

        $activity->causer_id = $authUser?->id;
        $activity->causer_type = $authUser ? get_class($authUser) : null;

        $activity->properties = $activity->properties->merge([
            'ribbon_id' => $this->ribbon_id,
            'ribbon_name' => $this->ribbon?->ribbon_name ?? null,

            'target_user_id' => $this->user_id,
            'target_user_name' => $this->user?->name ?? null,

            'access_level' => $this->access_level,
            'access_label' => Ribbon::userRights()[$this->access_level] ?? null,

            'description' => $this->description,

            'event' => $eventName,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),

            // before / after
            'old' => $activity->properties['old'] ?? [],
            'attributes' => $activity->properties['attributes'] ?? [],
        ]);
    }
}
