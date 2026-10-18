<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\SupplierManagement\DueDiligence\Support\CaseState;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One move of a case, with who made it and what they held at the time.
 *
 * The role is captured rather than looked up later, because a person's role changes and what they
 * held when they approved a supplier does not. An audit that resolves roles at reading time reports
 * that a case was approved by whoever the approver has since become.
 */
class DueDiligenceCaseTransition extends Model
{
    use BelongsToCompany;

    protected $table = 'dd_case_transitions';

    protected $fillable = [
        'uuid', 'case_id', 'from_state', 'to_state', 'action', 'actor_id', 'actor_label',
        'actor_slot', 'actor_roles', 'reason', 'context', 'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transition) {
            $transition->uuid ??= (string) Str::uuid();
            $transition->occurred_at ??= Carbon::now();
        });
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(DueDiligenceCase::class, 'case_id');
    }

    /** One line an auditor can read: who did what, in what capacity, and why. */
    public function summary(): string
    {
        $who = $this->actor_label ?: 'The system';
        $slot = $this->actor_slot ? ' ('.\App\Modules\SupplierManagement\DueDiligence\Support\RoleSlot::label($this->actor_slot).')' : '';
        $move = CaseState::transitions()[$this->action]['label'] ?? $this->action;

        return "{$who}{$slot}: {$move} → ".CaseState::label($this->to_state)
            .($this->reason ? ' — '.$this->reason : '');
    }
}
