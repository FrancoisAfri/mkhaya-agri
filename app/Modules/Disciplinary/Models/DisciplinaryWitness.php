<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryWitness extends Model
{
    protected $table = 'disciplinary_witnesses';

    protected $fillable = [
        'incident_id', 'employee_id', 'name', 'email', 'phone',
        'is_anonymous', 'statement', 'statement_date',
    ];

    protected $casts = [
        'is_anonymous'   => 'boolean',
        'statement_date' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryIncident::class, 'incident_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
