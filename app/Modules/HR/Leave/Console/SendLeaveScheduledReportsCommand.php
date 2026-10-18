<?php

namespace App\Modules\HR\Leave\Console;

use App\Mail\PolicyReportMail;
use App\Modules\HR\Hr\Controllers\LeaveManagementController;
use App\Modules\HR\Leave\Models\LeaveScheduledReport;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\Carbon;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Generate and email due leave scheduled reports.
 *
 * The LeaveScheduledReport model and its setup screen already existed, but
 * nothing ever ran them — leave reports could be configured and scheduled and
 * would silently never arrive. Payroll's equivalent dispatcher is the model
 * this follows; report definitions are reused from LeaveManagementController so
 * the scheduled output matches what the on-screen report shows.
 */
class SendLeaveScheduledReportsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'leave:send-scheduled-reports
                            {--company= : Process only this company}
                            {--sync : Process companies inline instead of fanning out to the queue}';

    protected $description = 'Generate and email due Leave scheduled reports';

    public function handle(TenantContext $tenant): int
    {
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->runForCompany());
            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->runForCompany());
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('leave:send-scheduled-reports', []);
        $this->info("Dispatched leave scheduled reports to {$n} company(ies).");
        return self::SUCCESS;
    }

    private function runForCompany(): void
    {
        $due = LeaveScheduledReport::where('active', true)
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', Carbon::now()))
            ->get();

        if ($due->isEmpty()) {
            return;
        }

        foreach ($due as $scheduled) {
            try {
                $this->send($scheduled);
                $this->info("Sent: {$scheduled->name}");
            } catch (\Throwable $e) {
                Log::error("leave:send-scheduled-reports failed [{$scheduled->name}]: " . $e->getMessage());
                $this->error("Failed [{$scheduled->name}]: {$e->getMessage()}");
            }
        }
    }

    private function send(LeaveScheduledReport $scheduled): void
    {
        [$headers, $rows] = app(LeaveManagementController::class)->buildReportData($scheduled->report_key);

        $slug = 'leave-' . str_replace('_', '-', $scheduled->report_key);
        $date = now()->format('Y-m-d');
        $path = $this->buildCsv($headers, $rows, $slug, $date);

        $recipients = array_filter(array_map('trim', explode(',', (string) $scheduled->recipients)));

        try {
            foreach ($recipients as $email) {
                Mail::to($email)->send(new PolicyReportMail(
                    $scheduled->name,
                    'Leave — ' . ucwords(str_replace('_', ' ', $scheduled->report_key)),
                    $scheduled->cadence,
                    [['path' => $path, 'name' => "{$slug}-{$date}.csv"]]
                ));
            }
        } finally {
            // The attachment is a temp file; remove it whether or not sending worked.
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $scheduled->forceFill([
            'last_run_at' => now(),
            'next_run_at' => $scheduled->computeNextRun(),
        ])->save();
    }

    /** @param array<int,string> $headers */
    private function buildCsv(array $headers, iterable $rows, string $slug, string $date): string
    {
        $path = storage_path("app/{$slug}-{$date}-" . uniqid() . '.csv');
        $out = fopen($path, 'w');

        fputcsv($out, $headers);
        foreach ($rows as $row) {
            $values = is_array($row) ? array_values($row) : array_values((array) $row);
            fputcsv($out, array_map([$this, 'sanitise'], $values));
        }

        fclose($out);

        return $path;
    }

    /** A leading =, +, - or @ makes spreadsheet software treat the cell as a formula. */
    private function sanitise($value)
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return is_scalar($value) || $value === null ? $value : json_encode($value);
    }
}
