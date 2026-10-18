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
 * A working pattern at a factory (MFG-001.04).
 *
 * `crosses_midnight` is stored rather than derived: a night shift ends before it starts by the
 * clock, and 22:00-06:00 is indistinguishable from 06:00-22:00 to a comparison of the two times
 * when the shift runs exactly twelve hours.
 */
class Shift extends Model
{
    use BelongsToCompany, HasPublicUuid, LogsActivity, SoftDeletes;

    /**
     * Confined to the factories the signed-in person is assigned to (MFG-001.09). Company
     * isolation underneath is untouched; this only narrows it.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('factory_access', new \App\Modules\Operations\Manufacturing\Scopes\FactoryAccessScope('factory_id'));
    }

    protected $table = 'mfg_shifts';

    protected $fillable = [
        'uuid', 'company_id', 'factory_id', 'code', 'name', 'starts_at', 'ends_at',
        'crosses_midnight', 'break_minutes', 'days_of_week', 'overtime_after_minutes',
        'overtime_multiplier', 'observes_public_holidays', 'is_active',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'crosses_midnight' => 'boolean',
        'observes_public_holidays' => 'boolean',
        'is_active' => 'boolean',
        'break_minutes' => 'integer',
        'overtime_multiplier' => 'decimal:3',
    ];

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    /** Paid minutes on the floor, breaks removed. */
    public function workingMinutes(): int
    {
        $start = \Illuminate\Support\Carbon::createFromFormat('H:i:s', $this->rawTime('starts_at'));
        $end = \Illuminate\Support\Carbon::createFromFormat('H:i:s', $this->rawTime('ends_at'));

        if ($this->crosses_midnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return max(0, $start->diffInMinutes($end) - (int) $this->break_minutes);
    }

    private function rawTime(string $attribute): string
    {
        $value = $this->getAttributes()[$attribute] ?? '00:00:00';

        return strlen((string) $value) === 5 ? $value.':00' : (string) $value;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'starts_at', 'ends_at', 'break_minutes', 'days_of_week',
                'overtime_multiplier', 'is_active'])
            ->logOnlyDirty()->useLogName('manufacturing');
    }
}
