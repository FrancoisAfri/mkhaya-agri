<?php

namespace App\Console\Commands\Attendance;

use App\Mail\PolicyReportMail;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAbsenceReportRecipient;
use App\Modules\HR\Hr\Models\Attendance\AttendanceAbsentUser;
use App\Modules\HR\Hr\Models\Attendance\AttendanceSetting;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\Carbon;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email the day's absentee list to the configured recipients — the equivalent of
 * the legacy intranet's daily "absent users to managers" report.
 *
 * Each row shows whether the absence is already covered by leave, so a manager
 * can see at a glance who is unaccounted for.
 */
class SendAbsentUsersReport extends Command
{
    use RunsPerTenant;

    protected $signature = 'attendance:send-absent-report
                            {--date= : Report on this date (Y-m-d) instead of today}
                            {--company= : Process only this company}
                            {--sync : Process companies inline instead of fanning out to the queue}';

    protected $description = 'Email the daily absent-users report to the configured recipients';

    public function handle(TenantContext $tenant): int
    {
        $date = $this->option('date');

        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->runForCompany($date));
            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->runForCompany($date));
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('attendance:send-absent-report', $date ? ['--date' => $date] : []);
        $this->info("Dispatched absent-users report to {$n} company(ies).");
        return self::SUCCESS;
    }

    private function runForCompany(?string $date): void
    {
        $settings = AttendanceSetting::current();

        if (! $settings->absent_manager_report_enabled) {
            return;
        }

        $reportDate = $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString();

        $recipients = AttendanceAbsenceReportRecipient::active()->with('employee')->get()
            ->map(fn ($r) => ['email' => $r->resolvedEmail(), 'name' => $r->resolvedName()])
            ->filter(fn ($r) => ! empty($r['email']))
            ->values();

        if ($recipients->isEmpty()) {
            $this->line('No active absent-report recipients configured.');
            return;
        }

        $rows = $this->buildRows($reportDate);

        if (empty($rows)) {
            $this->line("No absences recorded for {$reportDate}; nothing sent.");
            return;
        }

        $path = $this->buildCsv($rows, $reportDate);

        try {
            foreach ($recipients as $recipient) {
                try {
                    Mail::to($recipient['email'])->send(new PolicyReportMail(
                        'Absent Users Report',
                        'Absent Users — ' . $reportDate,
                        'daily',
                        [['path' => $path, 'name' => "absent-users-{$reportDate}.csv"]]
                    ));
                } catch (\Throwable $e) {
                    Log::error("attendance:send-absent-report failed for {$recipient['email']}: " . $e->getMessage());
                }
            }
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->info('Absent-users report sent to ' . $recipients->count() . ' recipient(s), ' . count($rows) . ' absentee(s).');
    }

    /** @return array<int, array<string, mixed>> */
    private function buildRows(string $reportDate): array
    {
        $absences = AttendanceAbsentUser::where('date', $reportDate)->get();

        if ($absences->isEmpty()) {
            return [];
        }

        $people = HRPerson::whereIn('id', $absences->pluck('employee_id')->unique())
            ->get()
            ->keyBy('id');

        return $absences->map(function ($absence) use ($people) {
            $person = $people->get($absence->employee_id);

            return [
                'Employee Number' => $person?->employee_number,
                'Name'            => $person ? trim($person->first_name . ' ' . $person->surname) : ('#' . $absence->employee_id),
                'Email'           => $person?->email,
                'Date'            => $absence->date,
                'On Leave'        => $absence->is_applied ? 'Yes' : 'No',
                'Leave Deducted'  => $absence->is_deducted ? 'Yes' : 'No',
                'Source'          => $absence->source,
            ];
        })->values()->all();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function buildCsv(array $rows, string $reportDate): string
    {
        $path = storage_path("app/absent-users-{$reportDate}-" . uniqid() . '.csv');
        $out = fopen($path, 'w');

        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, array_map([$this, 'sanitise'], array_values($row)));
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

        return $value;
    }
}
