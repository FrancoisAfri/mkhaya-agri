<?php

namespace App\Modules\HR\Leave\Jobs;

use App\Modules\HR\Leave\Exports\LeaveReportExport;
use App\Modules\HR\Leave\Enums\LeaveAnomalyType;
use App\Modules\HR\Leave\Models\LeaveAnomalyFlag;
use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Core\User\Models\User;
use App\Modules\HR\Leave\Notifications\LeaveReportReadyNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class GenerateLeaveReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly string  $reportKey,
        private readonly ?string $recipientEmail = null,
    ) {}

    public function handle(): void
    {
        [$headers, $rows] = $this->buildData();

        $filename  = "leave-{$this->reportKey}-" . now()->format('Y-m-d-His') . '.xlsx';
        $storagePath = "reports/{$filename}";

        $export = new LeaveReportExport($headers, $rows);
        $stored = Excel::store($export, $storagePath, 'local');

        if ($this->recipientEmail && $stored) {
            $path = Storage::path($storagePath);
            Mail::raw(
                "Your {$this->reportKey} leave report is ready. Please find it attached.",
                function ($msg) use ($path, $filename) {
                    $msg->to($this->recipientEmail)
                        ->subject("Leave Report Ready: {$this->reportKey}")
                        ->attach($path, ['as' => $filename]);
                }
            );

            $user = User::where('email', $this->recipientEmail)->first();
            $user?->notify(new LeaveReportReadyNotification($this->reportKey, $filename));
        }
    }

    private function buildData(): array
    {
        return match ($this->reportKey) {
            'taken' => [
                ['Employee', 'Leave Type', 'Start Date', 'End Date', 'Working Days', 'Status'],
                LeaveRequest::with(['employee', 'leaveType'])
                    ->where('leave_requests.status', 'approved')
                    ->latest('leave_requests.start_date')
                    ->get()
                    ->map(fn($r) => [
                        trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->surname ?? '')),
                        $r->leaveType?->name ?? '',
                        $r->start_date?->format('d M Y'),
                        $r->end_date?->format('d M Y'),
                        $r->working_days,
                        $r->status?->value ?? $r->status,
                    ])->toArray(),
            ],
            'anomaly' => [
                ['Employee', 'Anomaly Type', 'Severity', 'Detected At', 'Status'],
                LeaveAnomalyFlag::with('employee')->get()->map(fn($f) => [
                    trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')),
                    $f->anomaly_type instanceof LeaveAnomalyType ? $f->anomaly_type->label() : (string) $f->anomaly_type,
                    $f->severity ?? '',
                    $f->detected_at?->format('d M Y'),
                    $f->false_positive ? 'Resolved' : 'Open',
                ])->toArray(),
            ],
            'burnout' => [
                ['Employee', 'Risk Score', 'Leave Frequency', 'Days Used', 'Sick Days', 'Flagged At', 'Reviewed'],
                LeaveAnomalyFlag::with('employee')
                    ->where('anomaly_type', LeaveAnomalyType::BurnoutRisk->value)
                    ->get()
                    ->map(fn($f) => [
                        trim(($f->employee?->first_name ?? '') . ' ' . ($f->employee?->surname ?? '')),
                        $f->confidence_score ?? '',
                        data_get($f->metadata, 'leave_frequency', ''),
                        data_get($f->metadata, 'days_used', ''),
                        data_get($f->metadata, 'sick_days', ''),
                        $f->detected_at?->format('d M Y'),
                        $f->reviewed_by ? 'Yes' : 'No',
                    ])->toArray(),
            ],
            'balance' => [
                ['Employee', 'Leave Type', 'Balance (h)', 'Balance (d)'],
                DB::table('leave_balance_transactions as lbt')
                    ->join('hr_people as hp', 'hp.id', '=', 'lbt.employee_id')
                    ->join('leave_types as lt', 'lt.id', '=', 'lbt.leave_type_id')
                    ->select('lbt.employee_id', DB::raw("TRIM(CONCAT(hp.first_name,' ',hp.surname)) as name"), 'lt.name as lt_name', DB::raw('MAX(lbt.id) as latest_tx_id'))
                    ->groupBy('lbt.employee_id', 'hp.first_name', 'hp.surname', 'lt.name')
                    ->get()
                    ->map(function ($row) {
                        $bal = DB::table('leave_balance_transactions')->where('id', $row->latest_tx_id)->value('balance_after') ?? 0;
                        return [$row->name, $row->lt_name, round((float)$bal, 2), round((float)$bal / 8, 1)];
                    })->toArray(),
            ],
            default => [['Report', 'Key'], [[$this->reportKey, 'No data builder defined']]],
        };
    }
}
