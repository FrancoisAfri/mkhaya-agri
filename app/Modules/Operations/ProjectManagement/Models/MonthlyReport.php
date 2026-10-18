<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One project's row on one month's report.
 *
 * Only what the client's document says that the project record does not: their stage vocabulary,
 * their status wording, the challenges text and the three fee figures. Name, code and manager are
 * read from the project — this row never duplicates the project.
 */
class MonthlyReport extends Model
{
    use AuditsChanges, BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_monthly_reports';

    protected $fillable = [
        'uuid', 'company_id', 'reporting_period_id', 'project_id', 'row_number',
        'project_manager_ids', 'project_manager_label', 'stage', 'progress_percentage',
        'project_status', 'project_status_notes', 'challenges',
        'estimated_professional_fee', 'professional_fee_invoiced_to_date',
        'professional_fee_outstanding', 'currency', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'row_number'                        => 'integer',
        'project_manager_ids'               => 'array',
        'progress_percentage'               => 'decimal:2',
        'estimated_professional_fee'        => 'decimal:2',
        'professional_fee_invoiced_to_date' => 'decimal:2',
        'professional_fee_outstanding'      => 'decimal:2',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function cashflows(): HasMany
    {
        return $this->hasMany(MonthlyCashflow::class, 'monthly_report_id');
    }

    /**
     * The managers as the client writes them — "Neo / Refiloe".
     *
     * A stored label wins over the ids: the document sometimes names somebody who is not a Hub
     * user, and losing that name to make the field tidy would lose information the report carries.
     */
    public function managerLabel(): string
    {
        return $this->project_manager_label ?: '';
    }
}
