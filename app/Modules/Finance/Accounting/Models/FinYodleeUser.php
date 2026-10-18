<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One company's identity inside the shared Yodlee subscription.
 *
 * Yodlee scopes accounts and transactions to a loginName, not to the subscription. With Mkhaya
 * holding one subscription for every customer, this row is the only thing keeping one tenant's
 * linked bank accounts out of another's — so the login name is unique platform-wide at the
 * database, not by naming convention.
 */
class FinYodleeUser extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_yodlee_users';

    protected $fillable = ['uuid', 'company_id', 'login_name', 'registered_at', 'last_error'];

    protected $casts = ['registered_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $u) {
            $u->uuid ??= (string) Str::uuid();
            // Random rather than derived from the company id: a predictable login name is a name
            // somebody can guess, and Yodlee treats it as an identifier we control.
            $u->login_name ??= 'mk_' . Str::lower(Str::random(24));
        });
    }

    /**
     * This company's Yodlee identity, created on first use.
     *
     * Registration with Yodlee happens separately — this row exists as soon as the tenant needs a
     * name, and `registered_at` records when Yodlee actually accepted it.
     */
    public static function forCurrentCompany(?int $companyId): self
    {
        return static::query()->firstOrCreate(['company_id' => $companyId]);
    }

    public function markRegistered(): void
    {
        $this->update(['registered_at' => now(), 'last_error' => null]);
    }
}
