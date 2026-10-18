<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the due-diligence evidence chain.
 *
 * Read-only by construction: the table has a BEFORE UPDATE OR DELETE trigger, so an attempt to
 * change history through Eloquent raises a database error rather than succeeding quietly. The model
 * therefore offers no update path at all — writes go through the EvidenceLedger service, which is
 * the only thing that knows how to chain a hash.
 */
class DueDiligenceEvidence extends Model
{
    use BelongsToCompany;

    protected $table = 'dd_evidence_entries';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'claimed' => 'array',
        'observed' => 'array',
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'sequence' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'actor_id');
    }
}
