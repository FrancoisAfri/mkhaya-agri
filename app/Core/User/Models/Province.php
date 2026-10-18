<?php

namespace App\Core\User\Models;

use App\Core\User\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Province extends Model
{
    use LogsActivity;

    public $table = 'provinces';

    protected $fillable = [
        'name',
        'abbreviation',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Province')
            ->logOnly(['name', 'abbreviation', 'country_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(
                fn(string $eventName) => $this->getDescriptionForEvent($eventName)
            );
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor       = Auth::user()?->name ?? 'System';
        $name        = $this->name ?? 'Unnamed province';
        $abbreviation = $this->abbreviation ? " ({$this->abbreviation})" : '';
        $countryName = $this->country?->name ?? 'an unknown country';

        return match ($eventName) {
            'created' => "{$actor} added the province \"{$name}\"{$abbreviation} under {$countryName}.",

            'updated' => (function () use ($actor, $name, $abbreviation, $countryName) {
                $changes = collect($this->getDirty())
                    ->except(['updated_at'])
                    ->keys()
                    ->map(fn($field) => match ($field) {
                        'name'         => 'the province name',
                        'abbreviation' => "the abbreviation (now {$this->abbreviation})",
                        'country_id'   => "the country (now {$countryName})",
                        default        => $field,
                    })
                    ->join(', ', ' and ');

                $summary = $changes
                    ? "Updated {$changes} on"
                    : 'Made changes to';

                return "{$actor} — {$summary} the province \"{$name}\"{$abbreviation}.";
            })(),

            'deleted' => "{$actor} removed the province \"{$name}\"{$abbreviation} from {$countryName}.",

            default   => "{$actor} triggered a \"{$eventName}\" event on the province \"{$name}\"{$abbreviation}.",
        };
    }
}
