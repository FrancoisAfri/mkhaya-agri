<?php

namespace App\Modules\Finance\Accounting\Console;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Tenancy\Services\Edition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Why a screen is showing the wrong business's paperwork.
 *
 * Everything the separation depends on resolves at request time — the edition, the tenant, the
 * business — and when one of them comes back null the module does not fail, it quietly stops
 * filtering. Two businesses' quotes then appear in one list and both sets of letterhead write to
 * one row, which looks like a bug in the lists rather than in the resolution underneath them.
 *
 * So this prints the resolution and the data side by side, on the installation that has the
 * problem, rather than inferring either from the other.
 */
class DiagnoseBusinesses extends Command
{
    protected $signature = 'finance:diagnose-businesses';

    protected $description = 'Show how Accounting is resolving businesses, and what the books look like';

    public function handle(): int
    {
        $this->line('');
        $this->components->twoColumnDetail('<fg=cyan>Edition</>', config('edition.edition', '(unset)')
            .(Edition::isSelfHosted() ? ' — self-hosted' : ' — multi-tenant'));

        $companies = DB::table('companies')
            ->when(
                DB::getSchemaBuilder()->hasColumn('companies', 'deleted_at'),
                fn ($q) => $q->whereNull('deleted_at')
            )
            ->get(['id', 'name', 'status']);

        $this->components->twoColumnDetail('<fg=cyan>Companies</>', (string) $companies->count()
            .' ('.$companies->where('status', 'active')->count().' active)');

        foreach ($companies as $company) {
            $this->line('');
            $this->components->info("Company {$company->id} — {$company->name} [{$company->status}]");

            $businesses = FinEntity::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->orderByDesc('is_default')->orderBy('name')
                ->get();

            if ($businesses->isEmpty()) {
                $this->components->warn('No businesses. Accounting will not scope anything here.');

                continue;
            }

            foreach ($businesses as $business) {
                $this->line('');
                $this->line("  <options=bold>{$business->name}</> (id {$business->id})"
                    .($business->is_default ? ' <fg=yellow>[default]</>' : '')
                    ." [{$business->status}]");

                foreach ($this->documentCounts($business->id) as $label => $count) {
                    $this->line("      {$label}: {$count}");
                }

                $this->line('      letterhead: '.$this->letterhead($company->id, $business));

                $series = FinNumberSeries::withoutGlobalScopes()
                    ->where('company_id', $company->id)->where('entity_id', $business->id)
                    ->orderBy('doc_type')->get();

                $this->line('      numbering: '.($series->isEmpty()
                    ? '<fg=yellow>no series yet</>'
                    : $series->map(fn ($s) => "{$s->prefix}{$s->next_number}")->implode('  ')));
            }

            $this->unattached($company->id);
        }

        $this->nobodys();

        $this->line('');
        $this->components->info('Rows listed as belonging to nobody are visible in every business.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function documentCounts(int $businessId): array
    {
        $counts = [];

        $of = [
            'fin_quotes'   => 'quotes',
            'fin_invoices' => 'invoices',
            'fin_payments' => 'receipts',
            // The books themselves, and the master data they are written against. A business with
            // no accounts of its own cannot be posted to, and one with no journals has no trial
            // balance — both worth seeing beside the document counts rather than assuming.
            'fin_journals' => 'journals',
            'fin_accounts' => 'accounts',
            'fin_parties'  => 'customers & suppliers',
            'fin_items'    => 'items',
        ];

        foreach ($of as $table => $label) {
            $counts[$label] = DB::table($table)->where('entity_id', $businessId)->count();
        }

        return $counts;
    }

    /** Documents that name the company but no business — hidden from every one of them. */
    private function unattached(int $companyId): void
    {
        foreach (['fin_quotes' => 'quotes', 'fin_invoices' => 'invoices', 'fin_payments' => 'receipts'] as $table => $label) {
            $count = DB::table($table)->where('company_id', $companyId)->whereNull('entity_id')->count();

            if ($count > 0) {
                $this->line('');
                $this->components->error("{$count} {$label} name this company but no business — hidden from all of them.");
            }
        }
    }

    /**
     * Rows with neither a company nor a business, which every business can see.
     *
     * The ledger is listed here as loudly as the documents were, and it is the more serious of the
     * two. FinJournal and FinAccount carry only the entity scope — no company scope underneath —
     * so an unstamped journal is not merely visible to an administrator, it is in every business's
     * trial balance, and a profit figure that silently includes another set of books is wrong in a
     * way nobody reading it can see.
     */
    private function nobodys(): void
    {
        $of = [
            'fin_quotes'        => 'quotes',
            'fin_invoices'      => 'invoices',
            'fin_payments'      => 'receipts',
            'fin_journals'      => 'journals',
            'fin_journal_lines' => 'journal lines',
            'fin_accounts'      => 'accounts',
            'fin_parties'       => 'customers & suppliers',
            'fin_items'         => 'items',
        ];

        foreach ($of as $table => $label) {
            $count = DB::table($table)->whereNull('company_id')->whereNull('entity_id')->count();

            if ($count > 0) {
                $this->line('');
                $this->components->error("{$count} {$label} belong to nobody — they appear in EVERY business.");
            }
        }
    }

    /**
     * Which letterhead this business would print on, resolved the way FinSetting::current() does.
     *
     * The default business has no row of its own until it saves one: it reads the row the tenant
     * had before businesses existed, which names no business. Looking only for its own row reports
     * a letterhead as missing when it is there and printing, so the check has to follow the same
     * order the renderer does or it answers a different question.
     */
    private function letterhead(int $companyId, FinEntity $business): string
    {
        $own = FinSetting::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('entity_id', $business->id)->first();

        if ($own) {
            return 'own row — '.($own->company_name ?: 'no name').', logo '.$this->logoState($own);
        }

        if ($business->is_default) {
            $legacy = FinSetting::withoutGlobalScopes()
                ->where('company_id', $companyId)->whereNull('entity_id')->first();

            if ($legacy) {
                return '<fg=yellow>the pre-businesses row</> — '.($legacy->company_name ?: 'no name')
                    .', logo '.$this->logoState($legacy).' (stamped on next save)';
            }
        }

        return '<fg=red>none — this business prints unbranded</>';
    }

    private function logoState(FinSetting $settings): string
    {
        if ($settings->logo_path) {
            return 'on disk';
        }

        // Before logo_path became fillable an upload wrote the file and dropped its location, so a
        // row with neither is the shape that bug left behind.
        return $settings->logo ? 'in column (old style)' : '<fg=red>NONE</>';
    }
}
