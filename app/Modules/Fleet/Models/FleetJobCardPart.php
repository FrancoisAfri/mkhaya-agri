<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** A part consumed on a job card. Name/number are snapshotted so catalogue edits don't rewrite history. */
class FleetJobCardPart extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $table = 'fleet_job_card_parts';

    protected $fillable = [
        'uuid', 'company_id', 'job_card_id', 'catalog_part_id', 'category_id',
        'name', 'part_number', 'quantity', 'unit_price', 'line_total', 'created_by',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
        static::saving(function ($m) {
            $m->line_total = (float) $m->quantity * (float) $m->unit_price;
        });
    }

    public function jobCard(): BelongsTo    { return $this->belongsTo(FleetJobCard::class, 'job_card_id'); }
    public function catalogPart(): BelongsTo { return $this->belongsTo(FleetJobCardCatalogPart::class, 'catalog_part_id'); }
}
