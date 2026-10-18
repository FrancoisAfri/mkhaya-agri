<?php

namespace App\Console\Commands\Disciplinary;

use App\Mail\PolicyReportMail;
use App\Modules\Disciplinary\Controllers\DisciplinaryReportController;
use App\Modules\Disciplinary\Exports\DisciplinaryReportExport;
use App\Modules\Disciplinary\Models\DisciplinaryScheduledReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

class SendDisciplinaryScheduledReports extends Command
{
    use RunsPerTenant;

    protected $signature   = 'disciplinary:send-scheduled-reports';
    protected $description = 'Generate and email due disciplinary scheduled reports';

    public function handle(): int
    {
        $this->eachTenant(app(TenantContext::class), function () {
            $due = DisciplinaryScheduledReport::where('active', true)
                ->where(fn($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', Carbon::now()))
                ->get();

            if ($due->isEmpty()) {
                $this->info('No disciplinary reports due.');
                return;
            }

            $controller = app(DisciplinaryReportController::class);

            foreach ($due as $scheduled) {
                try {
                    $this->processReport($scheduled, $controller);
                    $this->info("Sent: {$scheduled->name}");
                } catch (\Throwable $e) {
                    $this->error("Failed [{$scheduled->name}]: {$e->getMessage()}");
                }
            }
        });

        return self::SUCCESS;
    }

    private function processReport(DisciplinaryScheduledReport $scheduled, DisciplinaryReportController $controller): void
    {
        [$title, $headers, $rows] = $controller->buildReportData($scheduled->report_key);

        $slug  = 'disciplinary-' . str_replace('_', '-', $scheduled->report_key);
        $date  = now()->format('Y-m-d');

        $attachments = [];
        $tmpFiles    = [];

        if (in_array($scheduled->format, ['pdf', 'both'], true)) {
            $path = $this->buildPdf($title, $headers, $rows, $slug, $date);
            $attachments[] = ['path' => $path, 'name' => "{$slug}-{$date}.pdf"];
            $tmpFiles[]    = $path;
        }

        if (in_array($scheduled->format, ['excel', 'both'], true)) {
            $path = $this->buildExcel($title, $headers, $rows, $slug, $date);
            $attachments[] = ['path' => $path, 'name' => "{$slug}-{$date}.xlsx"];
            $tmpFiles[]    = $path;
        }

        $recipients = array_filter(
            array_map('trim', explode(',', $scheduled->recipients)),
            fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
        );

        foreach ($recipients as $email) {
            Mail::to($email)->send(new PolicyReportMail(
                reportName:  $scheduled->name,
                reportTitle: $title,
                cadence:     $scheduled->cadence,
                attachments: $attachments,
            ));
        }

        $scheduled->update([
            'last_run_at' => now(),
            'next_run_at' => $scheduled->computeNextRun(),
        ]);

        foreach ($tmpFiles as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    private function buildPdf(string $title, array $headers, array $rows, string $slug, string $date): string
    {
        $path = sys_get_temp_dir() . "/{$slug}-{$date}.pdf";

        $pdf = Pdf::loadView('reports.disciplinary-report', [
            'title'   => $title,
            'headers' => $headers,
            'rows'    => $rows,
            'date'    => now()->format('d M Y H:i'),
        ])->setPaper('a4', 'landscape');

        file_put_contents($path, $pdf->output());
        return $path;
    }

    private function buildExcel(string $title, array $headers, array $rows, string $slug, string $date): string
    {
        $path    = sys_get_temp_dir() . "/{$slug}-{$date}.xlsx";
        $content = Excel::raw(
            new DisciplinaryReportExport($title, $headers, $rows),
            \Maatwebsite\Excel\Excel::XLSX,
        );
        file_put_contents($path, $content);
        return $path;
    }
}
