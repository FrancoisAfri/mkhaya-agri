<?php

namespace App\Modules\Procurement\Governance\Console;

use App\Support\Console\RunsPerTenant;
use App\Modules\Procurement\Governance\Services\AuditLedger;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * Verifies the §7 audit chain (`procgov:verify-ledger`).
 *
 * The table's append-only trigger stops anything reachable through the application from editing
 * history. This command defends against the case the trigger cannot: someone with direct database
 * access who could drop it. Every event hashes its own content together with its predecessor's
 * hash, so altering or removing any row breaks every hash after it, and this reports the exact
 * sequence number where the chain parts.
 *
 *   php artisan procgov:verify-ledger
 *   php artisan procgov:verify-ledger --company=7
 *
 * Exits non-zero on any failure, so it can be wired to an alert rather than read by hand. A silent
 * integrity check that nobody looks at is not an integrity check.
 */
class VerifyLedgerCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'procgov:verify-ledger
                            {--company= : Restrict to one company id}';

    protected $description = 'Verify the Procurement Governance audit chain for tampering';

    public function handle(TenantContext $tenants, AuditLedger $ledger): int
    {

        $broken = [];
        $checked = 0;

        $verify = function (int $companyId) use ($ledger, &$broken, &$checked): void {
            $result = $ledger->verify($companyId);
            $checked += $result['checked'];

            if ($result['ok']) {
                $this->components->twoColumnDetail(
                    "Company {$companyId}",
                    "<fg=green>verified</> ({$result['checked']} events)"
                );

                return;
            }

            $broken[] = $companyId;

            $this->components->twoColumnDetail(
                "Company {$companyId}",
                "<fg=red>BROKEN at sequence {$result['broken_at']}</> — {$result['reason']}"
            );
        };

        $this->eachTenant($tenants, $verify);

        if ($broken !== []) {
            $this->components->error(sprintf(
                'Audit chain integrity FAILED for %d company/companies: %s. Investigate immediately — '
                .'this indicates the audit table was modified outside the application.',
                count($broken),
                implode(', ', $broken),
            ));

            return self::FAILURE;
        }

        /*
         * A tenant whose verification *crashed* is not a tenant whose chain is intact, and this is
         * the one command where conflating the two would be actively dangerous: "no tampering
         * detected" is what somebody reads when deciding not to investigate.
         */
        if ($this->hadTenantFailures()) {
            $this->reportTenantFailures('Audit chain verification');

            return self::FAILURE;
        }

        $this->components->info("Audit chain verified across {$checked} event(s). No tampering detected.");

        return self::SUCCESS;
    }
}
