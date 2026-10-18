<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Append-only (a database trigger refuses UPDATE and DELETE). Written only through TaxAudit. */
class TaxAuditEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'tax_audit_events';

    protected $guarded = ['id'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'context' => 'array', 'created_at' => 'datetime'];
}
