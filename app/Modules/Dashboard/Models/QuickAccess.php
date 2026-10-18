<?php

namespace App\Modules\Dashboard\Models;

use App\Core\Ribbon\Models\Ribbon;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class QuickAccess extends Model
{
    use LogsActivity;

    protected $table = 'quick_access';

    protected $fillable = ['ribbon_id'];

    /**
     * Get the ribbon this quick access is for
     */
    public function ribbon(): BelongsTo
    {
        return $this->belongsTo(Ribbon::class, 'ribbon_id');
    }

    /**
     * Get the users who have this quick access pinned to their bar
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'quick_access_users',
            'quick_access_id',
            'user_id'
        )->withPivot('name')
          ->withTimestamps();
    }

    /**
     * Get quick access with ribbon details
     */
    public function getRibbonDetails()
    {
        return $this->ribbon()->with('module')->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Quick Access')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(
                fn(string $eventName) => $this->getDescriptionForEvent($eventName)
            );
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor = Auth::user()?->person
            ? Auth::user()->person->first_name . ' ' . (Auth::user()->person->surname ?? '')
            : 'System';

        $ribbonName = $this->ribbon?->ribbon_name ?? 'Unknown';

        return match ($eventName) {
            'created' => "{$actor} created a quick access for ribbon: \"{$ribbonName}\".",
            'updated' => "{$actor} updated the quick access for ribbon \"{$ribbonName}\".",
            'deleted' => "{$actor} deleted the quick access for ribbon \"{$ribbonName}\".",
            default   => "{$actor} triggered {$eventName} on quick access for ribbon \"{$ribbonName}\".",
        };
    }
}
