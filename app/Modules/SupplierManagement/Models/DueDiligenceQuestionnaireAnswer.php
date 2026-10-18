<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer.
 *
 * `is_flag` is set from the template when the answer is recorded, not judged when it is read — two
 * reviewers looking at the same "no" have to reach the same number, or the score is an opinion
 * wearing a decimal point.
 */
class DueDiligenceQuestionnaireAnswer extends Model
{
    use BelongsToCompany;

    protected $table = 'dd_questionnaire_answers';

    protected $fillable = [
        'questionnaire_id', 'code', 'question', 'type', 'answer', 'note',
        'document_id', 'is_flag', 'flag_severity',
    ];

    protected $casts = ['is_flag' => 'boolean'];

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(DueDiligenceQuestionnaire::class, 'questionnaire_id');
    }

    public function isAnswered(): bool
    {
        return $this->answer !== null && trim((string) $this->answer) !== '';
    }
}
