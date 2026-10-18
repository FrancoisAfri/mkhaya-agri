<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WorkflowCalendar extends Model
{
    use BelongsToCompany;

    protected $table = 'workflow_calendars';

    protected $guarded = ['id'];

    protected $casts = ['work_days' => 'array', 'extra_holidays' => 'array', 'use_public_holidays' => 'boolean', 'is_default' => 'boolean'];

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
