<?php

namespace App\Console\Commands\Insights;

use App\Shared\Insights\ScheduledReports;
use Illuminate\Console\Command;

/** Email every Insights report schedule that is due. */
class SendScheduledReports extends Command
{
    protected $signature = 'insights:send-scheduled';

    protected $description = 'Send the Insights report schedules that are due';

    public function handle(ScheduledReports $reports): int
    {
        $this->info($reports->runDue().' report(s) sent.');

        return self::SUCCESS;
    }
}
