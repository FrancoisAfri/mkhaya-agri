<?php

namespace App\Console\Commands\Tenancy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the rows `tenancy:backfill-orphans` cannot assign.
 *
 * A row with a null company_id is invisible to every tenanted user. Most can simply be given an
 * owner; a few cannot, because the target company already has a row with the same key, and giving
 * the orphan an owner would create two rows claiming to be the same thing. The backfill reports
 * those and stops, which is correct — which of the two is real is a question about the data, not
 * about the schema.
 *
 * This answers that question for the one shape it takes here: a reference table seeded twice, once
 * without an owner and once with. The complete set is kept, the partial one is folded into it, and
 * anything pointing at the discarded rows is repointed first so no reference is left dangling.
 *
 * Dry by default. It deletes rows, so it should be read before it is run.
 *
 *   php artisan tenancy:resolve-collisions --company=1
 *   php artisan tenancy:resolve-collisions --company=1 --execute
 */
class ResolveOrphanCollisions extends Command
{
    protected $signature = 'tenancy:resolve-collisions
        {--company= : The company the orphaned rows should end up belonging to.}
        {--table=* : Restrict to these tables.}
        {--execute : Actually write. Without this the command only reports.}';

    protected $description = 'Fold duplicate reference rows together so orphans can be assigned an owner.';

    /**
     * How each table's collision is resolved, and the column the collision is on.
     *
     * Three shapes turn up, and they need different treatment — which is the whole reason the
     * backfill refuses to guess:
     *
     *   fold      the same row seeded twice. A chart of accounts was inserted once without an owner
     *             and once, partially, with. Keeping the complete set and folding the partial one
     *             into it loses nothing, because the rows genuinely are the same accounts.
     *
     *   renumber  two different records that collided on a generated code. The orphan was invisible
     *             to the generator — row-level security hides it — so the sequence handed the same
     *             number out twice. Folding these would delete a real record: "Project Yello Africa
     *             B", active, is not "Yello Project", pending. The orphan gets a free code instead
     *             and both survive.
     *
     *   supersede an append-only ledger, where neither of the above is legal. The row cannot be
     *             updated (company_id is hashed into the chain) and cannot be deleted (a trigger
     *             forbids it), so the orphan is re-recorded under its owner and marked as
     *             superseded by the replacement. Nothing is rewritten and nothing is lost.
     *
     * A short named list rather than anything inferred. Deciding which of two rows is real is
     * exactly the judgement that must not be automated across tables nobody has looked at.
     */
    private const STRATEGIES = [
        'fin_accounts' => ['strategy' => 'fold', 'key' => 'code'],
        'budgets' => ['strategy' => 'renumber', 'key' => 'code'],
        'pm_evidence_events' => ['strategy' => 'supersede', 'key' => 'uuid'],
    ];

    public function handle(): int
    {
        $companyId = (int) $this->option('company');

        if ($companyId <= 0 || ! DB::table('companies')->where('id', $companyId)->exists()) {
            $this->error('Give me a real --company= id.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $only = (array) $this->option('table');

        // The orphans are precisely the rows a tenant context hides.
        DB::statement("SELECT set_config('app.company_id', '', false)");

        $execute
            ? $this->info('Folding duplicates and assigning orphans to company ' . $companyId . '.')
            : $this->warn('Dry run. Nothing is written.');

        $totals = ['folded' => 0, 'repointed' => 0, 'renumbered' => 0, 'superseded' => 0, 'assigned' => 0];

        foreach (self::STRATEGIES as $table => $plan) {
            if (! Schema::hasTable($table) || ($only !== [] && ! in_array($table, $only, true))) {
                continue;
            }

            match ($plan['strategy']) {
                'fold' => $this->foldDuplicates($table, $plan['key'], $companyId, $execute, $totals),
                'renumber' => $this->renumberOrphans($table, $plan['key'], $companyId, $execute, $totals),
                'supersede' => $this->supersedeOrphanChain($table, $companyId, $execute, $totals),
            };
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d duplicate(s), repointed %d, renumbered %d, superseded %d, assigned %d orphan(s).',
            $execute ? 'Folded' : 'Would fold',
            $totals['folded'], $totals['repointed'], $totals['renumbered'],
            $totals['superseded'], $totals['assigned'],
        ));

        return self::SUCCESS;
    }

    /** @param array<string,int> $totals */
    private function foldDuplicates(string $table, string $key, int $companyId, bool $execute, array &$totals): void
    {
        $orphans = DB::table($table)->whereNull('company_id')->count();

        if ($orphans === 0) {
            return;
        }

        $owned = DB::table($table)->where('company_id', $companyId)->get(['id', $key]);

        $this->newLine();
        $this->line("  <fg=cyan>{$table}</> — {$orphans} orphan(s), {$owned->count()} already owned by this company");

        $references = $this->referencesTo($table);

        foreach ($owned as $duplicate) {
            $twin = DB::table($table)
                ->whereNull('company_id')
                ->where($key, $duplicate->{$key})
                ->first(['id']);

            if (! $twin) {
                // No collision: this row is simply the company's own and stays.
                continue;
            }

            /*
             * The orphan is kept and the owned row folded into it, not the other way round.
             *
             * In every case seen here the orphaned set is the complete one — a full chart of
             * accounts seeded seconds after a partial attempt — so keeping it preserves the rows
             * that have no duplicate at all. Repointing happens first: a reference left aimed at a
             * deleted row is worse than the duplication being fixed.
             */
            $moved = 0;

            foreach ($references as [$refTable, $refColumn]) {
                $pointing = DB::table($refTable)->where($refColumn, $duplicate->id)->count();

                if ($pointing === 0) {
                    continue;
                }

                if ($execute) {
                    DB::table($refTable)->where($refColumn, $duplicate->id)->update([$refColumn => $twin->id]);
                }

                $moved += $pointing;
                $this->line(sprintf('    %s.%s: %d reference(s) → #%d', $refTable, $refColumn, $pointing, $twin->id));
            }

            if ($execute) {
                DB::table($table)->where('id', $duplicate->id)->delete();
            }

            $this->line(sprintf('    %s %s: duplicate #%d folded into #%d',
                $key, $duplicate->{$key}, $duplicate->id, $twin->id));

            $totals['folded']++;
            $totals['repointed'] += $moved;
        }

        if ($execute) {
            DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);
        }

        $totals['assigned'] += $orphans;
    }


    /**
     * Gives colliding orphans a free code so both records survive.
     *
     * Nothing is deleted and no reference moves: only the code changes, and references are by id.
     * The record keeps its identity, its lines and its history — it simply stops claiming a number
     * that now belongs to something else.
     *
     * @param array<string,int> $totals
     */
    private function renumberOrphans(string $table, string $key, int $companyId, bool $execute, array &$totals): void
    {
        $orphans = DB::table($table)->whereNull('company_id')->get(['id', $key, 'name']);

        if ($orphans->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line("  <fg=cyan>{$table}</> — {$orphans->count()} orphan(s)");

        foreach ($orphans as $orphan) {
            $taken = DB::table($table)
                ->where('company_id', $companyId)
                ->where($key, $orphan->{$key})
                ->exists();

            if (! $taken) {
                $this->line(sprintf('    %s: no collision, assigned as-is', $orphan->{$key}));

                continue;
            }

            $replacement = $this->nextFreeCode($table, $key, (string) $orphan->{$key});

            if ($execute) {
                DB::table($table)->where('id', $orphan->{'id'})->update([$key => $replacement]);
            }

            $this->line(sprintf('    %s "%s" → %s (both records kept)',
                $orphan->{$key}, substr((string) ($orphan->name ?? ''), 0, 30), $replacement));

            $totals['renumbered']++;
        }

        if ($execute) {
            DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);
        }

        $totals['assigned'] += $orphans->count();
    }

    /**
     * The next unused code following the same pattern.
     *
     * Increments the trailing digits and keeps their width, so BGT202600001 becomes BGT202600007
     * rather than something a person would not recognise as one of their own budget numbers. Checks
     * the whole table rather than one company: the collision exists precisely because a row was
     * invisible to whatever numbered the other one.
     */
    private function nextFreeCode(string $table, string $key, string $current): string
    {
        if (! preg_match('/^(.*?)(\d+)$/', $current, $parts)) {
            return $current . '-1';
        }

        [, $prefix, $digits] = $parts;
        $width = strlen($digits);
        $next = (int) $digits;

        do {
            $next++;
            $candidate = $prefix . str_pad((string) $next, $width, '0', STR_PAD_LEFT);
        } while (DB::table($table)->where($key, $candidate)->exists());

        return $candidate;
    }


    /**
     * Re-records an orphaned hash chain under its owner instead of deleting it.
     *
     * An append-only ledger hashes company_id into every event and chains each hash to the one
     * before, so stamping an owner onto an existing row changes what it hashes to and breaks every
     * link after it. Verification failing there is the ledger working — a backfill is
     * indistinguishable from tampering because it is tampering, and recomputing the hashes to hide
     * that would forge the exact record the chain exists to protect. The table enforces this
     * itself: a trigger refuses DELETE outright and permits no UPDATE except superseded_by.
     *
     * That trigger is also why this cannot simply drop the run, which is what it used to try. The
     * orphans are not debris — an event carries a project, and the project has an owner, so these
     * are a real tenant's evidence made invisible by row-level security. Deleting them would throw
     * away that tenant's history, and the next event appended for the project would start a second
     * chain from nothing, because the first is hidden from the query that looks for it.
     *
     * So each orphan is superseded: a replacement carrying the same content is appended under the
     * owning company through the ledger's own API, hashed properly into that company's chain, and
     * the orphan is marked as superseded by it. Nothing is rewritten and nothing is lost — the
     * original stays exactly as written, pointing at what replaced it.
     *
     * @param array<string,int> $totals
     */
    private function supersedeOrphanChain(string $table, int $companyId, bool $execute, array &$totals): void
    {
        $orphans = DB::table($table)->whereNull('company_id')->orderBy('occurred_at')->orderBy('id')->get();

        if ($orphans->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line("  <fg=cyan>{$table}</> — {$orphans->count()} orphaned event(s)");

        /*
         * Only events whose project belongs to the company being repaired. An orphan for somebody
         * else's project is not this run's to adopt, and guessing would hand one tenant another
         * tenant's evidence.
         */
        $ownedProjects = DB::table('pm_projects')->where('company_id', $companyId)->pluck('id')->all();

        $ledger = app(\App\Modules\Operations\ProjectManagement\Services\EvidenceLedger::class);

        foreach ($orphans as $orphan) {
            if (! in_array($orphan->project_id, $ownedProjects, true)) {
                $this->line("    <fg=yellow>skipped {$orphan->uuid} — project {$orphan->project_id} is not company {$companyId}'s</>");

                continue;
            }

            if ($orphan->superseded_by !== null) {
                $this->line("    already superseded: {$orphan->uuid}");

                continue;
            }

            if (! $execute) {
                $this->line("    would supersede {$orphan->event_type} ({$orphan->uuid}) under company {$companyId}");
                $totals['superseded']++;

                continue;
            }

            $original = \App\Modules\Operations\ProjectManagement\Models\EvidenceEvent::query()
                ->where('uuid', $orphan->uuid)
                ->firstOrFail();

            // The replacement is the same event, not a correction of it: same type, same subject,
            // same measurement, same time. The only thing being corrected is who it belongs to.
            $replacement = $ledger->supersede($original, [
                'company_id' => $companyId,
                'event_type' => $orphan->event_type,
                'source_module' => $orphan->source_module,
                'source_ref' => $orphan->source_ref,
                'actor_type' => $orphan->actor_type,
                'actor_id' => $orphan->actor_id,
                'quantity' => $orphan->quantity,
                'unit' => $orphan->unit,
                'amount' => $orphan->amount,
                'currency' => $orphan->currency,
                'confidence' => $orphan->confidence,
                'geo_lat' => $orphan->geo_lat,
                'geo_lng' => $orphan->geo_lng,
                'payload' => json_decode($orphan->payload ?? '{}', true) ?: [],
                'attachments' => json_decode($orphan->attachments ?? '[]', true) ?: [],
            ]);

            $this->line("    {$orphan->event_type} ({$orphan->uuid}) → {$replacement->uuid}");
            $totals['superseded']++;
        }
    }

    /**
     * Every foreign key pointing at this table.
     *
     * Read from the schema rather than listed by hand: a reference added after this was written
     * would otherwise be silently left dangling, and the failure would surface as a report that
     * cannot load rather than as anything pointing here.
     *
     * @return list<array{0:string,1:string}>
     */
    private function referencesTo(string $table): array
    {
        $rows = DB::select("
            select tc.table_name, kcu.column_name
            from information_schema.table_constraints tc
            join information_schema.key_column_usage kcu on tc.constraint_name = kcu.constraint_name
            join information_schema.constraint_column_usage ccu on tc.constraint_name = ccu.constraint_name
            where tc.constraint_type = 'FOREIGN KEY' and ccu.table_name = ?
        ", [$table]);

        return array_map(fn ($r) => [$r->table_name, $r->column_name], $rows);
    }
}
