<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The daily site record (EPIC-100).
 *
 * The first document anybody reaches for in a delay claim, and worthless unless it was written on
 * the day. One per site per day, enforced by the database: two accounts of the same day is two
 * versions of what happened, and a claim built on the wrong one falls apart under scrutiny.
 */
class SiteDiary extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_site_diaries';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'entry_date', 'weather', 'temperature_c', 'rain_hours',
        'workers_on_site', 'work_done', 'plant_on_site', 'deliveries', 'visitors', 'delays',
        'instructions', 'safety_notes', 'recorded_by', 'submitted_at',
    ];

    protected $casts = [
        'entry_date'      => 'date',
        'temperature_c'   => 'decimal:1',
        'rain_hours'      => 'integer',
        'workers_on_site' => 'integer',
        'submitted_at'    => 'datetime',
    ];

    /** Conditions that stop work, which is the distinction that matters for an extension of time. */
    public const WEATHER = ['clear', 'cloudy', 'rain', 'heavy_rain', 'wind', 'storm', 'extreme_heat'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** A day where weather or a recorded delay stopped work. */
    public function scopeDisrupted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereIn('weather', ['heavy_rain', 'storm', 'extreme_heat'])
            ->orWhere('rain_hours', '>', 0)
            ->orWhereNotNull('delays'));
    }
}
