<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A draft the assistant prepared: a task, an investigation request, or an action that waits for approval. */
class AssistantAction extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_assistant_actions';

    protected $fillable = ['uuid', 'company_id', 'recommendation_id', 'kind', 'status', 'summary', 'payload', 'requires_permission', 'owner_id', 'due_date', 'created_by', 'decided_by', 'decided_at', 'decision_notes', 'result_type', 'result_id', 'result_reference'];

    protected $casts = ['payload' => 'array', 'due_date' => 'date', 'decided_at' => 'datetime'];

    public const KINDS = ['TASK', 'INVESTIGATION', 'PURCHASE_REQUEST', 'PRIORITISE_ORDER'];

    /** Kinds that change purchasing or production, and so wait for somebody else's approval. */
    public const NEEDS_APPROVAL = ['PURCHASE_REQUEST', 'PRIORITISE_ORDER'];

    public function recommendation(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(AssistantRecommendation::class, 'recommendation_id'); }

    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'owner_id'); }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(\App\Core\User\Models\User::class, 'created_by'); }
}
