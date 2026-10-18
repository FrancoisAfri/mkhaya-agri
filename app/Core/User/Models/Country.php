<?php

namespace App\Core\User\Models;

use App\Core\User\Models\Province;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Country extends Model
{
    use LogsActivity;

    public $table = 'countries';

    protected $fillable = [
        'name',
        'a2_code',
        'a3_code',
        'numeric_code',
        'dialing_code',
        'abbreviation',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function province()
    {
        return $this->hasMany(Province::class, 'country_id');
    }

    public function addProvince(Province $province)
    {
        return $this->province()->save($province);
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Country')
            ->logOnly(['name', 'a2_code', 'a3_code', 'numeric_code', 'dialing_code', 'abbreviation'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(
                fn(string $eventName) => $this->getDescriptionForEvent($eventName)
            );
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor         = Auth::user()?->name ?? 'System';
        $name          = $this->name ?? 'Unnamed country';
        $codes         = collect([
                            $this->a2_code,
                            $this->a3_code,
                            $this->numeric_code ? "#{$this->numeric_code}" : null,
                        ])->filter()->join(' / ');
        $codeTxt       = $codes ? " [{$codes}]" : '';
        $dialingTxt    = $this->dialing_code ? ", dialing code +{$this->dialing_code}" : '';
        $provinceCount = $this->province()->count();
        $provinceTxt   = $provinceCount > 0
            ? " It has {$provinceCount} province" . ($provinceCount > 1 ? 's' : '') . ' registered.'
            : '';

        return match ($eventName) {
            'created' => "{$actor} added \"{$name}\"{$codeTxt} to the platform{$dialingTxt}.",

            'updated' => (function () use ($actor, $name, $codeTxt) {
                $changes = collect($this->getDirty())
                    ->except(['updated_at'])
                    ->keys()
                    ->map(fn($field) => match ($field) {
                        'name'         => 'the country name',
                        'a2_code'      => "the ISO 2-letter code (now {$this->a2_code})",
                        'a3_code'      => "the ISO 3-letter code (now {$this->a3_code})",
                        'numeric_code' => "the numeric code (now {$this->numeric_code})",
                        'dialing_code' => "the dialing code (now +{$this->dialing_code})",
                        'abbreviation' => "the abbreviation (now {$this->abbreviation})",
                        default        => $field,
                    })
                    ->join(', ', ' and ');

                $summary = $changes
                    ? "Updated {$changes} on"
                    : 'Made changes to';

                return "{$actor} — {$summary} the country \"{$name}\"{$codeTxt}.";
            })(),

            'deleted' => "{$actor} removed the country \"{$name}\"{$codeTxt} from the platform.{$provinceTxt}",

            default   => "{$actor} triggered a \"{$eventName}\" event on the country \"{$name}\"{$codeTxt}.",
        };
    }
}
