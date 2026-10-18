<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryHearingAttendee extends Model
{
    protected $table = 'disciplinary_hearing_attendees';

    protected $fillable = [
        'hearing_id', 'user_id', 'role',
        'external_name', 'external_org',
        'attended', 'apology_received', 'notes',
    ];

    protected $casts = [
        'attended'         => 'boolean',
        'apology_received' => 'boolean',
    ];

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryHearing::class, 'hearing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function displayName(): string
    {
        if ($this->external_name) return $this->external_name;
        $p = $this->user?->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($this->user?->email ?? '—');
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'employee'      => 'Accused Employee',
            'employee_rep'  => 'Employee Representative',
            'chairperson'   => 'Chairperson',
            'initiator'     => 'Initiator',
            'hr_rep'        => 'HR Representative',
            'witness'       => 'Witness',
            'observer'      => 'Observer',
            default         => ucfirst($role),
        };
    }
}
