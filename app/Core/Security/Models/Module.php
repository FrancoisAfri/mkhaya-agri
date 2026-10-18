<?php

namespace App\Core\Security\Models;

use App\Core\Category\Models\Category;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Ribbon\Models\Ribbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Module extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'security_modules';

    protected $fillable = [
        'category_id',
        'name',
        'path',
        'active',
        'font_awesome',
        'code_name',
        'uuid'
    ];

    /**
     * Relationships
     */
    public function moduleRibbons()
    {
        return $this->hasMany(Ribbon::class, 'module_id');
    }

    public function moduleAccess()
    {
        return $this->hasOne(ModuleAccess::class, 'module_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Activity Log Configuration (NEW STYLE)
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Modules')
            ->logOnly([
                'category_id',
                'name',
                'path',
                'active',
                'code_name'
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
        $category = $this->category?->name ?? 'Unknown Category';

        return match ($eventName) {
            'created' => "Module '{$this->name}' created in category '{$category}' by " . ($authUser?->name ?? 'System'),
            'updated' => "Module '{$this->name}' updated in category '{$category}' by " . ($authUser?->name ?? 'System'),
            'deleted' => "Module '{$this->name}' deleted from category '{$category}' by " . ($authUser?->name ?? 'System'),
            default => "Module '{$this->name}' event: {$eventName}",
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
            'module_id' => $this->id,
            'module_name' => $this->name,
            'code_name' => $this->code_name,

            'category_id' => $this->category_id,
            'category_name' => $this->category?->name ?? null,

            'path' => $this->path,
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
     * Business methods
     */
    public function addRibbon(Ribbon $ribbon)
    {
        return $this->moduleRibbons()->save($ribbon);
    }

    public function addModuleAccess(ModuleAccess $module)
    {
        return $this->moduleAccess()->save($module);
    }

    /**
     * Queries
     */
    public static function getAllModules(): Collection
    {
        return self::orderBy('id', 'asc')->get();
    }

    public static function getAllModulesOrderedByName()
    {
        return self::orderBy('name', 'asc')->get();
    }

    public static function getModuleByUuid($id)
    {
        return self::where('uuid', $id)->first();
    }

    public function scopeAccessibleByLoggedInUser($query)
    {
        return $query->whereHas('moduleAccess', function ($q) {
            $q->where('user_id', Auth::id())
                ->where('access_level', '>', 0)
                ->where('active', 1);
        });
    }
}
