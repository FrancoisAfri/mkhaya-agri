<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryAccused extends Model
{
    protected $table = 'disciplinary_accused';

    protected $fillable = [
        'incident_id', 'employee_id', 'name', 'job_title', 'department_id', 'description',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryIncident::class, 'incident_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function displayName(): string
    {
        if ($this->employee_id && $this->employee) {
            $p = $this->employee->person;
            return $p ? trim("{$p->first_name} {$p->surname}") : ($this->employee->email ?? 'Unknown');
        }
        return $this->name ?? 'Unknown';
    }
}
