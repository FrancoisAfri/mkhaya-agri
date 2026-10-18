<?php

namespace App\Core\Ribbon\Models;

use App\Core\Module\Models\Modules;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Ribbon extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'security_module_ribbons';

    protected $fillable = [
        'module_id',
        'sort_order',
        'ribbon_name',
        'font_awesome',
        'ribbon_path',
        'description',
        'access_level',
        'active',
        'uuid',
    ];

    /**
     * Relationship
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Modules::class, 'module_id');
    }

    /**
     * Activity Log Configuration (NEW STYLE)
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ModuleRibbon')
            ->logOnly([
                'module_id',
                'ribbon_name',
                'ribbon_path',
                'description',
                'access_level',
                'active'
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
        $module = $this->module?->name ?? 'Unknown Module';

        return match ($eventName) {
            'created' => "Ribbon '{$this->ribbon_name}' created in module '{$module}' by " . ($authUser?->name ?? 'System'),
            'updated' => "Ribbon '{$this->ribbon_name}' updated in module '{$module}' by " . ($authUser?->name ?? 'System'),
            'deleted' => "Ribbon '{$this->ribbon_name}' deleted from module '{$module}' by " . ($authUser?->name ?? 'System'),
            default => "Ribbon '{$this->ribbon_name}' event: {$eventName}",
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
            'module_id' => $this->module_id,
            'module_name' => $this->module?->name ?? null,

            'ribbon_name' => $this->ribbon_name,
            'ribbon_path' => $this->ribbon_path,
            'access_level' => $this->access_level,
            'access_label' => self::userRights()[$this->access_level] ?? null,
            'status' => $this->active ? 'active' : 'inactive',

            'event' => $eventName,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),

            // before / after
            'old' => $activity->properties['old'] ?? [],
            'attributes' => $activity->properties['attributes'] ?? [],
        ]);
    }

    /**
     * Access levels
     */
    public static function userRights(): array
    {
        return [
            0 => 'None',
            1 => 'Read',
            2 => 'Write',
            3 => 'Modify',
            4 => 'Admin',
            5 => 'SuperUser',
        ];
    }

    /**
     * Get ribbons by module
     */
    public static function getAllModuleRibbons($id)
    {
        return self::where('module_id', $id)
            ->orderBy('ribbon_name', 'asc')
            ->get();
    }
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'quick_access_users',
            'ribbon_id',
            'user_id'
        )->withTimestamps();
    }
}
