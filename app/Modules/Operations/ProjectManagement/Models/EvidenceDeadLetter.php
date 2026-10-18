<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An evidence submission that could not be accepted (PM-1003).
 *
 * Not an error log: it is the record itself, held until someone fixes and replays it. A row here
 * means a person captured something the system refused — the work happened either way.
 */
class EvidenceDeadLetter extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_evidence_dead_letters';

    protected $fillable = [
        'uuid', 'company_id', 'source_module', 'source_ref', 'event_type', 'project_id',
        'batch_id', 'payload', 'errors', 'submitted_by', 'replayed_at', 'replayed_as',
    ];

    protected $casts = [
        'payload'     => 'array',
        'errors'      => 'array',
        'replayed_at' => 'datetime',
    ];

    /** Still waiting to be corrected — what the health view counts. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('replayed_at');
    }
}
