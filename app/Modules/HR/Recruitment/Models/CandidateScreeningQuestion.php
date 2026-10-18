<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A neutral, non-discriminatory interview question generated from a screening's
 * findings — designed to let the candidate provide context, not to prejudge them.
 */
class CandidateScreeningQuestion extends Model
{
    use BelongsToCompany;

    protected $table = 'candidate_screening_questions';

    protected $fillable = [
        'company_id',
        'screening_id',
        'question',
        'rationale',
        'based_on',
    ];

    public function screening(): BelongsTo
    {
        return $this->belongsTo(CandidateScreening::class, 'screening_id');
    }
}
