<?php

namespace App\Modules\Communication\ContentManagement\Models;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use App\Modules\Communication\ContentManagement\Models\AssetsFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Content extends Model
{
    use SoftDeletes, LogsActivity;

    /*
    |--------------------------------------------------------------------------
    | FILLABLE
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'title',
        'description',
        'summary',
        'icon',
        'event_start',
        'event_end',
        'event_date',
        'type',
        'meta_data',
        'user_id',
        'assets_file_id',
        'company_unit_id',
        'is_active',
        'expired_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTS
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'event_start' => 'datetime:H:i',
        'event_end'   => 'datetime:H:i',
        'event_date'  => 'datetime',
        'expired_at'  => 'datetime',
        'meta_data'   => 'array',
        'is_active'   => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

   public function author()
{
    return $this->belongsTo(User::class, 'user_id')
                ->with('person');
}

    public function asset()
    {
        return $this->belongsTo(AssetsFile::class, 'assets_file_id');
    }

    public function companyUnit()
    {
        return $this->belongsTo(CompanyUnit::class, 'company_unit_id');
    }
       public function reactions()
    {
        return $this->hasMany(Reaction::class, 'content_id');
    }

    /*
    |--------------------------------------------------------------------------
    | TYPE HELPERS
    |--------------------------------------------------------------------------
    */

    public function isAnnouncement(): bool
    {
        return $this->type === 1;
    }

    public function isNews(): bool
    {
        return $this->type === 2;
    }

    public function isEvent(): bool
    {
        return $this->type === 3;
    }

    public function isInternal(): bool
    {
        return $this->type === 4;
    }

    /*
    |--------------------------------------------------------------------------
    | TYPE LABEL
    |--------------------------------------------------------------------------
    */

    protected function getTypeLabel(): string
    {
        return match ($this->type) {
            1 => 'Announcement',
            2 => 'News article',
            3 => 'Event',
            4 => 'Internal Notice',
            default => 'Content',
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
            ->useLogName('Content')
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
        $actor     = Auth::user()?->name ?? 'System';
        $typeLabel = $this->getTypeLabel();
        $title     = $this->title ?? 'Untitled';
        $audience  = $this->isInternal() ? 'internal' : 'public';

        // Extra context for events
        $eventRange = '';
        if ($this->isEvent() && $this->event_start) {
            $eventRange = $this->event_end
                ? ' scheduled from ' . $this->event_start->format('H:i') . ' to ' . $this->event_end->format('H:i')
                : ' scheduled at ' . $this->event_start->format('H:i');
        }

        return match ($eventName) {
            'created' => "{$actor} created a new {$audience} {$typeLabel}: \"{$title}\"{$eventRange}.",

            'updated' => (function () use ($actor, $typeLabel, $title, $audience, $eventRange) {
                $changes = collect($this->getDirty())
                    ->except(['updated_at'])
                    ->keys()
                    ->map(fn($field) => match ($field) {
                        'title'          => 'the title',
                        'description'    => 'the description',
                        'summary'        => 'the summary',
                        'icon'           => 'the icon',
                        'event_start'    => 'the start time',
                        'event_end'      => 'the end time',
                        'event_date'     => 'the date of event',
                        'type'           => "the content type (now {$typeLabel})",
                        'meta_data'      => 'the metadata',
                        'assets_file_id' => 'the attached media',
                        'is_active'      => 'status change',
                        default          => $field,
                    })
                    ->join(', ', ' and ');

                $summary = $changes
                    ? "Updated {$changes} on"
                    : 'Made changes to';

                return "{$actor} — {$summary} the {$audience} {$typeLabel} \"{$title}\"{$eventRange}.";
            })(),

            'deleted' => "{$actor} removed the {$audience} {$typeLabel} \"{$title}\" from the platform.",

            default   => "{$actor} triggered a \"{$eventName}\" event on the {$audience} {$typeLabel} \"{$title}\".",
        };
    }
}
