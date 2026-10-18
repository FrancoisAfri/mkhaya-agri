<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something done under a recall: stock held or released, product retrieved or disposed, people told. */
class RecallAction extends Model
{
    use BelongsToCompany, HasPublicUuid;

    public const KINDS = ['HOLD', 'RELEASE', 'RETRIEVED', 'DISPOSED', 'CUSTOMER_NOTIFIED', 'REGULATOR_NOTIFIED', 'NOTE'];

    protected $table = 'mfg_recall_actions';

    protected $fillable = ['uuid', 'company_id', 'recall_id', 'kind', 'lot_id', 'qty', 'reference', 'notes', 'acted_by', 'acted_at'];

    protected $casts = ['qty' => 'decimal:4', 'acted_at' => 'datetime'];

    public function recall(): BelongsTo { return $this->belongsTo(Recall::class, 'recall_id'); }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockLot::class, 'lot_id')->withoutGlobalScopes();
    }

    public function actor(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'acted_by'); }
}
