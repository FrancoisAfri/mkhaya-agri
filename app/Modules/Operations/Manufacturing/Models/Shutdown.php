<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A window in which nothing runs. A NULL production_line_id means the whole factory stops -- not
 * that no line is affected.
 */
class Shutdown extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity;

    /**
     * Confined to the factories the signed-in person is assigned to (MFG-001.09). Company
     * isolation underneath is untouched; this only narrows it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('factory_id'));
    }

    protected $table = 'mfg_shutdowns';

    protected $fillable = [
        'uuid', 'company_id', 'factory_id', 'production_line_id', 'reason', 'kind',
        'starts_at', 'ends_at', 'created_by',
    ];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public const KINDS = ['PLANNED', 'BREAKDOWN', 'STATUTORY'];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    public function productionLine(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'production_line_id');
    }

    /*
     * Stored as an instant, in UTC.
     *
     * Eloquent writes a Carbon as a bare 'Y-m-d H:i:s' and drops its timezone, so a shutdown at
     * 04:00 Johannesburg time was stored as 04:00 UTC -- two hours late. The calendar then cut the
     * wrong two hours out of the wrong shift, and a test of a same-day shutdown passed only because
     * the shifted window still happened to fall inside the shift. Converting here keeps every
     * caller honest: an instant goes in as the instant it is.
     */
    public function setStartsAtAttribute($value): void
    {
        $this->attributes['starts_at'] = self::toUtc($value);
    }

    public function setEndsAtAttribute($value): void
    {
        $this->attributes['ends_at'] = self::toUtc($value);
    }

    private static function toUtc($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $instant = $value instanceof \DateTimeInterface
            ? \Illuminate\Support\Carbon::instance($value)
            : \Illuminate\Support\Carbon::parse($value);

        return $instant->utc()->format('Y-m-d H:i:sP');
    }

    /** Does this shutdown stop the given line? A factory-wide one stops every line. */
    public function affectsLine(?int $lineId): bool
    {
        return $this->production_line_id === null || $this->production_line_id === $lineId;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['reason', 'kind', 'starts_at', 'ends_at'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
