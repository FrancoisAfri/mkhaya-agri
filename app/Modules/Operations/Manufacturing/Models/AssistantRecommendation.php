<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A recommendation the assistant made, the measure it should move, and how that measure later turned out. */
class AssistantRecommendation extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_assistant_recommendations';

    protected $fillable = ['uuid', 'company_id', 'query_id', 'topic', 'text', 'subject_type', 'subject_id', 'subject_label', 'url', 'metric', 'baseline_value', 'baseline_from', 'baseline_to', 'status', 'measured_value', 'measured_at', 'outcome'];

    public function askedIn(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(AssistantQuery::class, 'query_id'); }

    public function actions(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(AssistantAction::class, 'recommendation_id'); }

    protected $casts = ['baseline_value' => 'float', 'baseline_from' => 'date', 'baseline_to' => 'date', 'measured_value' => 'float', 'measured_at' => 'datetime'];
}
