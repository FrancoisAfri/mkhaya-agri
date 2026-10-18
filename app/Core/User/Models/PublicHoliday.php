<?php

namespace App\Core\User\Models;

use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PublicHoliday extends Model
{
    use LogsActivity;

    public $table = 'public_holidays';

    protected $fillable = [
        'company_id',
        'day',
        'country_id',
        'year',
        'name',
    ];

    /**
     * Tenant-aware with a shared fallback: a NULL company_id row is a global
     * default every tenant sees; a tenant only additionally sees its own rows.
     * New holidays created inside a tenant context are stamped to that company.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenantOrShared', function ($builder) {
            $current = app(CurrentCompany::class);
            if ($current->isSet()) {
                $builder->where(function ($q) use ($current) {
                    $q->whereNull('public_holidays.company_id')
                      ->orWhere('public_holidays.company_id', $current->id());
                });
            }
        });

        static::creating(function (self $model) {
            if ($model->company_id === null) {
                $current = app(CurrentCompany::class);
                if ($current->isSet()) {
                    $model->company_id = $current->id();
                }
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Public Holiday')
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
    $actor       = Auth::user()?->name ?? 'System';
    $holidayName = $this->name ?? 'Unnamed holiday';
    $day         = $this->day
        ? \Carbon\Carbon::createFromFormat('m-d', $this->day)->format('l, d F Y')
        : 'an unspecified date';
    $year        = $this->year ?? ($this->day
        ? \Carbon\Carbon::createFromFormat('m-d', $this->day)->year
        : 'unknown year');

    return match ($eventName) {
        'created' => "{$actor} added \"{$holidayName}\" as a public holiday on {$day} for the {$year} calendar.",

        'updated' => (function () use ($actor, $holidayName, $day, $year) {
            $changes = collect($this->getDirty())
                ->except(['updated_at'])
                ->keys()
                ->map(fn($field) => match ($field) {
                    'name'       => 'the holiday name',
                    'day'        => "the date (now {$day})",
                    'year'       => "the year (now {$year})",
                    'country_id' => 'the country',
                    default      => $field,
                })
                ->join(', ', ' and ');

            $summary = $changes
                ? "Updated {$changes} on"
                : 'Made changes to';

            return "{$actor} — {$summary} the public holiday \"{$holidayName}\".";
        })(),

        'deleted' => "{$actor} removed \"{$holidayName}\" ({$day}) from the {$year} public holiday calendar.",

        default   => "{$actor} triggered a \"{$eventName}\" event on the public holiday \"{$holidayName}\".",
    };
}
}
