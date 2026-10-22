<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One truck load of grain: from a field (or out of a silo) to the farm's own silo or a buyer, with
 * its two weighings, quality, grade, deductions and settled mass. Masses are kilograms.
 */
class GrainTicket extends FarmOwnedModel
{
    public const STATUSES = ['dispatched', 'weighing', 'weighed', 'complete', 'void'];
    public const OPEN = ['dispatched', 'weighing', 'weighed'];
    public const MOVEMENTS = ['field_to_store', 'field_to_buyer', 'store_to_buyer'];
    public const TO_BUYER = ['field_to_buyer', 'store_to_buyer'];

    protected $table = 'agri_grain_tickets';

    protected $casts = [
        'dispatched_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'first_weighed_at' => \App\Modules\Agri\Casts\FarmDateTime::class,
        'second_weighed_at' => \App\Modules\Agri\Casts\FarmDateTime::class, 'completed_at' => \App\Modules\Agri\Casts\FarmDateTime::class,
        'gross_kg' => 'float', 'tare_kg' => 'float', 'net_kg' => 'float', 'moisture_pct' => 'float', 'foreign_matter_pct' => 'float',
        'moisture_deduction_kg' => 'float', 'handling_deduction_kg' => 'float', 'screenings_deduction_kg' => 'float', 'settled_kg' => 'float',
        'unit_value' => 'float', 'quality' => 'array', 'deduction_basis' => 'array', 'stock_out' => 'array', 'flags' => 'array',
    ];

    public function cropPlan(): BelongsTo
    {
        return $this->belongsTo(CropPlan::class);
    }

    public function weighings(): HasMany
    {
        return $this->hasMany(GrainWeighing::class, 'ticket_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(GrainTicketPhoto::class, 'ticket_id');
    }

    public function toBuyer(): bool
    {
        return in_array($this->movement, self::TO_BUYER, true);
    }

    /** The day the load counts on: when it was weighed out, else when it left the field. */
    public function loadDate(): ?string
    {
        return ($this->second_weighed_at ?? $this->first_weighed_at ?? $this->dispatched_at ?? $this->created_at)?->toDateString();
    }
}
