<?php

namespace App\Console\Commands\Fleet;

use App\Modules\Fleet\Services\FleetAnomalyService;
use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;

class FleetWeeklyAnomalyDigestCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'fleet:anomaly-digest';
    protected $description = 'FR-4.3.6 — Send weekly fuel & spend anomaly digest to fleet managers.';

    public function handle(FleetAnomalyService $service): int
    {
        $this->eachTenant(app(TenantContext::class), function () use ($service) {
            $this->info('Building fleet anomaly digest…');

            $data     = $service->weeklyDigestData();
            $managers = User::role(['fleet_manager', 'administrator'])->get();

            if ($managers->isEmpty()) {
                $this->warn('No fleet managers found — skipping digest.');
                return;
            }

            foreach ($managers as $manager) {
                $this->sendDigestEmail($manager, $data);
            }

            $this->info("Digest sent to {$managers->count()} manager(s).");
        });

        return Command::SUCCESS;
    }

    private function sendDigestEmail(User $manager, array $data): void
    {
        $name = $this->resolveManagerName($manager);

        $rows = collect($data['by_type'])->map(fn($count, $type) =>
            "  • " . str_replace('_', ' ', ucfirst($type)) . ": {$count}"
        )->implode("\n");

        $topVehicles = collect($data['top_vehicles'])->map(fn($v) =>
            "  • {$v['vehicle']}: {$v['count']} anomaly(ies)"
        )->implode("\n");

        $body = <<<TEXT
        Hello {$name},

        Fleet Fuel & Spend Anomaly — Weekly Digest
        Week ending: {$this->weekLabel()}

        SUMMARY
        ───────
        Total anomalies flagged : {$data['total']}
        Unreviewed              : {$data['unreviewed']}

        BY TYPE
        ───────
        {$rows}

        TOP VEHICLES
        ────────────
        {$topVehicles}

        Please log in to review and resolve open anomalies.

        — Mkhaya Fleet AI
        TEXT;

        Mail::raw($body, function (Message $msg) use ($manager, $data) {
            $msg->to($manager->email)
                ->subject("Fleet Anomaly Digest — {$this->weekLabel()} ({$data['total']} flagged)");
        });
    }

    private function resolveManagerName(User $manager): string
    {
        $p = $manager->person;
        return trim(($p?->first_name ?? '') . ' ' . ($p?->surname ?? '')) ?: $manager->email;
    }

    private function weekLabel(): string
    {
        return now()->startOfWeek()->format('d M') . ' – ' . now()->endOfWeek()->format('d M Y');
    }
}
