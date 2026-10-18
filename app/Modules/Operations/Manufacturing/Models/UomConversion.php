<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A conversion the dimension cannot derive: a pack of twelve, a pallet of forty bags, a drum that
 * holds 210 litres.
 *
 * These belong to a particular item, which is why stock_item_id is required. A conversion with no
 * item would be a second, competing answer to a question `factor_to_base` already answers for
 * real units -- and the first disagreement between the two would be silent.
 */
class UomConversion extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_uom_conversions';

    protected $fillable = [
        'company_id', 'stock_item_id', 'from_uom_id', 'to_uom_id', 'factor',
    ];

    protected $casts = ['factor' => 'decimal:9'];

    public function fromUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'from_uom_id');
    }

    public function toUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'to_uom_id');
    }
}
