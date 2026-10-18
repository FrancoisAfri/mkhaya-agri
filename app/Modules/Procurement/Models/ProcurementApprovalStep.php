<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProcurementApprovalStep extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_approval_steps';

    protected $fillable = [
        'uuid', 'procurement_approval_id', 'step', 'name', 'role', 'approver_id', 'status', 'comment', 'acted_at',
    ];

    protected $casts = ['acted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (ProcurementApprovalStep $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ProcurementApproval::class, 'procurement_approval_id');
    }
}
