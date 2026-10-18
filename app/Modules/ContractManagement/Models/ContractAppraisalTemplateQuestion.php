<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAppraisalTemplateQuestion extends Model
{
    use BelongsToCompany;

    protected $table = 'contract_appraisal_template_questions';

    protected $fillable = ['template_id', 'question', 'weight_percentage'];

    protected $casts = ['weight_percentage' => 'decimal:2'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractAppraisalTemplate::class, 'template_id');
    }
}
