<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\SupplierManagement\DueDiligence\Support\QuestionnaireTemplates;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One questionnaire pack sent to one supplier.
 *
 * The answers live in their own rows rather than a blob on this one, so a single "no" can be found,
 * quoted and defended years later without unpacking anything — which is the only form in which a
 * questionnaire answer is worth having.
 */
class DueDiligenceQuestionnaire extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'dd_questionnaires';

    protected $fillable = [
        'uuid', 'supplier_id', 'case_id', 'pack', 'title', 'status', 'completeness',
        'risk_score', 'flags', 'sent_by', 'sent_at', 'submitted_at',
        'reviewed_by', 'reviewed_at', 'review_note', 'due_on',
    ];

    protected $casts = [
        'flags' => 'array',
        'completeness' => 'integer',
        'risk_score' => 'decimal:2',
        'sent_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'due_on' => 'date',
    ];

    public const STATUS_SENT = 'sent';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_REVIEWED = 'reviewed';

    protected static function booted(): void
    {
        static::creating(fn (self $q) => $q->uuid ??= (string) Str::uuid());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(DueDiligenceQuestionnaireAnswer::class, 'questionnaire_id');
    }

    public function template(): ?array
    {
        return QuestionnaireTemplates::get($this->pack);
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_REVIEWED], true);
    }

    /** Answers that are themselves findings — the ones a reviewer actually needs to read. */
    public function flaggedAnswers()
    {
        return $this->answers()->where('is_flag', true)->get();
    }
}
