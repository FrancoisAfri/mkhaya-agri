<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A containment, corrective or preventive action on a non-conformance, with an owner and a deadline. */
class NcrAction extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany, HasPublicUuid;

    public const TYPES = ['CONTAINMENT', 'CORRECTIVE', 'PREVENTIVE'];

    protected $table = 'mfg_ncr_actions';

    protected $fillable = [
        'uuid', 'company_id', 'ncr_id', 'type', 'description', 'owner_id', 'due_date', 'status',
        'completed_by', 'completed_at', 'completion_notes',
    ];

    protected $casts = ['due_date' => 'date', 'completed_at' => 'datetime'];

    public function ncr(): BelongsTo { return $this->belongsTo(Nonconformance::class, 'ncr_id'); }

    public function owner(): BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'owner_id'); }
}
