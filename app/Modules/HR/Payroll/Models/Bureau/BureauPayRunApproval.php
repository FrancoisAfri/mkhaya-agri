<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One decision on one step of one run.
 *
 * Rejections are kept alongside approvals. "It came back once before it was signed" is part of the
 * history of a payroll, and a table that only records the yeses cannot tell you that.
 */
class BureauPayRunApproval extends Model
{
    use BelongsToCompany;

    protected $table = 'bureau_pay_run_approvals';

    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected $casts = [
        'sequence'   => 'integer',
        'round'      => 'integer',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BureauPayRun::class, 'bureau_pay_run_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
