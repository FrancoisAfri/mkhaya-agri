<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAppraisalResponse extends Model
{
    use BelongsToCompany;

    protected $table = 'contract_appraisal_responses';

    protected $fillable = ['appraisal_id', 'question_id', 'response', 'score', 'comment'];

    protected $casts = ['score' => 'decimal:2'];

    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(ContractAppraisal::class, 'appraisal_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ContractAppraisalTemplateQuestion::class, 'question_id');
    }
}
