<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinarySuspension extends Model
{
    use StoresFileBytes;

    protected $table = 'disciplinary_suspensions';

    protected $fillable = [
        'employee_id', 'case_id', 'suspension_type', 'start_date',
        'end_date', 'expected_return_date', 'actual_return_date',
        'reason', 'issued_by', 'status', 'suspension_days',
        'notes', 'file_uuid', 'original_name', 'mime', 'file_data',
        'created_by',
    ];

    protected $casts = [
        'start_date'           => 'date',
        'end_date'             => 'date',
        'expected_return_date' => 'date',
        'actual_return_date'   => 'date',
        'suspension_days'      => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'disciplinary/suspensions';
    }
}
