<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\AgriExportedJournal;
use App\Modules\Agri\Models\AgriFinanceExport;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmTransaction;
use App\Modules\Agri\Models\Feeding;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\LivestockProduction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §6.5: Mkhaya Agri never keeps money of its own. Every farm transaction is posted once to
 * the shared ledger (Mkhaya Hub Finance) under an idempotency key, so a retry can never double an
 * expense, a sale or a stock issue. This class proves it and connects it onwards:
 *
 *  - reconcile(): every operational record that should have posted, checked for its posting, and
 *    the farm's operational totals set against the books, category by category;
 *  - trace(): from any journal back to the farm record it came from;
 *  - export() / acknowledge(): when another accounting system is the source of truth, the farm's
 *    journals go to it once each, with an external reference, a status and the error if it failed.
 */
class FinanceIntegration
{
    /**
     * @return array{records: list<array<string, mixed>>, untraced: list<array<string, mixed>>, by_category: list<array<string, mixed>>, ok: bool}
     */
    public function reconcile(Farm $farm, string $from, string $to): array
    {
        $checks = [];
        $untraced = [];
        $check = function (string $kind, $rows, callable $posted, callable $label) use (&$checks, &$untraced) {
            $n = 0;
            $bad = 0;
            foreach ($rows as $r) {
                $n++;
                if (! $posted($r)) {
                    $bad++;
                    $untraced[] = ['kind' => $kind, 'record' => $label($r)];
                }
            }
            $checks[] = ['kind' => $kind, 'records' => $n, 'posted' => $n - $bad, 'missing' => $bad];
        };
        $movementJournal = fn ($id) => $id && DB::table('fin_stock_movements')->where('id', $id)->whereNotNull('journal_id')->exists();

        $check('Inputs issued to fields', ActivityInput::query()->where('farm_id', $farm->id)->whereIn('status', [ActivityInput::ISSUED, ActivityInput::REVERSED])
            ->whereBetween('issued_at', [$from.' 00:00:00', $to.' 23:59:59'])->get(), fn ($i) => $movementJournal($i->stock_movement_id), fn ($i) => "input {$i->uuid}");
        $check('Farm expenses and income', FarmTransaction::query()->where('farm_id', $farm->id)->whereBetween('transacted_on', [$from, $to])->get(),
            fn ($t) => $t->journal_id || $movementJournal($t->stock_movement_id), fn ($t) => "{$t->kind} {$t->description}");
        $check('Harvests into store or sold', HarvestRecord::query()->where('farm_id', $farm->id)->whereBetween('harvested_on', [$from, $to])
            ->where(fn ($q) => $q->where('destination', 'store')->orWhere(fn ($q) => $q->where('destination', 'sold')->where('unit_value', '>', 0)))->get(),
            fn ($h) => $h->journal_id || $movementJournal($h->stock_movement_id), fn ($h) => "harvest {$h->lot_code}");
        $check('Treatments from the store', AnimalHealthRecord::query()->where('farm_id', $farm->id)->whereNotNull('stock_item_id')->where('quantity', '>', 0)
            ->whereBetween('treated_on', [$from, $to])->get(), fn ($h) => $movementJournal($h->stock_movement_id), fn ($h) => "treatment {$h->uuid}");
        $check('Feed issued', Feeding::query()->where('farm_id', $farm->id)->whereBetween('fed_on', [$from, $to])->get(),
            fn ($f) => collect($f->issues ?? [])->every(fn ($x) => $movementJournal($x['movement_id'] ?? null)) && ! empty($f->issues), fn ($f) => "feeding {$f->uuid}");
        $check('Animals sold', Animal::query()->where('farm_id', $farm->id)->where('status', 'sold')->where('exit_value', '>', 0)->whereBetween('exited_on', [$from, $to])->get(),
            fn ($a) => (bool) $a->exit_journal_id, fn ($a) => "animal {$a->tag_number}");
        $check('Milk, eggs, wool sold', LivestockProduction::query()->where('farm_id', $farm->id)->where('status', 'sold')->where('value', '>', 0)->whereBetween('recorded_on', [$from, $to])->get(),
            fn ($p) => (bool) $p->journal_id, fn ($p) => "lot {$p->lot_code}");

        // Operational totals against the books: what the farm records say was issued, and what the
        // stock-issue journals for the same records carry. A correction reverses the original line in
        // the books, so the operational side counts lines still standing (issued, not reversed).
        $ops = [
            'inputs' => (float) DB::table('activity_inputs as i')->join('fin_stock_movements as m', 'm.id', '=', 'i.stock_movement_id')
                ->where('i.farm_id', $farm->id)->where('i.status', ActivityInput::ISSUED)->whereBetween('m.movement_date', [$from, $to])->sum('i.total_cost'),
            'feed' => (float) Feeding::query()->where('farm_id', $farm->id)->whereBetween('fed_on', [$from, $to])->sum('cost'),
        ];
        $sources = ['inputs' => 'agri_activity', 'feed' => 'agri_feeding'];
        $byCategory = [];
        foreach ($ops as $cat => $operational) {
            $books = (float) DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.company_id', $farm->company_id)->where('j.status', '!=', 'draft')->where('a.type', 'expense')->whereBetween('j.journal_date', [$from, $to])
                ->whereRaw("l.dimensions->>'agri_farm' = ?", [(string) $farm->id])->whereRaw("l.dimensions->>'agri_category' = ?", [$cat])
                ->whereExists(fn ($q) => $q->from('fin_stock_movements as m')->whereColumn('m.journal_id', 'j.id')->where('m.source_type', $sources[$cat]))
                ->sum(DB::raw('l.base_debit - l.base_credit'));
            $byCategory[] = ['category' => $cat, 'operational' => round($operational, 2), 'books' => round($books, 2), 'difference' => round($books - $operational, 2)];
        }

        return ['records' => $checks, 'untraced' => $untraced, 'by_category' => $byCategory,
            'ok' => $untraced === [] && collect($byCategory)->every(fn ($r) => abs($r['difference']) < 0.01)];
    }

    /** The farm record a journal came from, by the idempotency key it was posted under. */
    public function trace(int $journalId): ?array
    {
        // Only a journal that touches a farm this person can reach (§10 acceptance: no indirect reads).
        $reach = app(FarmAccess::class)->farmIdsFor();
        if ($reach !== null && ! DB::table('fin_journal_lines')->where('journal_id', $journalId)
            ->whereRaw("dimensions->>'agri_farm' IN (".implode(',', array_fill(0, max(1, count($reach)), '?')).')', array_map('strval', $reach ?: [0]))->exists()) {
            return null;
        }
        $j = DB::table('fin_journals')->where('id', $journalId)->first(['id', 'journal_no', 'journal_date', 'idempotency_key', 'source_module', 'source_ref', 'reverses_journal_id', 'description']);
        if (! $j) {
            return null;
        }
        $m = DB::table('fin_stock_movements')->where('journal_id', $journalId)->first(['id', 'source_type', 'source_id', 'idempotency_key', 'reference']);
        $key = $m->idempotency_key ?? $j->idempotency_key;
        $origin = match (true) {
            $m && $m->source_type === 'agri_activity' => ['record' => 'field activity', 'id' => $m->source_id],
            $m && $m->source_type === 'agri_work_order' => ['record' => 'work order', 'id' => $m->source_id],
            $m && $m->source_type === 'agri_feeding' => ['record' => 'feeding', 'id' => $m->source_id],
            $m && $m->source_type === 'agri_health' => ['record' => 'health record', 'id' => $m->source_id],
            $m && $m->source_type === 'agri_harvest' => ['record' => 'harvest', 'id' => $m->source_id],
            $m && $m->source_type === 'agri_sale' => ['record' => 'harvest sale', 'id' => $m->source_id],
            $m && $m->source_type === 'agri_store' => ['record' => 'store transfer or count', 'id' => $m->source_id],
            (bool) $m => ['record' => $m->source_type ?: 'stock movement', 'id' => $m->source_id],
            str_starts_with((string) $key, 'agri-txn-') => ['record' => 'farm transaction', 'uuid' => substr($key, 9)],
            str_starts_with((string) $key, 'agri-animal-sale-') => ['record' => 'animal sale', 'uuid' => substr($key, 17)],
            str_starts_with((string) $key, 'agri-count-sale-') => ['record' => 'group sale', 'uuid' => substr($key, 16)],
            str_starts_with((string) $key, 'agri-produce-sale-') => ['record' => 'production lot', 'uuid' => substr($key, 18)],
            default => ['record' => $j->source_module, 'ref' => $j->source_ref],
        };

        return ['journal' => (array) $j, 'key' => $key, 'origin' => $origin, 'reverses' => $j->reverses_journal_id];
    }

    /**
     * Send the farm's posted journals for a period to another accounting system. A journal goes once:
     * already sent or acknowledged ones are left out; failed ones go again. Returns the export with
     * its lines, each carrying the external reference the other side keys on.
     *
     * @return array{export: AgriFinanceExport, lines: list<array<string, mixed>>}
     */
    public function export(int $companyId, string $target, string $from, string $to, User $actor): array
    {
        $ids = DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.company_id', $companyId)->where('j.status', '!=', 'draft')->whereBetween('j.journal_date', [$from, $to])
            ->whereRaw("l.dimensions->>'agri_farm' IS NOT NULL")
            ->when(($reach = app(FarmAccess::class)->farmIdsFor()) !== null, fn ($q) => $q->whereIn(DB::raw("l.dimensions->>'agri_farm'"), array_map('strval', $reach ?: [0])))
            ->distinct()->pluck('j.id');
        $done = AgriExportedJournal::query()->whereIn('journal_id', $ids)->whereIn('status', ['sent', 'acknowledged'])->pluck('journal_id');
        $todo = $ids->diff($done)->values();
        if ($todo->isEmpty()) {
            throw ValidationException::withMessages(['period' => 'Every farm journal in that period has already been sent.']);
        }

        return DB::transaction(function () use ($companyId, $target, $from, $to, $actor, $todo) {
            $lines = DB::table('fin_journal_lines as l')->join('fin_journals as j', 'j.id', '=', 'l.journal_id')->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
                ->whereIn('j.id', $todo)->orderBy('j.id')->orderBy('l.id')
                ->get(['j.id as journal_id', 'j.journal_no', 'j.journal_date', 'j.idempotency_key', 'j.description', 'a.code as account', 'l.debit', 'l.credit', 'l.dimensions']);
            $export = AgriFinanceExport::create(['company_id' => $companyId, 'target' => $target, 'period_from' => $from, 'period_to' => $to,
                'journals' => $todo->count(), 'total' => round((float) $lines->sum('debit'), 2), 'status' => 'exported', 'created_by' => $actor->id]);
            foreach ($todo as $jid) {
                $first = $lines->firstWhere('journal_id', $jid);
                $ref = 'MKAGRI-'.($first?->idempotency_key ?: $first?->journal_no ?: $jid);
                $row = AgriExportedJournal::query()->firstOrNew(['company_id' => $companyId, 'journal_id' => $jid]);
                $row->fill(['export_id' => $export->id, 'external_ref' => mb_strimwidth($ref, 0, 120), 'status' => 'sent', 'error' => null,
                    'attempts' => $row->exists ? $row->attempts + 1 : 1])->save();
            }
            $refs = AgriExportedJournal::query()->whereIn('journal_id', $todo)->pluck('external_ref', 'journal_id');

            return ['export' => $export, 'lines' => $lines->map(fn ($l) => ['external_ref' => $refs[$l->journal_id], 'journal' => $l->journal_no, 'date' => $l->journal_date,
                'account' => $l->account, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'description' => $l->description,
                'dimensions' => json_decode($l->dimensions ?? '{}', true)])->all()];
        });
    }

    /**
     * The other system's answer, by external reference: accepted (with its own id) or failed (with
     * the error). Failed journals go again in the next export.
     *
     * @param  array<string, array{status: string, target_id?: ?string, error?: ?string}>  $results
     */
    public function acknowledge(AgriFinanceExport $export, array $results): AgriFinanceExport
    {
        foreach ($results as $ref => $r) {
            $row = AgriExportedJournal::query()->where('export_id', $export->id)->where('external_ref', $ref)->first();
            if (! $row) {
                continue;
            }
            $ok = ($r['status'] ?? '') === 'acknowledged';
            $row->update(['status' => $ok ? 'acknowledged' : 'failed', 'target_id' => $r['target_id'] ?? null, 'error' => $ok ? null : mb_strimwidth((string) ($r['error'] ?? 'Rejected'), 0, 500)]);
        }
        $statuses = AgriExportedJournal::query()->where('export_id', $export->id)->pluck('status');
        $export->update(['status' => $statuses->every(fn ($s) => $s === 'acknowledged') ? 'acknowledged' : ($statuses->contains('failed') ? 'partly_failed' : 'exported')]);

        return $export;
    }
}
