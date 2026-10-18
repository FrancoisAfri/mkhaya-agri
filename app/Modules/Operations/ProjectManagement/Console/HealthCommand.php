<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Modules\Operations\ProjectManagement\Models\EvidenceDeadLetter;
use App\Modules\Operations\ProjectManagement\Models\OutboxMessage;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\Webhook;
use App\Modules\Operations\ProjectManagement\Services\EvidenceLedger;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Is Project Management actually working? (EPIC-080)
 *
 * Every check here corresponds to a way the module fails *quietly* — where nothing throws, no page
 * errors, and the damage is only visible weeks later when somebody queries a number. A nightly cron
 * that emails on a non-zero exit is the cheapest possible insurance against all of them.
 *
 * Exit codes: 0 healthy, 1 something needs attention. `--json` for a monitoring agent.
 */
class HealthCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'pm:health
                            {--company= : Only this company id}
                            {--json : Emit machine-readable output}
                            {--verify-chains : Also re-hash every evidence chain (slow; for a restore drill)}';

    protected $description = 'Check the Project Management invariants that fail silently';

    /** Warn above this many undelivered outbox messages: a queue this deep is not draining. */
    private const OUTBOX_BACKLOG = 500;

    /** Dead letters older than this were never looked at, which defeats the point of holding them. */
    private const DEAD_LETTER_AGE_DAYS = 7;

    public function handle(TenantContext $tenants, EvidenceLedger $ledger): int
    {
        $checks = [];

        $this->option('company')
            ? $tenants->run((int) $this->option('company'), function () use (&$checks, $ledger) {
                $checks = array_merge($checks, $this->checkTenant($ledger));
            })
            : $this->eachTenant($tenants, function () use (&$checks, $ledger) {
                $checks = array_merge($checks, $this->checkTenant($ledger));
            });

        // Partitions are global reference maintenance, not per-tenant.
        $checks[] = $this->checkPartitions();

        $failed = array_values(array_filter($checks, fn (array $c) => ! $c['ok']));

        if ($this->option('json')) {
            $this->line(json_encode([
                'healthy' => $failed === [],
                'checks'  => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $check) {
            $check['ok']
                ? $this->line("  <fg=green>✓</> {$check['name']}: {$check['detail']}")
                : $this->line("  <fg=red>✗</> {$check['name']}: {$check['detail']}");
        }

        if ($failed === []) {
            $this->info('Project Management is healthy.');

            return self::SUCCESS;
        }

        $this->error(count($failed) . ' check(s) need attention.');

        return self::FAILURE;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<array{name:string, ok:bool, detail:string}> */
    private function checkTenant(EvidenceLedger $ledger): array
    {
        $company = \App\Modules\Tenancy\Models\Company::find(app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id());
        $label = $company?->name ?? 'tenant';

        return array_filter([
            $this->checkOutbox($label),
            $this->checkDeadLetters($label),
            $this->checkWebhooks($label),
            $this->checkScheduleFreshness($label),
            $this->option('verify-chains') ? $this->checkChains($ledger, $label) : null,
        ]);
    }

    /** @return array{name:string, ok:bool, detail:string} */
    private function checkOutbox(string $label): array
    {
        $pending = OutboxMessage::pending()->count();
        $dead = OutboxMessage::whereNotNull('dead_at')->count();

        return [
            'name'   => "{$label}: outbox",
            'ok'     => $pending < self::OUTBOX_BACKLOG && $dead === 0,
            'detail' => $dead > 0
                ? "{$dead} message(s) gave up entirely — an integration has been missing events."
                : ($pending >= self::OUTBOX_BACKLOG
                    ? "{$pending} messages pending; the drain is not keeping up."
                    : "{$pending} pending, none dead."),
        ];
    }

    /**
     * Dead letters are the module being honest about what it refused. Old ones mean nobody is
     * reading that honesty, and a device somewhere has been failing to sync for a week.
     */
    private function checkDeadLetters(string $label): array
    {
        // `replayed_at` is how a rejected record is dealt with — it was re-submitted and accepted.
        // Counting those as outstanding would make this check permanently red on any tenant that
        // ever had a bad sync, and a permanently red check is a muted one.
        $stale = EvidenceDeadLetter::where('created_at', '<', now()->subDays(self::DEAD_LETTER_AGE_DAYS))
            ->whereNull('replayed_at')
            ->count();

        return [
            'name'   => "{$label}: rejected evidence",
            'ok'     => $stale === 0,
            'detail' => $stale === 0
                ? 'Nothing rejected and forgotten.'
                : "{$stale} record(s) rejected more than " . self::DEAD_LETTER_AGE_DAYS
                    . ' days ago and never resolved — somebody\'s work is not arriving.',
        ];
    }

    private function checkWebhooks(string $label): array
    {
        $disabled = Webhook::whereNotNull('disabled_at')->count();
        $total = Webhook::count();

        return [
            'name'   => "{$label}: webhooks",
            'ok'     => $disabled === 0,
            'detail' => $total === 0
                ? 'None configured.'
                : ($disabled === 0
                    ? "{$total} active."
                    : "{$disabled} of {$total} switched off after repeated failures."),
        ];
    }

    /**
     * A schedule nobody has recalculated since work started is a schedule people are reading and
     * believing. Nothing errors; the dates are just quietly stale.
     */
    private function checkScheduleFreshness(string $label): array
    {
        $stale = Project::query()
            ->whereIn('status', ['active'])
            ->whereHas('tasks', fn ($q) => $q->whereNull('scheduled_at'))
            ->count();

        return [
            'name'   => "{$label}: schedules",
            'ok'     => true,   // informational: an unscheduled project is a choice, not a fault
            'detail' => $stale === 0
                ? 'Every active project has been scheduled.'
                : "{$stale} active project(s) contain tasks that have never been scheduled.",
        ];
    }

    /**
     * Re-hash every chain. Slow, and deliberately opt-in: this is the check to run after restoring
     * a backup, when the question is whether what came back is what went in.
     */
    private function checkChains(EvidenceLedger $ledger, string $label): array
    {
        $broken = [];
        $checked = 0;

        foreach (Project::pluck('id') as $projectId) {
            $result = $ledger->verifyChain(
                app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id(),
                $projectId
            );

            $checked += $result->eventsChecked;

            if (! $result->intact) {
                $broken[] = $projectId;
            }
        }

        return [
            'name'   => "{$label}: evidence chains",
            'ok'     => $broken === [],
            'detail' => $broken === []
                ? "{$checked} event(s) verified; every chain intact."
                : 'Chain broken on project id(s) ' . implode(', ', $broken) . ' — the ledger has been altered.',
        ];
    }

    /**
     * The ledger is range-partitioned by month. Running out of partitions does not degrade
     * gracefully — the next insert fails outright, and it fails on the sync path, in the field.
     */
    private function checkPartitions(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return ['name' => 'partitions', 'ok' => true, 'detail' => 'Not PostgreSQL; nothing to check.'];
        }

        // Read the partitions' real range bounds rather than guessing their names. A check that
        // encodes a naming convention breaks the day the convention changes, and it breaks by
        // crying wolf — worse than not checking, because the alarm then gets muted.
        //
        // The bound expression is parsed in PHP, not SQL: a regex that has to survive PHP, a
        // heredoc and Postgres all escaping it is a regex that will be wrong.
        $bounds = DB::select(<<<'SQL'
            SELECT pg_get_expr(c.relpartbound, c.oid) AS bound
            FROM pg_class c
            JOIN pg_inherits i ON i.inhrelid = c.oid
            JOIN pg_class p ON p.oid = i.inhparent
            WHERE p.relname = 'pm_evidence_events'
        SQL);

        $coveredTo = null;

        foreach ($bounds as $row) {
            // FOR VALUES FROM ('2026-09-01 00:00:00') TO ('2026-10-01 00:00:00')
            if (preg_match_all("/'([0-9]{4}-[0-9]{2}-[0-9]{2})/", (string) $row->bound, $m)) {
                $upper = end($m[1]);

                if ($coveredTo === null || $upper > $coveredTo) {
                    $coveredTo = $upper;
                }
            }
        }

        if ($coveredTo === null) {
            return [
                'name'   => 'evidence partitions',
                'ok'     => false,
                'detail' => 'The evidence table has no partitions at all. Every write will fail — run pm:evidence-partitions.',
            ];
        }

        $months = (int) now()->startOfMonth()->diffInMonths(\Carbon\CarbonImmutable::parse($coveredTo));

        return [
            'name'   => 'evidence partitions',
            'ok'     => $months >= 2,
            'detail' => $months >= 2
                ? "Partitioned to {$coveredTo} ({$months} month(s) ahead)."
                // Running out does not degrade gracefully: the next insert fails outright, on the
                // sync path, in the field.
                : "Partitions only reach {$coveredTo}. Evidence writes will start failing — run pm:evidence-partitions.",
        ];
    }
}
