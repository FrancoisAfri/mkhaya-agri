<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A question put to the manufacturing assistant, and what it answered from (MFG-019). */
class AssistantQuery extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_assistant_queries';

    protected $fillable = ['uuid', 'company_id', 'user_id', 'question', 'topic', 'period_from', 'period_to', 'refused', 'answer', 'narration', 'narration_source'];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date', 'refused' => 'boolean', 'answer' => 'array'];
}
