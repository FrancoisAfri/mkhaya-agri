<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDisciplinePack extends Model
{
    protected $fillable = [
        'discipline_case_id', 'employee_id', 'pack_type',
        'attendance_summary', 'violation_details', 'pattern_analysis',
        'comparative_data', 'ai_narrative', 'warning_letter_content',
        'recommended_sanction', 'supporting_references',
        'generated_by', 'generated_at',
    ];

    protected $casts = [
        'attendance_summary'   => 'array',
        'violation_details'    => 'array',
        'pattern_analysis'     => 'array',
        'comparative_data'     => 'array',
        'supporting_references'=> 'array',
        'generated_at'         => 'datetime',
    ];

    const PACK_TYPES = ['full' => 'Full Pack', 'warning_letter' => 'Warning Letter', 'evidence_summary' => 'Evidence Summary'];

    public function case(): BelongsTo
    {
        return $this->belongsTo(AttendanceDisciplineCase::class, 'discipline_case_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Hr\Models\HRPerson::class, 'employee_id');
    }
}
