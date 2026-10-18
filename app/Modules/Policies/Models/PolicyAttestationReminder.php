<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAttestationReminder extends Model
{
    protected $table = 'policy_attestation_reminders';

    public $timestamps = false;

    protected $fillable = [
        'attestation_id',
        'type',
        'sent_at',
        'escalated_to',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function attestation(): BelongsTo
    {
        return $this->belongsTo(PolicyAttestation::class, 'attestation_id');
    }

    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to');
    }
}
