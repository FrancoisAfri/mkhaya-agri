<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShortlistItem extends Model
{
    use HasFactory;

    protected $table = 'candidate_shortlist_items';

    protected $fillable = [
        'shortlist_id',
        'candidate_id',
        'application_id',
        'rank',
        'ai_recommended',
    ];

    protected $casts = [
        'ai_recommended' => 'boolean',
    ];

    // Relationships

    public function shortlist()
    {
        return $this->belongsTo(Shortlist::class);
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
