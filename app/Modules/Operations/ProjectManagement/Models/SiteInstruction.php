<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An instruction given on site (§23).
 *
 * The single most common source of a variation claim. `claimed_as_variation` records the
 * contractor's view *at the time it was issued*, not months later when the account is argued —
 * deciding it retrospectively is how the claim is lost.
 */
class SiteInstruction extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_site_instructions';

    public const STATUSES = ['open', 'actioned', 'closed', 'disputed'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'number', 'issued_on', 'issued_by', 'title',
        'instruction', 'claimed_as_variation', 'change_request_id', 'status', 'response',
        'acknowledged_at', 'created_by',
    ];

    protected $casts = [
        'issued_on'            => 'date',
        'claimed_as_variation' => 'boolean',
        'acknowledged_at'      => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }

    /** Claimed as a variation and not yet turned into one — the gap where money goes missing. */
    public function isUnconvertedVariation(): bool
    {
        return $this->claimed_as_variation && $this->change_request_id === null;
    }
}
