<?php

namespace App\Console\Commands\PerformanceAppraisal;

use App\Modules\PerformanceAppraisal\Controllers\PaReportController;
use App\Modules\PerformanceAppraisal\Models\PaScheduledReport;
use Carbon\Carbon;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendPaScheduledReportsCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'pa:send-scheduled-reports';
    protected $description = 'Generate and email due performance scheduled reports';

    public function handle(PaReportController $reports, \App\Modules\Tenancy\Services\TenantContext $tenant): int
    {
        // Tenant-safe: per-company so each report's data is RLS-scoped to that company.
        $this->eachTenant($tenant, function () use ($reports) {
            $due = PaScheduledReport::where('active', true)
                ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', Carbon::now()))
                ->get();

            foreach ($due as $scheduled) {
                try {
                    $this->process($scheduled, $reports);
                    $scheduled->update([
                        'last_run_at' => now(),
                        'next_run_at' => $scheduled->computeNextRun(),
                    ]);
                    $this->info("Sent: {$scheduled->name}");
                } catch (\Throwable $e) {
                    $this->error("Failed [{$scheduled->name}]: {$e->getMessage()}");
                }
            }
        });

        return self::SUCCESS;
    }

    private function process(PaScheduledReport $scheduled, PaReportController $reports): void
    {
        $recipients = array_filter(array_map('trim', explode(',', (string) $scheduled->recipients)));
        if (empty($recipients)) {
            return;
        }

        [$headers, $rows] = $reports->buildReportData($scheduled->report_key);

        $csv = $this->buildCsv($headers, $rows);
        $tmp = sys_get_temp_dir() . '/pa-' . $scheduled->report_key . '-' . now()->format('Y-m-d') . '.csv';
        file_put_contents($tmp, $csv);

        // Mail is best-effort (fault-tolerant channel); a failure won't abort the run.
        foreach ($recipients as $email) {
            Mail::raw(
                "Please find attached the performance report: {$scheduled->name}\n\nGenerated: " . now()->toDateTimeString(),
                function ($msg) use ($email, $scheduled, $tmp) {
                    $msg->to($email)
                        ->subject("Performance Report — {$scheduled->name} — " . now()->format('d M Y'))
                        ->attach($tmp, ['as' => basename($tmp), 'mime' => 'text/csv']);
                }
            );
        }

        @unlink($tmp);
    }

    private function buildCsv(array $headers, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, is_array($row) ? $row : [$row]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
