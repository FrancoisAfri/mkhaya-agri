<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;

class CandidateMessage extends Model
{
    protected $table = 'recruitment_candidate_messages';

    protected $fillable = [
        'application_id',
        'direction',
        'channel',
        'subject',
        'body',
        'sent_by',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
