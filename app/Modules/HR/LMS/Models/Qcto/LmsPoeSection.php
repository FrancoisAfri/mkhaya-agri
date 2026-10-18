<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class LmsPoeSection extends Model
{
    use LogsActivity, KeepsDatabaseDefaults;
    protected $table = 'lms_poe_sections';

    protected $fillable = [
        'poe_portfolio_id', 'unit_standard_id', 'unit_standard_outcome_id',
        'title', 'description', 'outcome_number', 'status', 'sort_order',
        'assessor_notes', 'assessment_decision', 'learner_declaration',
    ];

    protected $casts = ['learner_declaration' => 'boolean'];

    public function portfolio()
    {
        return $this->belongsTo(LmsPoePortfolio::class, 'poe_portfolio_id');
    }

    public function unitStandard()
    {
        return $this->belongsTo(LmsUnitStandard::class, 'unit_standard_id');
    }

    public function outcome()
    {
        return $this->belongsTo(LmsUnitStandardOutcome::class, 'unit_standard_outcome_id');
    }

    public function evidence()
    {
        return $this->hasMany(LmsPoeEvidence::class, 'poe_section_id');
    }

    public function revisions()
    {
        return $this->hasMany(LmsPoeSectionRevision::class, 'poe_section_id')->orderByDesc('revision_number');
    }

    public function logRevision(string $previousStatus, string $newStatus, ?string $notes = null): void
    {
        $next = ($this->revisions()->max('revision_number') ?? 0) + 1;
        $this->revisions()->create([
            'revision_number' => $next,
            'previous_status' => $previousStatus,
            'new_status'      => $newStatus,
            'notes'           => $notes,
            'revised_by'      => auth()->id(),
            'revised_at'      => now(),
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LMS PoE Section')
            ->logOnly(['title', 'status', 'assessment_decision', 'assessor_notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => ucfirst($e) . " PoE section \"{$this->title}\".");
    }
}
