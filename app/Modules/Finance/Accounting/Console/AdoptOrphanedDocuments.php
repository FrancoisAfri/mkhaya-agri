<?php

namespace App\Modules\Finance\Accounting\Console;

use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Tenancy\Services\Edition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give the books that belong to nobody back to the business they came from.
 *
 * Documents written before tenancy carry neither a company nor a business. The scope treats that
 * as "belongs to nobody" and lets them through everywhere, which is right for the handful of
 * system accounts it was built for and badly wrong for 115 quotes: they appear in every business,
 * so one client's quote history is on another business's screen and no business can be trusted to
 * show its own.
 *
 * The migration deliberately left them. There was no safe answer while a tenant might hold several
 * companies — stamping a document onto the wrong one is worse than leaving it visible — so the
 * decision was left to somebody who knows the installation. This is that decision, made explicitly
 * and shown before it is taken.
 *
 * Nothing is written without --apply.
 */
class AdoptOrphanedDocuments extends Command
{
    protected $signature = 'finance:adopt-orphaned-documents
        {--business= : The business to adopt them into; defaults to the company\'s default}
        {--apply : Actually write the changes}';

    protected $description = 'Attach pre-tenancy finance documents to a business so they stop appearing in all of them';

    public function handle(): int
    {
        $company = $this->theCompany();

        if ($company === null) {
            return self::FAILURE;
        }

        $business = $this->theBusiness($company);

        if ($business === null) {
            return self::FAILURE;
        }

        $this->line('');
        $this->components->twoColumnDetail('Company', "{$company->name} (id {$company->id})");
        $this->components->twoColumnDetail('Adopting into', "{$business->name} (id {$business->id})"
            .($business->is_default ? ' [default]' : ''));

        $orphans = $this->orphans();

        $this->line('');

        /*
         * Not "shared rows". This cannot tell a row the system installed on purpose from one of
         * yours that was never stamped, and the first wording claimed it could — which read as
         * reassurance over 185 unstamped journals sitting in both sets of books.
         *
         * So it says what it knows: these were not adopted because they are not documents, and
         * whether that is right is a judgement about the installation.
         */
        foreach ($this->leftAlone() as $table => $count) {
            $this->components->twoColumnDetail(
                "<fg=yellow>{$table}</>",
                "<fg=yellow>{$count} unowned — not a document, not adopted</>"
            );
        }

        if ($orphans === []) {
            $this->line('');
            $this->components->info('No documents belong to nobody. Nothing to do.');

            return self::SUCCESS;
        }

        foreach ($orphans as $table => $count) {
            $this->components->twoColumnDetail($table, "{$count} documents");
        }

        $this->theLedgerWarning();

        if (! $this->option('apply')) {
            $this->line('');
            $this->components->warn('Nothing written. Re-run with --apply to adopt these.');

            return self::SUCCESS;
        }

        $this->adopt($orphans, $company->id, $business->id);

        return self::SUCCESS;
    }

    private function theCompany(): ?object
    {
        $companies = DB::table('companies')
            ->when(
                DB::getSchemaBuilder()->hasColumn('companies', 'deleted_at'),
                fn ($q) => $q->whereNull('deleted_at')
            )
            ->get(['id', 'name', 'status']);

        if ($companies->count() === 1) {
            return $companies->first();
        }

        $active = $companies->where('status', 'active');

        if (Edition::isSelfHosted() && $active->count() === 1) {
            return $active->first();
        }

        // Several live companies means a document could belong to any of them, and guessing here
        // is the cross-tenant mistake this command exists to avoid being made by hand.
        $this->components->error(
            'This installation holds '.$companies->count().' companies, so which one these '
            .'documents belong to is not something this command can decide. Adopt them by hand.'
        );

        return null;
    }

    private function theBusiness(object $company): ?FinEntity
    {
        $businesses = FinEntity::withoutGlobalScopes()->where('company_id', $company->id);

        if ($chosen = $this->option('business')) {
            $business = (clone $businesses)
                ->where(is_numeric($chosen) ? 'id' : 'name', $chosen)->first();

            if (! $business) {
                $this->components->error("No business [{$chosen}] under {$company->name}.");

                return null;
            }

            return $business;
        }

        $default = (clone $businesses)->where('is_default', true)->first();

        if (! $default) {
            $this->components->error(
                "{$company->name} has no default business. Name one with --business."
            );
        }

        return $default;
    }

    /**
     * Finance tables holding documents that name neither a company nor a business.
     *
     * A document is recognised by carrying a number, and that test is doing real work rather than
     * saving a list. Not every unowned row is a stray: the chart of accounts and the account
     * mappings ship rows with no company and no business on purpose — Bad Debts, Retention
     * Receivable, the contras every tenant posts to — and the scope lets those through everywhere
     * *because* they belong to nobody. Adopting them into one business would take the system's own
     * accounts away from every other one and break posting across the install.
     *
     * Documents are numbered; shared library rows are not. So the tables this will write to are
     * the ones with a number column, and everything else is reported and left alone.
     *
     * @return array<string, int>
     */
    private function orphans(): array
    {
        return $this->unownedRowsIn(true);
    }

    /** The unowned rows this command will not touch, so they are at least visible. */
    private function leftAlone(): array
    {
        return $this->unownedRowsIn(false);
    }

    /**
     * @param  bool  $numbered  documents when true, shared library rows when false
     * @return array<string, int>
     */
    private function unownedRowsIn(bool $numbered): array
    {
        $found = [];

        $tables = DB::select(
            "select table_name from information_schema.columns
             where table_schema = 'public' and column_name = 'entity_id' and table_name like 'fin\\_%'"
        );

        foreach (array_column($tables, 'table_name') as $table) {
            $schema = DB::getSchemaBuilder();

            if (! $schema->hasColumn($table, 'company_id')) {
                continue;
            }

            if ($schema->hasColumn($table, 'number') !== $numbered) {
                continue;
            }

            $count = DB::table($table)->whereNull('company_id')->whereNull('entity_id')->count();

            if ($count > 0) {
                $found[$table] = $count;
            }
        }

        return $found;
    }

    /** @param  array<string, int>  $orphans */
    private function adopt(array $orphans, int $companyId, int $businessId): void
    {
        $this->line('');

        DB::transaction(function () use ($orphans, $companyId, $businessId) {
            foreach ($orphans as $table => $count) {
                DB::table($table)
                    ->whereNull('company_id')->whereNull('entity_id')
                    ->update(['company_id' => $companyId, 'entity_id' => $businessId]);

                $this->components->task("adopted {$count} from {$table}", fn () => true);
            }

            /*
             * The series only reads the books when it is created, so adopting a batch afterwards
             * leaves it pointing at a number the business has already used. Without this the very
             * next quote would reuse one a client has seen.
             */
            $moved = FinNumberSeries::catchUp($companyId, $businessId);

            foreach ($moved as $prefix => $range) {
                $this->components->task("numbering {$prefix}{$range['from']} → {$prefix}{$range['to']}", fn () => true);
            }
        });

        $this->line('');
        $this->components->info('Done. Run finance:diagnose-businesses to confirm.');
    }

    /**
     * Say plainly when the general ledger is one of the things sitting unowned.
     *
     * FinJournal and FinAccount carry only the entity scope, with no company scope underneath, so
     * an unstamped journal is not merely visible to an administrator who has not chosen a tenant —
     * it is in every business's trial balance. A business with no books of its own shows another
     * business's profit as its own, and that reads as a correct report.
     *
     * This command will not move them on its own. Which business a ledger belongs to is not
     * something to infer from a row count, and a journal moved to the wrong set of books is harder
     * to undo than one left where it is.
     */
    private function theLedgerWarning(): void
    {
        $journals = DB::table('fin_journals')->whereNull('company_id')->whereNull('entity_id')->count();

        if ($journals === 0) {
            return;
        }

        $lines = DB::table('fin_journal_lines')->whereNull('company_id')->whereNull('entity_id')->count();

        $this->line('');
        $this->components->error(
            "{$journals} journals and {$lines} journal lines belong to nobody, which puts them in "
            .'EVERY business\'s trial balance, profit and loss and balance sheet. This command '
            .'does not move the ledger — that is a decision about whose books these are.'
        );
    }
}
