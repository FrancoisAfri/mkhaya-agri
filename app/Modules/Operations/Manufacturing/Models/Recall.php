<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\DocumentManagement\Concerns\HasDocuments;
use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A product recall, opened from one lot and covering every lot that lot went into (MFG-010). */
class Recall extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasDocuments, HasPublicUuid;

    public const OPEN = 'OPEN';
    public const CLOSED = 'CLOSED';
    public const SEVERITIES = ['CLASS_I', 'CLASS_II', 'CLASS_III'];

    protected $table = 'mfg_recalls';

    protected $fillable = [
        'uuid', 'company_id', 'number', 'title', 'reason', 'severity', 'status', 'origin_lot_id',
        'opened_by', 'opened_at', 'closed_by', 'closed_at', 'closure_notes',
        'scope', 'stock_item_id', 'serial_from', 'serial_to', 'item_status_before',
    ];

    protected $casts = ['opened_at' => 'datetime', 'closed_at' => 'datetime'];

    public function lots(): HasMany { return $this->hasMany(RecallLot::class, 'recall_id')->orderBy('depth')->orderBy('id'); }

    public function actions(): HasMany { return $this->hasMany(RecallAction::class, 'recall_id')->orderByDesc('acted_at')->orderByDesc('id'); }

    public function originLot(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finance\Accounting\Models\FinStockLot::class, 'origin_lot_id')->withoutGlobalScopes();
    }

    public function isOpen(): bool { return $this->status === self::OPEN; }
}
