<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateDocument extends Model
{
    use HasFactory;

    protected $table = 'candidate_documents';

    protected $fillable = [
        'candidate_id',
        'type',
        'original_name',
        'file_size',
        // NOT: path, mime_type — set by upload service after binary MIME validation.
        //      virus_scanned_at, virus_clean — set by virus scan job.
        //      verified_at — set by HR action.
    ];

    protected $hidden = [
        'path', // served via time-limited signed URLs only, never exposed directly
    ];

    protected $casts = [
        'virus_clean'     => 'boolean',
        'virus_scanned_at'=> 'datetime',
        'verified_at'     => 'datetime',
        'parsed_at'       => 'datetime',
    ];

    // Relationships

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    // Helpers

    public function isPendingScan(): bool
    {
        return $this->virus_scanned_at === null;
    }

    public function isVirusClean(): bool
    {
        return $this->virus_clean === true;
    }

    public function isParsed(): bool
    {
        return $this->parse_status === 'completed';
    }
}
