<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody's decision, kept.
 *
 * No soft delete and nothing that updates: this is the record of who approved what, and the value
 * of it is entirely that it cannot be quietly edited afterwards. `actor_name` is stamped at the
 * time so the trail still reads after the account is deactivated.
 */
class ApprovalAction extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_approval_actions';

    protected $fillable = [
        'uuid', 'company_id', 'request_id', 'step_id', 'position',
        'user_id', 'actor_name', 'decision', 'comment', 'acted_at',
    ];

    protected $casts = ['position' => 'integer', 'acted_at' => 'datetime'];

    public const DECISIONS = ['approved', 'rejected', 'delegated', 'cancelled'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }
}
