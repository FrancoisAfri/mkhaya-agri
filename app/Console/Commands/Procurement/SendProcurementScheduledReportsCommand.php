<?php

namespace App\Console\Commands\Procurement;

use App\Modules\Tenancy\Services\TenantContext;
use App\Shared\Exports\TabularExport;
use App\Shared\Procurement\Models\ProcurementScheduledReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Generates and emails due Contract/Supplier scheduled reports. Report data is
 * produced by each module's report service; output is PDF and/or Excel.
 */
class SendProcurementScheduledReportsCommand extends Command
{
    protected $signature = 'procurement:send-scheduled-reports';

    protected $description = 'Generate and email due contract & supplier scheduled reports';

    /** module => report-service class providing report(string $key): [headers, rows]. */
    private const SERVICES = [
        'contracts' => \App\Modules\ContractManagement\Services\ContractReportService::class,
        'suppliers' => \App\Modules\SupplierManagement\Services\SupplierReportService::class,
    ];

    public function handle(): int
    {
        $due = ProcurementScheduledReport::query()
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', Carbon::now()))
            ->get();

        if ($due->isEmpty()) {
            $this->info('No procurement scheduled reports due.');

            return self::SUCCESS;
        }

        $tenant = app(TenantContext::class);

        foreach ($due as $report) {
            try {
                // Generate + email each report INSIDE its owning company's tenant context,
                // so the report service only sees that tenant's data. Running untenanted
                // would merge every company's rows into one tenant's emailed report.
                $tenant->run(
                    $report->company_id ? (int) $report->company_id : null,
                    fn () => $this->process($report)
                );
                $report->update(['last_run_at' => now(), 'next_run_at' => $report->computeNextRun()]);
                $this->info("Sent: [{$report->module}] {$report->name}");
            } catch (\Throwable $e) {
                $this->error("Failed [{$report->name}]: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function process(ProcurementScheduledReport $report): void
    {
        $recipients = array_filter(array_map('trim', explode(',', (string) $report->recipients)));
        if (empty($recipients)) {
            return;
        }

        $serviceClass = self::SERVICES[$report->module] ?? null;
        if (! $serviceClass || ! class_exists($serviceClass)) {
            throw new \RuntimeException("No report service for module {$report->module}");
        }

        [$headers, $rows] = app($serviceClass)->report($report->report_key);
        $title = ucfirst($report->module).' Report — '.$report->name;
        $stamp = now()->format('Y-m-d');
        $base = "{$report->module}-{$report->report_key}-{$stamp}";

        $attachments = [];

        if (in_array($report->format, ['pdf', 'both'], true)) {
            $pdf = Pdf::loadView('reports.tabular', [
                'title' => $title, 'headers' => $headers, 'rows' => $rows, 'date' => now()->format('d M Y'),
            ])->setPaper('a4', 'landscape');
            $path = storage_path("app/{$base}.pdf");
            file_put_contents($path, $pdf->output());
            $attachments[] = $path;
        }

        if (in_array($report->format, ['excel', 'both'], true)) {
            $rel = "{$base}.xlsx";
            Excel::store(new TabularExport($headers, $rows), $rel, 'local');
            $attachments[] = storage_path("app/{$rel}");
        }

        foreach ($recipients as $email) {
            Mail::raw("Attached: {$report->name}\n\nGenerated ".now()->toDateTimeString(), function ($m) use ($email, $report, $attachments, $title) {
                $m->to($email)->subject("[{$title}] ".now()->format('d M Y'));
                foreach ($attachments as $a) {
                    $m->attach($a);
                }
            });
        }

        foreach ($attachments as $a) {
            @unlink($a);
        }
    }
}
