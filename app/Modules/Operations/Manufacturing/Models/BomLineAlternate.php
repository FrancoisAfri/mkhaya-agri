<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** An approved stand-in for one BOM line, rated and ranked. */
class BomLineAlternate extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_bom_line_alternates';

    protected $fillable = [
        'uuid', 'company_id', 'bom_line_id', 'stock_item_id', 'ratio', 'priority',
        'requires_approval', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'ratio' => 'decimal:6',
        'priority' => 'integer',
        'requires_approval' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function bomLine(): BelongsTo
    {
        return $this->belongsTo(BomLine::class, 'bom_line_id');
    }

    public function isUsable(): bool
    {
        return ! $this->requires_approval || $this->approved_at !== null;
    }
}
