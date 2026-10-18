<?php

namespace App\Core\Module\Models;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ModuleAccess extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'security_module_access';

    protected $fillable = [
        'module_id',
        'user_id',
        'active',
        'access_level',
    ];

    /**
     * Relationships
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Modules::class, 'module_id');
    }

    public function user(): BelongsTo
    {
      return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Activity Log Configuration (NEW STYLE ONLY)
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ModuleAccess')
            ->logOnly(['module_id', 'user_id', 'active', 'access_level'])
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
        $targetUser = $this->user?->full_name ?? 'Unknown User';
        $module = $this->module?->name ?? 'Unknown Module';

        return match ($eventName) {
            'created' => "Access granted to '{$targetUser}' for module '{$module}' by " . ($authUser?->name ?? 'System'),
            'updated' => "Access updated for '{$targetUser}' on module '{$module}' by " . ($authUser?->name ?? 'System'),
            'deleted' => "Access revoked for '{$targetUser}' on module '{$module}' by " . ($authUser?->name ?? 'System'),
            default => "Module access {$eventName} event",
        };
    }

    /**
     * Add extra metadata (VERY IMPORTANT)
     */
    public function tapActivity(\Spatie\Activitylog\Models\Activity $activity, string $eventName)
    {
        $authUser = Auth::user();

        $activity->causer_id = $authUser?->id;
        $activity->causer_type = $authUser ? get_class($authUser) : null;

        $activity->properties = $activity->properties->merge([
            'module_id' => $this->module_id,
            'module_name' => $this->module?->name ?? null,

            'target_user_id' => $this->user_id,
            'target_user_name' => $this->user?->full_name ?? null,

            'access_level' => $this->access_level,
            'status' => $this->active ? 'active' : 'inactive',

            'event' => $eventName,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),

            // Before / After values
            'old' => $activity->properties['old'] ?? [],
            'attributes' => $activity->properties['attributes'] ?? [],
        ]);
    }

    /**
     * Custom queries
     */
    public function getAdminUsers($id)
    {
        return DB::table('security_module_access')
            ->select('security_module_access.user_id')
            ->leftJoin('security_modules', 'security_module_access.module_id', '=', 'security_modules.id')
            ->where('security_modules.code_name', 'security')
            ->where('security_module_access.access_level', '>=', 4)
            ->where('security_module_access.user_id', $id)
            ->pluck('user_id')
            ->first();
    }

    public static function getAllModuleAccess()
    {
        return self::orderBy('id', 'asc')->get();
    }
}
