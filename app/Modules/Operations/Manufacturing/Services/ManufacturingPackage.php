<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Which manufacturing package the current company holds (MFG-025), read from the subscription
 * system's own entitlements -- the same check every module uses -- never stored here.
 */
class ManufacturingPackage
{
    /** Highest first. */
    private const MARKERS = ['ENTERPRISE' => 'manufacturing_enterprise', 'PROFESSIONAL' => 'manufacturing_professional', 'ESSENTIALS' => 'manufacturing_essentials'];

    public function __construct(private readonly CompanyEntitlements $entitlements, private readonly CurrentCompany $current) {}

    public function code(): string
    {
        // Platform administration and console work act for no company: nothing to package.
        if (! $this->current->get() && ! \App\Modules\Tenancy\Services\Edition::isSelfHosted()) {
            return 'ENTERPRISE';
        }
        foreach (self::MARKERS as $code => $marker) {
            if ($this->holds($marker)) {
                return $code;
            }
        }

        return strtoupper((string) config('manufacturing.default_package', 'ENTERPRISE'));
    }

    public function definition(): array
    {
        $packages = config('manufacturing.packages');

        return $packages[$this->code()] ?? $packages['ENTERPRISE'];
    }

    public function allowsFeature(string $feature): bool
    {
        $features = $this->definition()['features'];

        return in_array('*', $features, true) || in_array($feature, $features, true);
    }

    public function maxFactories(): ?int
    {
        return $this->definition()['max_factories'];
    }

    /** An overdue invoice puts manufacturing in read-only (MFG-025), under the platform's own billing records. */
    public function readOnly(): bool
    {
        $company = $this->current->get();
        if (! $company || ! config('manufacturing.read_only_when_overdue', true)) {
            return false;
        }

        return DB::table('subscription_invoices')->where('company_id', $company->id)->where('status', 'overdue')->exists()
            || DB::table('subscriptions')->where('company_id', $company->id)->where('status', 'past_due')->exists();
    }

    private function holds(string $marker): bool
    {
        $company = $this->current->get();
        if ($company instanceof Company && ! \App\Modules\Tenancy\Services\Edition::isSelfHosted()) {
            $modules = $this->entitlements->modules($company);

            // A plan that includes every module includes every package.
            return $modules === null || in_array($marker, $modules, true);
        }

        return $this->entitlements->currentAllowsModule($marker);
    }
}
