<?php

namespace App\Console\Commands\Payroll;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Walk the payroll audit chain and report the first place it breaks.
 *
 * The triggers refuse UPDATE and DELETE, which stops anybody working through the application or an
 * ordinary SQL client. They cannot stop somebody with rights to drop the trigger — nothing at that
 * layer can. What the hash chain adds is that doing so is *detectable*: every row's hash covers the
 * previous row's, so altering or removing one breaks every hash after it.
 *
 * That only helps if somebody looks, which is what this command is for. A chain nobody verifies is
 * decoration.
 */
class VerifyPayrollAudit extends Command
{
    protected $signature = 'payroll:verify-audit
        {--company= : Verify one company only}
        {--limit=0 : Stop after N rows per company (0 = all)}';

    protected $description = 'Verify the payroll audit trail has not been altered';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('The audit chain is enforced in Postgres. Nothing to verify on this driver.');

            return self::SUCCESS;
        }

        $companies = DB::table('payroll_audit_logs')
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->select('company_id')
            ->distinct()
            ->pluck('company_id');

        if ($companies->isEmpty()) {
            $this->info('No payroll audit entries to verify.');

            return self::SUCCESS;
        }

        $broken = 0;
        $checked = 0;
        $unchained = 0;

        foreach ($companies as $companyId) {
            [$rows, $firstBreak, $skipped] = $this->verifyCompany($companyId);

            $checked += $rows;
            $unchained += $skipped;

            if ($firstBreak) {
                $broken++;
                $this->error(sprintf(
                    'Company %s: the chain breaks at entry #%d (%s, %s). Everything from there on is unverifiable.',
                    $companyId ?? 'none',
                    $firstBreak['id'],
                    $firstBreak['action'],
                    $firstBreak['created_at'] ?? 'undated',
                ));
            }
        }

        // Rows written before the chain existed have no hash. They are not evidence of tampering —
        // they are simply older than the guarantee — and saying so plainly stops somebody reading
        // the count as a breach.
        if ($unchained > 0) {
            $this->line("{$unchained} entr(ies) predate the hash chain and were skipped.");
        }

        if ($broken > 0) {
            $this->error("{$broken} compan(ies) have an altered audit trail.");

            return self::FAILURE;
        }

        $this->info("Payroll audit trail verified — {$checked} chained entr(ies) across {$companies->count()} compan(ies), all intact.");

        return self::SUCCESS;
    }

    /**
     * @return array{0:int, 1:?array, 2:int}  rows checked, the first broken row, rows with no hash
     */
    private function verifyCompany(?int $companyId): array
    {
        $limit = (int) $this->option('limit');
        $previous = null;
        $checked = 0;
        $skipped = 0;

        $query = DB::table('payroll_audit_logs')
            ->when(
                $companyId === null,
                fn ($q) => $q->whereNull('company_id'),
                fn ($q) => $q->where('company_id', $companyId),
            )
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $row) {
            if (blank($row->hash)) {
                $skipped++;

                continue;
            }

            // Recomputed exactly as the trigger builds it. Any difference in a field, or a row
            // removed from between two others, changes this and every hash after it.
            $canonical = implode('|', [
                (string) ($previous ?? ''),
                $row->company_id === null ? '' : (string) $row->company_id,
                // Formatted, never converted. `strtotime` read the stored value in PHP's
                // timezone and `gmdate` rendered it in UTC, which shifted it by the offset — a
                // second, independent copy of the fault the trigger had. The column holds a naked
                // timestamp that is already UTC; anything that moves it makes the verifier
                // disagree with the writer and report tampering on a row nobody touched.
                $row->created_at ? Carbon::parse($row->created_at)->format('Y-m-d\TH:i:s\Z') : '',
                $row->user_id === null ? '' : (string) $row->user_id,
                (string) $row->action,
                (string) ($row->subject_type ?? ''),
                $row->subject_id === null ? '' : (string) $row->subject_id,
                (string) ($row->description ?? ''),
                (string) ($row->old_values ?? ''),
                (string) ($row->new_values ?? ''),
            ]);

            if (hash('sha256', $canonical) !== $row->hash) {
                return [$checked, (array) $row, $skipped];
            }

            $previous = $row->hash;
            $checked++;
        }

        return [$checked, null, $skipped];
    }
}
