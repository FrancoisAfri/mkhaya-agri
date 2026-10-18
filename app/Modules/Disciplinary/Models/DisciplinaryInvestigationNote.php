<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryInvestigationNote extends Model
{
    protected $table = 'disciplinary_investigation_notes';

    protected $fillable = [
        'investigation_id', 'author_id', 'note_type', 'title', 'content', 'is_confidential',
    ];

    protected $casts = [
        'is_confidential' => 'boolean',
    ];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryInvestigation::class, 'investigation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function noteTypeIcon(): string
    {
        return match ($this->note_type) {
            'witness_interview' => 'mdi-account-voice',
            'evidence_review'   => 'mdi-magnify',
            'finding'           => 'mdi-lightbulb-outline',
            'recommendation'    => 'mdi-thumb-up-outline',
            default             => 'mdi-note-text-outline',
        };
    }
}
