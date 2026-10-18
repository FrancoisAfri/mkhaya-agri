<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class FinancialYear extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    /**
     * Budget data is never global. A row with no company is invisible under row level security,
     * which on a budget means an allocation nobody can spend and nobody can find.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'financial_years';

    protected $fillable = ['uuid', 'name', 'start_date', 'end_date', 'status', 'is_current', 'variance_threshold_pct'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'is_current' => 'boolean', 'variance_threshold_pct' => 'float'];

    /** A period variance at or above this share of plan must be explained. */
    public const DEFAULT_VARIANCE_THRESHOLD_PCT = 10.0;

    protected static function booted(): void
    {
        static::creating(fn (FinancialYear $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('Budget Management')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function periods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class, 'financial_year_id');
    }
}
