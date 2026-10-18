<?php

namespace App\Core\CompanyUnits\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CompanyUnit extends Model
{
    use LogsActivity, BelongsToCompany;

    protected $table    = 'company_units';
    protected $fillable = ['name', 'parent_id', 'company_id', 'type', 'sort_order', 'active', 'user_id'];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function childrenRecursive(): HasMany
    {
        return $this->children()->with(['childrenRecursive', 'user.person', 'assignedUsers.person']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedUsers()
    {
        return $this->belongsToMany(
            User::class,
            'company_unit_user_pivots',
            'company_id',
            'user_id'
        )->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    protected function getTypeLabel(): string
    {
        return match ($this->type) {
            'department'  => 'department',
            'team'        => 'team',
            'division'    => 'division',
            'branch'      => 'branch',
            'subsidiary'  => 'subsidiary',
            default       => 'unit',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Organisation')
            ->logOnly(['name', 'parent_id', 'type', 'sort_order', 'user_id'])
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
        $name       = $this->name ?? 'Unnamed unit';
        $typeLabel  = $this->getTypeLabel();
        $parentName = $this->parent?->name;
        $parentTxt  = $parentName ? " under \"{$parentName}\"" : ' at the top level';
        $manager    = $this->user?->name;
        $managerTxt = $manager ? ", managed by {$manager}," : '';
        $childCount = $this->children()->count();
        $childTxt   = $childCount > 0
            ? " It has {$childCount} sub-unit" . ($childCount > 1 ? 's' : '') . ' attached.'
            : '';

        return match ($eventName) {
            'created' => "{$actor} created a new {$typeLabel} \"{$name}\"{$parentTxt}{$managerTxt} in the organisational chart.",

            'updated' => (function () use ($actor, $name, $typeLabel, $parentTxt) {
                $dirty = collect($this->getDirty())->except(['updated_at'])->keys();

                $changes = $dirty->map(fn($field) => match ($field) {
                    'name'       => 'the unit name',
                    'parent_id'  => "the parent unit (now {$parentTxt})",
                    'type'       => "the unit type (now {$typeLabel})",
                    'sort_order' => 'the display order',
                    'user_id'    => 'the unit manager',
                    'active'     => 'the active status',
                    default      => $field,
                })->join(', ', ' and ');

                $summary = $changes
                    ? "Updated {$changes} on"
                    : 'Made changes to';

                return "{$actor} — {$summary} the {$typeLabel} \"{$name}\"{$parentTxt}.";
            })(),

            'deleted' => "{$actor} removed the {$typeLabel} \"{$name}\" from the organisational chart.{$childTxt}",

            default   => "{$actor} triggered a \"{$eventName}\" event on the {$typeLabel} \"{$name}\".",
        };
    }
}
