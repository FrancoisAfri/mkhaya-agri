<?php

namespace App\Modules\HR\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Evidence that a named person accepted a named version of a policy at a named moment.
 *
 * The version matters more than the policy. "They accepted the code of conduct" is worth little if
 * the code has been revised twice since — what a disciplinary process needs is which text they saw.
 */
class OnboardingPolicyAcknowledgement extends Model
{
    protected $fillable = [
        'uuid', 'onboarding_task_id', 'hr_person_id', 'policy_version_id',
        'acknowledged_by', 'acknowledged_at', 'signature', 'ip_address', 'company_id',
    ];

    protected $casts = ['acknowledged_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $ack) => $ack->uuid ??= (string) Str::uuid());
    }
}
