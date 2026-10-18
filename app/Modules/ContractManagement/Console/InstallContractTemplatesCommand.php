<?php

namespace App\Modules\ContractManagement\Console;

use App\Modules\ContractManagement\Models\ContractsTemplate;
use App\Modules\ContractManagement\Templates\ContractTemplateLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Installs the starter contract library once, shared by every tenant.
 *
 *   php artisan contracts:templates:install
 *   php artisan contracts:templates:install --sector="Construction"
 *   php artisan contracts:templates:install --refresh
 *   php artisan contracts:templates:install --dry-run
 *
 * ── Why one shared copy and not one per tenant ──────────────────────────────
 *
 * A standard supply agreement does not differ by customer, and a copy per tenant means a
 * correction has to be applied to every one of them — or, more realistically, is applied to the
 * library and never reaches the tenants who already have it.
 *
 * The rows are written with a null company_id, which two things have to agree on: the
 * `tenant_isolation` policy on `contracts_templates` carries a null allowance (migration
 * 2026_08_10_000001) and `ContractsTemplate::sharesGlobalRecords()` returns true. If either is
 * removed the library still installs and every tenant sees an empty list.
 *
 * Tenants never write to these rows. The controller copies a shared template into the tenant's own
 * company on first edit, so customising the payment terms cannot rewrite anybody else's contract,
 * and the WITH CHECK half of the policy enforces the same thing at the database.
 *
 * ── Re-running ─────────────────────────────────────────────────────────────
 *
 * Safe by default: an existing library template is left alone. `--refresh` updates the body of
 * templates whose text has changed. Tenant copies are never touched by this command at all — they
 * belong to the tenant, and a shipped correction is not a reason to discard negotiated wording.
 */
class InstallContractTemplatesCommand extends Command
{
    protected $signature = 'contracts:templates:install
                            {--sector= : Only templates for this sector}
                            {--refresh : Update library templates whose shipped text has changed}
                            {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Install the shared starter contract template library for every sector';

    public function handle(): int
    {
        $definitions = $this->selectedDefinitions();

        if ($definitions === []) {
            $this->components->error('No templates match --sector='.$this->option('sector'));
            $this->line('  Available: '.implode(', ', $this->sectors()));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->components->info(count($definitions).' template(s) in the library'
            .($dryRun ? ' (dry run — nothing will be written)' : ''));

        $created = $updated = $skipped = 0;

        foreach ($definitions as $definition) {
            $name = ContractTemplateLibrary::displayName($definition);
            $content = ContractTemplateLibrary::render($definition);

            // Deliberately not the model: this has to see soft-deleted rows too, so that a library
            // template somebody removed on purpose is not resurrected by the next install. It also
            // has to bypass CompanyScope, which in a console run is inactive anyway.
            $existing = DB::table('contracts_templates')
                ->whereNull('company_id')
                ->where('name', $name)
                ->first(['id', 'content', 'deleted_at']);

            if ($existing !== null) {
                $this->refreshExisting($existing, $content, $dryRun) ? $updated++ : $skipped++;

                continue;
            }

            if (! $dryRun) {
                // company_id is left null on purpose — that is what makes it shared. The model's
                // auto-stamp does not fire because no tenant is active in a console run.
                ContractsTemplate::create([
                    'name' => $name,
                    'content' => $content,
                    'status' => true,
                ]);
            }

            $created++;
        }

        $this->newLine();
        $this->components->info("Library: {$created} added, {$updated} refreshed, {$skipped} unchanged.");

        if ($skipped > 0 && ! $this->option('refresh')) {
            $this->line('  <fg=gray>Run with --refresh to update templates whose shipped text has changed.</>');
        }

        return self::SUCCESS;
    }

    /** @return bool true when the row was (or would be) updated */
    private function refreshExisting(object $existing, string $content, bool $dryRun): bool
    {
        if ($existing->deleted_at !== null) {
            return false; // removed from the library on purpose
        }

        if ((string) $existing->content === $content) {
            return false; // already current
        }

        if (! $this->option('refresh')) {
            return false;
        }

        if (! $dryRun) {
            DB::table('contracts_templates')
                ->where('id', $existing->id)
                ->update(['content' => $content, 'updated_at' => now()]);
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    private function selectedDefinitions(): array
    {
        $sector = $this->option('sector');

        if ($sector === null) {
            return ContractTemplateLibrary::templates();
        }

        return array_values(array_filter(
            ContractTemplateLibrary::templates(),
            fn ($d) => strcasecmp($d['sector'], (string) $sector) === 0,
        ));
    }

    /** @return list<string> */
    private function sectors(): array
    {
        return array_values(array_unique(array_map(
            fn ($d) => $d['sector'],
            ContractTemplateLibrary::templates(),
        )));
    }
}
