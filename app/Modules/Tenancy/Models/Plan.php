<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Services\LaunchWindow;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'plans';

    protected $fillable = ['code', 'name', 'price_monthly', 'regular_price_monthly', 'max_users', 'ai_token_quota', 'sms_quota', 'storage_gb', 'support', 'modules', 'is_active', 'sort'];

    protected $casts = ['modules' => 'array', 'support' => 'array', 'price_monthly' => 'decimal:2', 'regular_price_monthly' => 'decimal:2', 'is_active' => 'boolean', 'ai_token_quota' => 'integer', 'sms_quota' => 'integer', 'storage_gb' => 'integer'];

    /** null modules = all modules included. */
    public function includesAllModules(): bool
    {
        return $this->modules === null;
    }

    /**
     * The price a company pays right now. Founding customers keep the launch
     * price until their lock expires; everyone else pays the regular price once
     * the launch promotion is over. When `$company` is null (or has no lock), the
     * launch price is returned during the promo and the regular price after it.
     */
    public function effectivePrice(?Company $company = null): float
    {
        $launch = (float) $this->price_monthly;
        $regular = $this->regular_price_monthly !== null ? (float) $this->regular_price_monthly : $launch;

        if ($company && $company->price_locked_until && $company->price_locked_until->isFuture()) {
            return $launch; // founding customer within their locked window
        }

        return LaunchWindow::isOpen() ? $launch : $regular;
    }
}
