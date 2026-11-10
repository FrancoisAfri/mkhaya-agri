<?php

namespace App\Modules\BI\Console;

use App\Modules\BI\Services\BIScheduler;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Runs every minute; sends each BI dashboard schedule at its own time (Africa/Johannesburg). */
class RunBISchedules extends Command
{
    protected $signature = 'bi:schedules {--id= : Send one schedule now, whatever its time}';

    protected $description = 'Email scheduled BI dashboards';

    public function handle(TenantContext $tenants, BIScheduler $scheduler): int
    {
        $now = CarbonImmutable::now('Africa/Johannesburg');
        // Read across companies (no tenant set), then send each inside its own company.
        $schedules = $tenants->run(null, fn () => DB::table('bi_schedules')->where('is_active', true)
            ->when($this->option('id'), fn ($q, $id) => $q->where('id', (int) $id))->get());

        foreach ($schedules as $s) {
            if (! $this->option('id') && ! BIScheduler::due($s, $now)) {
                continue;
            }
            $r = $tenants->run((int) $s->company_id, fn () => $scheduler->send($s, $now));
            $this->line("Schedule {$s->id} ({$s->name}): " . ($r['ok'] ? "sent {$r['file']} to {$r['recipients']}" : "failed — {$r['error']}"));
        }

        return self::SUCCESS;
    }
}
