<?php

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Services\TickService;
use Illuminate\Console\Command;

/**
 * Every minute: due timers (waits, SLA warnings and breaches, reminders), action retries, scheduled
 * workflows and outbox delivery (PRS §19). Inline — production has no queue worker — and safe to
 * overlap, though the schedule prevents it.
 */
class WorkflowTickCommand extends Command
{
    protected $signature = 'workflow:tick {--company= : Only this company} {--sync : Accepted for parity with other module commands; the tick always runs inline}';

    protected $description = 'Run due workflow timers, retries, scheduled starts and event delivery';

    public function handle(TickService $tick): int
    {
        $stats = $tick->run($this->option('company') ? (int) $this->option('company') : null);
        $this->line(sprintf('[%s] workflow tick: %d timer(s), %d action retry(ies), %d scheduled start(s), %d event(s) delivered, %d error(s)',
            now()->toDateTimeString(), $stats['timers'], $stats['actions'], $stats['scheduled'], $stats['events'], $stats['errors']));

        return $stats['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
