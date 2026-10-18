<?php

namespace App\Console\Commands\Payroll;

use App\Mail\PolicyReportMail;
use App\Modules\HR\Payroll\Jobs\GeneratePayrollReportJob;
use App\Modules\HR\Payroll\Models\PayrollScheduledReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendPayrollScheduledReports extends Command
{
    use RunsPerTenant;

    protected $signature   = 'payroll:send-scheduled-reports';
    protected $description = 'Generate and email due Payroll scheduled reports';

    public function handle(): int
    {
        $this->eachTenant(app(TenantContext::class), function () {
            $due = PayrollScheduledReport::where('active', true)
                ->where(fn($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', Carbon::now()))
                ->get();

            if ($due->isEmpty()) {
                $this->info('No payroll reports due.');
                return;
            }

            $job = new GeneratePayrollReportJob('payslips');

            foreach ($due as $scheduled) {
                try {
                    $this->processReport($scheduled, $job);
                    $this->info("Sent: {$scheduled->name}");
                } catch (\Throwable $e) {
                    $this->error("Failed [{$scheduled->name}]: {$e->getMessage()}");
                }
            }
        });

        return self::SUCCESS;
    }

    private function processReport(PayrollScheduledReport $scheduled, GeneratePayrollReportJob $jobTemplate): void
    {
        $job     = new GeneratePayrollReportJob($scheduled->report_key);
        [$headers, $rows] = $job->buildData();

        $title = 'Payroll — ' . ucwords(str_replace('_', ' ', $scheduled->report_key));
        $slug  = 'payroll-' . str_replace('_', '-', $scheduled->report_key);
        $date  = now()->format('Y-m-d');

        $attachments = [];
        $tmpFiles    = [];

        if (in_array($scheduled->format, ['pdf', 'both'], true)) {
            $path          = $this->buildPdf($title, $headers, $rows, $slug, $date);
            $attachments[] = ['path' => $path, 'name' => "{$slug}-{$date}.pdf"];
            $tmpFiles[]    = $path;
        }

        if (in_array($scheduled->format, ['excel', 'both'], true)) {
            $path          = $this->buildCsv($headers, $rows, $slug, $date);
            $attachments[] = ['path' => $path, 'name' => "{$slug}-{$date}.csv"];
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
        $thHtml   = implode('', array_map(fn($h) => '<th>' . e($h) . '</th>', $headers));
        $rowsHtml = '';

        foreach ($rows as $row) {
            $row      = (array) $row;
            $vals     = array_values($row);
            $rowsHtml .= '<tr>' . implode('', array_map(fn($v) => '<td>' . e((string) $v) . '</td>', $vals)) . '</tr>';
        }

        $html = "<html><head><style>
            body{font-family:Arial,sans-serif;font-size:9px;}
            h2{margin:0 0 4px;color:#1A237E;}p{margin:0 0 8px;color:#666;}
            table{width:100%;border-collapse:collapse;}
            th,td{border:1px solid #ccc;padding:4px 5px;text-align:left;}
            th{background:#E8EAF6;font-weight:bold;}
            tr:nth-child(even){background:#f9f9f9;}
        </style></head><body>
        <h2>{$title}</h2><p>Generated {$date}</p>
        <table><thead><tr>{$thHtml}</tr></thead><tbody>{$rowsHtml}</tbody></table>
        </body></html>";

        $path = sys_get_temp_dir() . "/{$slug}-{$date}.pdf";
        file_put_contents($path, Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output());
        return $path;
    }

    private function buildCsv(array $headers, array $rows, string $slug, string $date): string
    {
        $path = sys_get_temp_dir() . "/{$slug}-{$date}.csv";
        $fp   = fopen($path, 'w');
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, array_values((array) $row));
        }
        fclose($fp);
        return $path;
    }
}
