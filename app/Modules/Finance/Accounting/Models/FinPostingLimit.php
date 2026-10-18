<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's manual-journal posting authority: whether they may post at all and,
 * if so, the maximum journal amount — optionally scoped to a company.
 */
class FinPostingLimit extends Model
{
    use BelongsToEntity;

    protected $table = 'fin_posting_limits';

    protected $fillable = [
        'entity_id', 'user_id', 'company_id', 'max_amount', 'can_post',
    ];

    protected $casts = [
        'max_amount' => 'decimal:2',
        'can_post'   => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The limit that applies to a user for a company: company-specific wins over global. */
    public static function forUser(int $userId, ?int $companyId): ?self
    {
        return static::where('user_id', $userId)->where('company_id', $companyId)->first()
            ?? static::where('user_id', $userId)->whereNull('company_id')->first();
    }
}
