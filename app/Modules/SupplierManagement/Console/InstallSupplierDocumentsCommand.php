<?php

namespace App\Modules\SupplierManagement\Console;

use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use App\Modules\SupplierManagement\Support\SupplierDocumentLibrary;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Install the South African supplier compliance documents, per tenant.
 *
 *   php artisan suppliers:documents:install
 *   php artisan suppliers:documents:install --company=4 --dry-run
 *
 * Per tenant and not as shared rows with a null company, which is how the contract template library
 * works. The row-level policy on `supplier_document_types` admits a row only when its company
 * matches the session, so a null-company row would install cleanly, show nothing to anybody, and
 * look like a bug in the screen rather than in the install.
 *
 * Idempotent on the library code. Matching on name would mean a tenant who renamed "Letter of Good
 * Standing" to "COIDA letter" gets a second copy on the next run, and then two entries a supplier
 * could upload against with no way to tell which one the expiry sweep is watching.
 */
class InstallSupplierDocumentsCommand extends Command
{
    protected $signature = 'suppliers:documents:install
                            {--company= : Install for one company instead of all}
                            {--refresh : Update the guidance and validity on types already installed}
                            {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Install the statutory supplier compliance document types for each tenant';

    public function handle(TenantContext $tenant): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companies = $this->companies();

        if ($companies === []) {
            $this->components->warn('No companies to install for.');

            return self::SUCCESS;
        }

        $this->components->info(count(SupplierDocumentLibrary::definitions()).' document type(s) for '
            .count($companies).' company/companies'.($dryRun ? ' (dry run — nothing will be written)' : ''));

        $created = $adopted = $updated = $skipped = 0;

        foreach ($companies as $companyId) {
            // Each company in its own context, so the model stamps the right owner and the policy
            // lets us read what is already there. A sweep that forgets this reads nothing and
            // cheerfully installs a second copy of everything.
            $tenant->run($companyId, function () use ($dryRun, &$created, &$adopted, &$updated, &$skipped) {
                foreach (SupplierDocumentLibrary::definitions() as $definition) {
                    [$outcome] = $this->install($definition, $dryRun);

                    match ($outcome) {
                        'created' => $created++,
                        'adopted' => $adopted++,
                        'updated' => $updated++,
                        default => $skipped++,
                    };
                }
            });
        }

        $this->components->info("Created {$created}, adopted {$adopted}, updated {$updated}, unchanged {$skipped}.");

        if ($adopted > 0) {
            $this->line('  Adopted means an existing type was recognised by name and given its library code,');
            $this->line('  rather than a second one being created beside it.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{0: string}
     */
    private function install(array $definition, bool $dryRun): array
    {
        $existing = SupplierDocumentType::query()->where('code', $definition['code'])->first();

        if ($existing) {
            if (! $this->option('refresh')) {
                return ['skipped'];
            }

            if (! $dryRun) {
                // The name and the compulsory flag are left alone on a refresh: both are decisions a
                // tenant is entitled to have made, and overwriting them would undo their work every
                // time the library gains a document.
                $existing->update([
                    'authority' => $definition['authority'],
                    'default_validity_months' => $definition['months'],
                    'guidance' => $definition['guidance'],
                ]);
            }

            return ['updated'];
        }

        // A type installed before codes existed, or renamed since — recognised rather than duplicated.
        $legacy = SupplierDocumentType::query()
            ->whereNull('code')
            ->get()
            ->first(fn (SupplierDocumentType $type) => (SupplierDocumentLibrary::legacyNames()[
                SupplierDocumentLibrary::normalise((string) $type->document_type_name)
            ] ?? null) === $definition['code']);

        if ($legacy) {
            if (! $dryRun) {
                $legacy->update([
                    'code' => $definition['code'],
                    'authority' => $definition['authority'],
                    'default_validity_months' => $definition['months'],
                    'guidance' => $definition['guidance'],
                ]);
            }

            return ['adopted'];
        }

        if (! $dryRun) {
            SupplierDocumentType::create([
                'uuid' => (string) Str::uuid(),
                'code' => $definition['code'],
                'document_type_name' => $definition['name'],
                'authority' => $definition['authority'],
                'default_validity_months' => $definition['months'],
                'guidance' => $definition['guidance'],
                'compulsory' => $definition['compulsory'],
                'active' => true,
            ]);
        }

        return ['created'];
    }

    /** @return list<int> */
    private function companies(): array
    {
        if ($one = $this->option('company')) {
            return [(int) $one];
        }

        return DB::table('companies')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
