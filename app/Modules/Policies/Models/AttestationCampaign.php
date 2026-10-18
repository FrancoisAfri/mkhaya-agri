<?php

namespace App\Modules\Policies\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttestationCampaign extends Model
{
    protected $table = 'attestation_campaigns';

    protected $fillable = [
        'name',
        'policy_id',
        'target_roles',
        'target_departments',
        'deadline',
        'status',
        'created_by',
    ];

    protected $casts = [
        'target_roles'       => 'array',
        'target_departments' => 'array',
        'deadline'           => 'date',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(PolicyAttestation::class, 'campaign_id');
    }
}
