<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProcurementEscalation extends Model
{
    use BelongsToCompany;

    protected $table = 'procurement_escalations';

    protected $fillable = ['uuid', 'procurement_approval_id', 'step_id', 'reason', 'escalated_to', 'status'];

    protected static function booted(): void
    {
        static::creating(fn (ProcurementEscalation $m) => $m->uuid ??= (string) Str::uuid());
    }
}
