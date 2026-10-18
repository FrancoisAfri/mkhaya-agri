<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A service type applied to a particular job card, with its own hours/cost. */
class FleetJobCardServiceTypeLine extends Model
{
    use BelongsToCompany;

    protected $table = 'fleet_job_card_service_type_lines';

    protected $fillable = ['uuid', 'company_id', 'job_card_id', 'service_type_id', 'name', 'hours', 'cost'];

    protected $casts = ['hours' => 'decimal:2', 'cost' => 'decimal:2'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function jobCard(): BelongsTo    { return $this->belongsTo(FleetJobCard::class, 'job_card_id'); }
    public function serviceType(): BelongsTo { return $this->belongsTo(FleetJobCardServiceType::class, 'service_type_id'); }
}
