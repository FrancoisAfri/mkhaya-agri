<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use App\Modules\HR\Hr\Attendance\Support\SiteDateTime;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A time-and-attendance engine record: the organisation's, public by uuid, audited, timed in its site's zone. */
abstract class EngineModel extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getCasts()
    {
        return array_merge(['created_at' => SiteDateTime::class, 'updated_at' => SiteDateTime::class], parent::getCasts());
    }

    public function fromDateTime($value)
    {
        if ($value instanceof \DateTimeInterface && $value->getTimezone()->getName() !== config('app.timezone')) {
            $value = \Illuminate\Support\Carbon::instance($value)->setTimezone(config('app.timezone'));
        }

        return parent::fromDateTime($value);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('attendance')->logAll()->logExcept(['updated_at', 'created_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        if (isset($this->company_id)) {
            $activity->company_id = $this->company_id;
        }
    }
}
