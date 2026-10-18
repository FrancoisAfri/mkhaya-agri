<?php

namespace App\Modules\HR\LMS\Models\Qcto;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;

class LmsPoeSectionRevision extends Model
{
    protected $table = 'lms_poe_section_revisions';

    protected $fillable = [
        'poe_section_id', 'revision_number', 'previous_status',
        'new_status', 'notes', 'revised_by', 'revised_at',
    ];

    protected $casts = ['revised_at' => 'datetime'];

    public function section()
    {
        return $this->belongsTo(LmsPoeSection::class, 'poe_section_id');
    }

    public function revisedBy()
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
