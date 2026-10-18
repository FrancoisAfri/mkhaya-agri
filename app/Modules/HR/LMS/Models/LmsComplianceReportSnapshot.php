<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsComplianceReportSnapshot extends Model
{
    protected $table = 'lms_compliance_reports';

    public const TYPES = ['overall', 'position', 'department', 'individual'];

    protected $fillable = [
        'report_type', 'scope_id', 'scope_name',
        'period_start', 'period_end',
        'total_employees', 'total_assignments',
        'completed_count', 'overdue_count', 'pending_count',
        'compliance_rate', 'report_data',
        'generated_at', 'generated_by',
    ];

    protected $casts = [
        'report_data'   => 'array',
        'period_start'  => 'date',
        'period_end'    => 'date',
        'generated_at'  => 'datetime',
        'compliance_rate'     => 'float',
        'total_employees'     => 'integer',
        'total_assignments'   => 'integer',
        'completed_count'     => 'integer',
        'overdue_count'       => 'integer',
        'pending_count'       => 'integer',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
