<?php

namespace App\Modules\Communication\ContentManagement\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Reaction extends Model
{
    use SoftDeletes, LogsActivity;


    protected $table = 'reactions';
    protected $fillable = [
        'content_id',
        'employee_spotlight_id',
        'like',
        'comment',
    ];


    protected $casts = [
        'like' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function content()
    {
        return $this->belongsTo(Content::class, 'content_id');
    }

    public function employeeSpotlight()
    {
        return $this->belongsTo(EmployeeSpotlight::class, 'employee_spotlight_id');
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'reaction_user',
            'reaction_id',
            'user_id'
        )->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    public function hasComment(): bool
    {
        return !empty($this->comment);
    }

    public function isLike(): bool
    {
        return $this->like === true;
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Reaction')
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
        $actor = trim(
            (Auth::user()?->person?->first_name ?? '') . ' ' .
                (Auth::user()?->person?->surname ?? '')
        );

        $actor = $actor ?: 'System';

        $target = $this->content
            ? 'content'
            : ($this->employeeSpotlight ? 'employee spotlight' : 'item');

        return match ($eventName) {

            'created' =>
            $this->hasComment()
                ? "{$actor} added a comment reaction on a {$target}."
                : "{$actor} reacted with a like on a {$target}.",

            'updated' =>
            "{$actor} updated a reaction on a {$target}.",

            'deleted' =>
            "{$actor} removed a reaction from a {$target}.",

            default =>
            "{$actor} triggered a \"{$eventName}\" event on a reaction.",
        };
    }
}
