<?php

namespace App\Modules\HR\Payroll\Models\Bureau;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A client company (employer) managed by a payroll-bureau agent. Each company is
 * fully isolated: its employees, pay config and pay runs belong only to it, and
 * only the owning agent (or invited team members) can see it.
 */
class BureauCompany extends Model
{
    protected $table = 'bureau_companies';

    public const FREQUENCIES = ['monthly', 'fortnightly', 'weekly'];

    /** Supported payroll countries → currency. */
    public const COUNTRIES = ['ZA' => 'ZAR', 'NA' => 'NAD'];

    protected $fillable = [
        'uuid', 'agent_id', 'name', 'trading_name', 'registration_number',
        'paye_reference', 'uif_reference', 'sdl_reference',
        'contact_name', 'contact_email', 'contact_phone', 'address',
        'pay_frequency', 'tax_year', 'is_active', 'created_by',
        'bank', 'bank_account_name', 'bank_account_number', 'bank_branch_code', 'bank_user_code', 'auto_email_payslips', 'country', 'currency',
    ];

    protected $casts = ['is_active' => 'boolean', 'tax_year' => 'integer', 'auto_email_payslips' => 'boolean'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(fn (BureauCompany $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'bureau_company_user')->withPivot('role')->withTimestamps();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(BureauEmployee::class, 'bureau_company_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(BureauComponent::class, 'bureau_company_id');
    }

    public function payRuns(): HasMany
    {
        return $this->hasMany(BureauPayRun::class, 'bureau_company_id');
    }

    /** Alias of payRuns() so nested route-model binding (/companies/{c}/runs/{run}) resolves. */
    public function runs(): HasMany
    {
        return $this->payRuns();
    }

    /** May this user access the company? Owner or invited team member. */
    public function accessibleBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->agent_id === $user->id
            || $this->members()->where('users.id', $user->id)->exists()
            || $user->hasRole(['super admin', 'administrator']);
    }
}
