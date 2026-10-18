<?php

namespace App\Modules\Budget\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class BudgetCategory extends Model
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

    protected $table = 'budget_categories';

    protected $fillable = ['uuid', 'name', 'type', 'parent_id', 'status'];

    protected $casts = ['status' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (BudgetCategory $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('Budget Management')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
