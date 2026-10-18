<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** The record of one approval step against one job card. */
class FleetJobCardApproval extends Model
{
    use BelongsToCompany;

    protected $table = 'fleet_job_card_approvals';

    protected $fillable = [
        'uuid', 'company_id', 'job_card_id', 'step_id', 'step_name', 'sequence',
        'approver_user_id', 'status', 'comment', 'actioned_at',
    ];

    protected $casts = ['actioned_at' => 'datetime', 'sequence' => 'integer'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function jobCard(): BelongsTo { return $this->belongsTo(FleetJobCard::class, 'job_card_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approver_user_id'); }

    public function scopePending($q) { return $q->where('status', 'pending'); }
}
