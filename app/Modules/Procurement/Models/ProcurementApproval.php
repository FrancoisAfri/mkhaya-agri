<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProcurementApproval extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_approvals';

    protected $fillable = [
        'uuid', 'source_type', 'source_id', 'status', 'current_step', 'total_steps', 'started_by', 'completed_at',
    ];

    protected $casts = ['completed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (ProcurementApproval $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProcurementApprovalStep::class, 'procurement_approval_id')->orderBy('step');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProcurementApprovalComment::class, 'procurement_approval_id')->latest();
    }
}
