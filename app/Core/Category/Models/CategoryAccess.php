<?php

namespace App\Core\Category\Models;

use App\Core\User\Models\User;
use App\Core\Category\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\Models\Activity;

class CategoryAccess extends Model
{
    use LogsActivity;

    protected $table = 'security_category_access';

    protected $fillable = [
        'user_id',
        'category_id',
        'access_level',
        'active',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG CONFIGURATION
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('CategoryAccess')
            ->logOnly(['user_id', 'category_id', 'access_level', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(
                fn(string $eventName) => $this->getDescriptionForEvent($eventName)
            );
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS — résolution sûre des noms
    |--------------------------------------------------------------------------
    */

    protected function resolveUserName(): string
    {
        if ($this->relationLoaded('user') && $this->user) {
            return $this->user->name;
        }

        return User::find($this->user_id)?->name ?? 'Unknown User';
    }

    protected function resolveCategoryName(): string
    {
        if ($this->relationLoaded('category') && $this->category) {
            return $this->category->name;
        }

        return Category::find($this->category_id)?->name ?? 'Unknown Category';
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOM LOG DESCRIPTIONS
    |--------------------------------------------------------------------------
    */

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor      = Auth::user()?->person?->surname ?? 'System';
        $targetUser = $this->resolveUserName();
        $category   = $this->resolveCategoryName();

        return match ($eventName) {
            'created' => "{$actor} granted access to '{$targetUser}' for category '{$category}'.",

            'updated' => (function () use ($actor, $targetUser, $category) {
                $changes = collect($this->getDirty())
                    ->except(['updated_at'])
                    ->keys()
                    ->map(fn($field) => match ($field) {
                        'access_level' => "access level (now {$this->access_level})",
                        'active'       => "status (" . ($this->active ? 'active' : 'inactive') . ")",
                        default        => $field,
                    })
                    ->join(', ', ' and ');

                $summary = $changes ?: "made changes";

                return "{$actor} {$summary} for '{$targetUser}' on category '{$category}'.";
            })(),

            'deleted' => "{$actor} revoked access for '{$targetUser}' on category '{$category}'.",

            default => "{$actor} triggered '{$eventName}' on category access.",
        };
    }

    /*
    |--------------------------------------------------------------------------
    | TAP ACTIVITY (EXTRA METADATA)
    |--------------------------------------------------------------------------
    */

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $authUser = Auth::user();

        $activity->causer_id   = $authUser?->id;
        $activity->causer_type = $authUser ? get_class($authUser) : null;

        $activity->properties = $activity->properties->merge([
            'target_user_id'   => $this->user_id,
            'target_user_name' => $this->resolveUserName(),
            'category_id'      => $this->category_id,
            'category_name'    => $this->resolveCategoryName(),
            'access_level'     => $this->access_level,
            'status'           => $this->active ? 'active' : 'inactive',

            'event'      => $eventName,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),

            'old'        => $activity->properties['old'] ?? [],
            'attributes' => $activity->properties['attributes'] ?? [],
        ]);
    }
}
