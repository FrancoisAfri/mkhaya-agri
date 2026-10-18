<?php

namespace App\Core\Category\Models;

use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\Modules;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class Category extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'security_categories';

    protected $fillable = [
        'name',
        'active',
        'icon',
        'description',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function modules()
    {
        return $this->hasMany(Modules::class, 'category_id');
    }

    public function categoryAccess()
    {
        return $this->hasMany(CategoryAccess::class, 'category_id');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function giveSuperAdminAccessToCategory(int $categoryId): void
    {
        $superAdmins = User::role('super admin')->get();

        foreach ($superAdmins as $user) {
            CategoryAccess::updateOrCreate(
                [
                    'user_id'     => $user->id,
                    'category_id' => $categoryId,
                ],
                [
                    'access_level' => 5,
                    'active'       => true,
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Category')
            ->logOnly(['name', 'active', 'icon', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(
                fn(string $eventName) => $this->getDescriptionForEvent($eventName)
            );
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor      = Auth::user()?->name ?? 'System';
        $name       = $this->name ?? 'Unnamed category';
        $status     = $this->active ? 'active' : 'inactive';
        $moduleCount = $this->modules()->count();
        $moduleTxt  = $moduleCount > 0
            ? " It contains {$moduleCount} module" . ($moduleCount > 1 ? 's' : '') . '.'
            : '';

        return match ($eventName) {
            'created' => "{$actor} created a new category \"{$name}\" — currently {$status}. Modules can now be assigned to it.",

            'updated' => (function () use ($actor, $name, $status) {
                $changes = collect($this->getDirty())
                    ->except(['updated_at'])
                    ->keys()
                    ->map(fn($field) => match ($field) {
                        'name'        => 'the category name',
                        'active'      => "the visibility (now {$status})",
                        'icon'        => 'the icon',
                        'description' => 'the description',
                        default       => $field,
                    })
                    ->join(', ', ' and ');

                $summary = $changes
                    ? "Updated {$changes} on"
                    : 'Made changes to';

                return "{$actor} — {$summary} the category \"{$name}\".";
            })(),

            'deleted' => "{$actor} deleted the category \"{$name}\" from the platform.{$moduleTxt}",

            default   => "{$actor} triggered a \"{$eventName}\" event on the category \"{$name}\".",
        };
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $user = Auth::user();

        $activity->causer_id   = $user?->id;
        $activity->causer_type = $user ? get_class($user) : null;

        $activity->properties = $activity->properties->merge([
            'category_id'   => $this->id,
            'category_name' => $this->name,
            'event'         => $eventName,
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
            'old'           => $activity->properties['old'] ?? [],
            'attributes'    => $activity->properties['attributes'] ?? [],
        ]);
    }
}
