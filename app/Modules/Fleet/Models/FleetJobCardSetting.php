<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Per-tenant job card configuration: numbering, approval and notification behaviour. */
class FleetJobCardSetting extends Model
{
    use BelongsToCompany;

    protected $table = 'fleet_job_card_settings';

    protected $fillable = [
        'uuid', 'company_id', 'card_number_prefix', 'next_card_number', 'require_approval',
        'require_mechanic_feedback', 'allow_parts_without_stock', 'notify_on_assignment',
        'notify_on_completion', 'default_labour_rate',
    ];

    protected $casts = [
        'require_approval'          => 'boolean',
        'require_mechanic_feedback' => 'boolean',
        'allow_parts_without_stock' => 'boolean',
        'notify_on_assignment'      => 'boolean',
        'notify_on_completion'      => 'boolean',
        'next_card_number'          => 'integer',
        'default_labour_rate'       => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    /** The settings row for the active tenant, created with defaults on first use. */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], []);
    }
}
