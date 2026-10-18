<?php

namespace App\Modules\Finance\Sars\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a SARS return's life: created, reviewed, filed, accepted, refused.
 *
 * Append-only by intention — there is no update or delete path anywhere in the module. The value
 * of a statutory trail is that it cannot be tidied up afterwards.
 */
class SarsSubmissionEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'sars_submission_events';

    protected $fillable = [
        'submission_id', 'company_id', 'from_status', 'to_status',
        'event', 'note', 'actor_id', 'actor_name', 'ip',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(SarsSubmission::class, 'submission_id');
    }

    /** A line for the timeline on the submission screen. */
    public function describe(): string
    {
        return match ($this->event) {
            'generated' => 'Generated from payroll',
            default => $this->from_status
                ? "Moved from {$this->from_status} to {$this->to_status}"
                : "Created as {$this->to_status}",
        };
    }
}
