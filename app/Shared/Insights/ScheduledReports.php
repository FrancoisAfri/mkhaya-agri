<?php

namespace App\Shared\Insights;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Sending catalogue reports on a schedule: set one up, work out when it is next due, and run it —
 * as its owner, in their organisation — into a CSV or Excel file emailed to the recipients.
 */
class ScheduledReports
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function save(?ReportSchedule $s, array $data, User $owner): ReportSchedule
    {
        if (! ReportCatalog::mayRun($data['report_key'], $owner)) {
            throw ValidationException::withMessages(['report_key' => 'That report is not one you may run.']);
        }
        $recipients = collect($data['recipients'] ?? [])->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter()->unique()->values();
        if ($recipients->isEmpty() || $recipients->contains(fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL))) {
            throw ValidationException::withMessages(['recipients' => 'Give at least one valid email address.']);
        }
        $s ??= new ReportSchedule(['company_id' => $owner->company_id, 'user_id' => $owner->id]);
        $s->fill([
            'report_key' => $data['report_key'], 'title' => $data['title'] ?: ReportCatalog::get($data['report_key'])['title'],
            'params' => $data['params'] ?? null, 'period' => $data['period'] ?? 'last_month', 'format' => $data['format'] ?? 'xlsx',
            'frequency' => $data['frequency'], 'weekday' => $data['frequency'] === 'weekly' ? (int) ($data['weekday'] ?? 1) : null,
            'month_day' => $data['frequency'] === 'monthly' ? min(28, max(1, (int) ($data['month_day'] ?? 1))) : null,
            'send_at' => $data['send_at'] ?? '07:00', 'recipients' => $recipients->all(), 'active' => $data['active'] ?? true,
        ]);
        $s->next_run_at = $this->nextRun($s, now());
        $s->save();

        return $s;
    }

    /** When it is next due after $after, in the organisation's time (Africa/Johannesburg unless the app says otherwise). */
    public function nextRun(ReportSchedule $s, Carbon $after): Carbon
    {
        $tz = config('app.business_timezone', 'Africa/Johannesburg');
        [$h, $m] = array_map('intval', explode(':', substr((string) $s->send_at, 0, 5)) + [0, 0]);
        $at = $after->copy()->setTimezone($tz)->setTime($h, $m);
        $next = match ($s->frequency) {
            'daily' => $at->lte($after) ? $at->addDay() : $at,
            'weekly' => (function () use ($at, $after, $s) {
                $d = $at->copy()->startOfWeek()->addDays(((int) $s->weekday ?: 1) - 1)->setTime($at->hour, $at->minute);

                return $d->lte($after) ? $d->addWeek() : $d;
            })(),
            default => (function () use ($at, $after, $s) {
                $d = $at->copy()->startOfMonth()->addDays(((int) $s->month_day ?: 1) - 1)->setTime($at->hour, $at->minute);

                return $d->lte($after) ? $d->addMonthNoOverflow() : $d;
            })(),
        };

        return $next->setTimezone('UTC');
    }

    /** @return array{0: string, 1: string} the reporting period */
    public function period(string $period, ?Carbon $on = null): array
    {
        $on ??= today();

        return match ($period) {
            'last_7_days' => [$on->copy()->subDays(7)->toDateString(), $on->copy()->subDay()->toDateString()],
            'month_to_date' => [$on->copy()->startOfMonth()->toDateString(), $on->toDateString()],
            'year_to_date' => [$on->copy()->startOfYear()->toDateString(), $on->toDateString()],
            default => [$on->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $on->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        };
    }

    /** Send everything due. @return int how many were sent */
    public function runDue(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;
        // Across organisations: each schedule is run inside its own.
        foreach (ReportSchedule::withoutGlobalScopes()->where('active', true)->where('next_run_at', '<=', $now)->orderBy('next_run_at')->limit(200)->get() as $s) {
            $sent += $this->run($s) ? 1 : 0;
            $s->forceFill(['next_run_at' => $this->nextRun($s, $now)])->save();
        }

        return $sent;
    }

    public function run(ReportSchedule $s): bool
    {
        $owner = User::withoutGlobalScopes()->find($s->user_id);
        $previous = Auth::user();

        try {
            if (! $owner) {
                throw new \RuntimeException('The person who set up this schedule no longer exists.');
            }
            $file = $this->tenants->run((int) $s->company_id, function () use ($s, $owner) {
                Auth::setUser($owner);
                if (! ReportCatalog::mayRun($s->report_key, $owner)) {
                    throw new \RuntimeException("{$owner->email} may no longer run this report.");
                }

                return $this->render($s, $owner);
            });
            Mail::raw("{$s->title}\n\nAttached: {$file['name']} ({$file['period']}).\n\nSent on the schedule {$owner->email} set up under Insights.",
                fn ($m) => $m->to($s->recipients)->subject("{$s->title} — {$file['period']}")->attachData($file['content'], $file['name'], ['mime' => $file['mime']]));
            $s->forceFill(['last_run_at' => now(), 'last_status' => 'sent', 'last_error' => null])->save();

            return true;
        } catch (\Throwable $e) {
            report($e);
            $s->forceFill(['last_run_at' => now(), 'last_status' => 'failed', 'last_error' => mb_strimwidth($e->getMessage(), 0, 1000)])->save();

            return false;
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    /** @return array{name: string, mime: string, content: string, period: string} */
    public function render(ReportSchedule $s, User $as): array
    {
        $report = ReportCatalog::get($s->report_key) ?? throw new \RuntimeException("Report {$s->report_key} is no longer offered.");
        [$from, $to] = $this->period($s->period);
        $data = ($report['build'])($as, $from, $to, $s->params ?? []);
        $base = \Illuminate\Support\Str::slug($s->title)."-{$from}-{$to}";
        $header = array_column($data['columns'], 'title');
        $rows = array_map(fn ($row) => array_map(fn ($c) => $row[$c['key']] ?? null, $data['columns']), $data['rows']);

        if ($s->format === 'csv') {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, $header);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            rewind($out);

            return ['name' => "{$base}.csv", 'mime' => 'text/csv', 'content' => stream_get_contents($out), 'period' => "{$from} to {$to}"];
        }
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle(mb_substr(preg_replace('/[^\w ]/u', '', $s->title) ?: 'Report', 0, 30));
        $sheet->fromArray([$header], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $sheet->setCellValue('A'.(count($rows) + 3), "{$from} to {$to}. Generated ".now()->toDateTimeString().' UTC for '.$as->email.'.');
        ob_start();
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');

        return ['name' => "{$base}.xlsx", 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'content' => (string) ob_get_clean(), 'period' => "{$from} to {$to}"];
    }
}
