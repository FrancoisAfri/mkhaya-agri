<?php

namespace App\Console\Commands\ProjectManagement;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Services\AccountMap;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Switch retention posting on, once somebody has decided where it goes.
 *
 * Certified claims already carry retention and the ledger entry is built, but it deliberately posts
 * nothing until an account is chosen. The reason is that no chart on this platform has one: there
 * is `1250 Retention Receivable`, which is the opposite case — retention a *client* holds from us —
 * and nothing for retention *we* hold from a subcontractor. Inventing a liability account in every
 * tenant's chart is an accounting decision, not a code change, so it is made here by a person
 * running a command rather than silently by a migration.
 *
 * What it does: creates the account if it is missing, maps `claim.retention_payable` to it, and
 * says what it did. Idempotent, and it never overwrites a mapping somebody has already set — a
 * tenant who has chosen their own account keeps it.
 */
class EnableRetentionPosting extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:enable-retention-posting
        {--company= : Only this company}
        {--code=2150 : The account code to use}
        {--name=Retention Payable : The account name, when it has to be created}
        {--apply : Create and map it (otherwise report what would happen)}';

    protected $description = 'Choose the liability account certified retention is held in';

    public function handle(TenantContext $tenants): int
    {
        $code = (string) $this->option('code');
        $name = (string) $this->option('name');
        $apply = (bool) $this->option('apply');

        $this->eachTenant($tenants, function ($companyId) use ($code, $name, $apply) {
            $map = app(AccountMap::class);
            $existing = $map->codeFor('project_management', 'claim.retention_payable');

            if ($existing !== null) {
                $this->line("company {$companyId}: already posting retention to {$existing} — left alone.");

                return;
            }

            $account = FinAccount::where('code', $code)->first();

            if ($account && (! $account->is_postable || ! $account->is_active)) {
                // A heading or a retired account carries no journal lines. Saying so beats mapping
                // it and having every claim fail quietly afterwards.
                $this->warn("company {$companyId}: account {$code} exists but cannot be posted to.");

                return;
            }

            if (! $apply) {
                $this->line("company {$companyId}: would " . ($account ? 'map' : 'create and map') . " {$code} {$name}.");

                return;
            }

            $account ??= FinAccount::create([
                'code' => $code, 'name' => $name, 'type' => 'liability',
                'is_postable' => true, 'is_active' => true,
            ]);

            $map->set('project_management', 'claim.retention_payable', $account->code);

            $this->info("company {$companyId}: retention now posts to {$account->code} {$account->name}.");
        });

        if (! $apply) {
            $this->newLine();
            $this->line('Nothing was changed. Re-run with --apply once the account is agreed.');
        }

        return self::SUCCESS;
    }
}
