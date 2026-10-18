<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Province extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'country_id',
    ];

    /**
     * Relationship: A province belongs to a country
     */
    public function country()
    {
        return $this->belongsTo(Country::class);
    }
            public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->useLogName('province')
        ->logFillable()
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs()
        ->dontLogIfAttributesChangedOnly(['updated_at'])
        ->setDescriptionForEvent(fn (string $eventName) =>
            $this->getDescriptionForEvent($eventName)
        );
}
protected function getDescriptionForEvent(string $eventName): string
{
    $user = Auth::user();

    return match ($eventName) {
        'created' => "Province '{$this->name}' created by " . ($user?->name ?? 'System'),
        'updated' => "Province '{$this->name}' updated by " . ($user?->name ?? 'System'),
        'deleted' => "Province '{$this->name}' deleted by " . ($user?->name ?? 'System'),
        default => "Province {$eventName} event",
    };
}
}
