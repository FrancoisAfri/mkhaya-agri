<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;

class CandidateReference extends Model
{
    protected $table = 'recruitment_candidate_references';

    protected $fillable = [
        'application_id',
        'referee_name',
        'referee_email',
        'referee_phone',
        'referee_company',
        'referee_title',
        'relationship',
        'status',
        'response_notes',
        'contacted_at',
        'received_at',
        'added_by',
    ];

    protected $casts = [
        'contacted_at' => 'datetime',
        'received_at'  => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
