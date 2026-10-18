<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DisciplinaryWarning extends Model
{
    use StoresFileBytes;

    use SoftDeletes;
    protected $table = 'disciplinary_warnings';

    protected $fillable = [
        'employee_id', 'case_id', 'hearing_id', 'warning_type',
        'issued_by', 'issued_date', 'expiry_date', 'description',
        'incident_reference', 'is_expired', 'employee_acknowledged',
        'acknowledged_at', 'file_uuid', 'original_name', 'mime',
        'file_data', 'created_by',
    ];

    protected $casts = [
        'issued_date'           => 'date',
        'expiry_date'           => 'date',
        'acknowledged_at'       => 'datetime',
        'is_expired'            => 'boolean',
        'employee_acknowledged' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryHearing::class, 'hearing_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expiryRecord(): HasOne
    {
        return $this->hasOne(DisciplinaryWarningExpiry::class, 'warning_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_expired', false);
    }

    public function scopeExpired($query)
    {
        return $query->where('is_expired', true);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'disciplinary/warnings';
    }
}
