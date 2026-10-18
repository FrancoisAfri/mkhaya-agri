<?php

namespace App\Modules\PerformanceAppraisal\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Response;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PaKpiEvidence extends Model
{
    use StoresFileBytes;

    use LogsActivity;

    protected $table = 'pa_kpi_evidence';

    protected $fillable = [
        'employee_kpi_id', 'file_name', 'mime', 'data', 'uuid', 'type', 'size', 'description', 'uploaded_by',
    ];

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(PaEmployeeKpi::class, 'employee_kpi_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('performance_appraisal')
            ->logOnly(['employee_kpi_id', 'file_name', 'mime', 'size', 'description', 'uploaded_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => 'KPI evidence uploaded',
                'updated' => 'KPI evidence updated',
                'deleted' => 'KPI evidence deleted',
                default   => $e,
            });
    }

    public function buildDownloadResponse(): Response
    {
        return $this->fileResponse($this->file_name, $this->mime);
    }

    protected function fileBlobColumn(): ?string
    {
        return 'data';
    }

    protected function fileFolder(): string
    {
        return 'performance-appraisal/evidence';
    }
}
