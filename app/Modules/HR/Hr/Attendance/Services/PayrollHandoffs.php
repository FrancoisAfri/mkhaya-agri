<?php

namespace App\Modules\HR\Hr\Attendance\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Attendance\Models\Timesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use App\Modules\HR\Hr\Attendance\Models\PayrollHandoff;
use App\Modules\HR\Hr\Attendance\Models\PayrollHandoffLine;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use App\Modules\HR\Hr\Attendance\Models\PieceworkShare;
use App\Modules\HR\Payroll\Enums\EarningsLineType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Approved time and piecework, handed to payroll once (Phase 2 §1.6, LAB-009).
 *
 * Payroll stays the authority on pay. Mkhaya Agri hands over:
 *  - approved hours, as HR attendance records (source "agri") — which payroll's own overtime
 *    calculator already reads and prices;
 *  - approved piecework, as "piecework" earnings lines on the person's payslip in the open payroll
 *    run for the period, tagged with the handoff reference so a resend replaces rather than adds.
 * People with no in-house payslip (seasonal staff paid by a bureau or another system) are exported
 * instead. Every source record is stamped with the handoff line that carried it, so nothing is sent
 * twice, and each line shows what was prepared against what was delivered.
 */
class PayrollHandoffs
{
    public function __construct(private readonly Workforce $workforce) {}

    public function prepare(int $companyId, Carbon $from, Carbon $to, User $actor): PayrollHandoff
    {
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['period_end' => 'The period must end after it starts.']);
        }

        return DB::transaction(function () use ($companyId, $from, $to, $actor) {
            $sheets = Timesheet::withoutGlobalScopes()->where('company_id', $companyId)->where('status', Timesheet::APPROVED)
                ->whereNull('handoff_line_id')->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->lockForUpdate()->get();
            $pieces = PieceworkEntry::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'approved')->whereNotNull('worker_id')
                ->whereNull('handoff_line_id')->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->lockForUpdate()->get();
            $shares = PieceworkShare::withoutGlobalScopes()->where('attendance_piecework_shares.company_id', $companyId)->whereNull('attendance_piecework_shares.handoff_line_id')
                ->join('attendance_piecework_entries as e', 'e.id', '=', 'attendance_piecework_shares.entry_id')->where('e.status', 'approved')
                ->whereBetween('e.work_date', [$from->toDateString(), $to->toDateString()])->select('attendance_piecework_shares.*')->lockForUpdate()->get();
            if ($sheets->isEmpty() && $pieces->isEmpty() && $shares->isEmpty()) {
                throw ValidationException::withMessages(['period_start' => 'Nothing approved in that period is waiting to go to payroll.']);
            }

            $n = PayrollHandoff::withoutGlobalScopes()->where('company_id', $companyId)->count() + 1;
            $handoff = PayrollHandoff::create(['company_id' => $companyId, 'reference' => 'AGP-'.$to->format('Ymd').'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'period_start' => $from, 'period_end' => $to, 'status' => 'prepared', 'prepared_by' => $actor->id]);

            $workerIds = $sheets->pluck('worker_id')->merge($pieces->pluck('worker_id'))->merge($shares->pluck('worker_id'))->unique();
            foreach ($workerIds as $wid) {
                $worker = Worker::withoutGlobalScopes()->findOrFail($wid);
                $mine = $sheets->where('worker_id', $wid);
                [$regular, $overtime] = $this->splitWeekly($mine, $worker);
                $line = PayrollHandoffLine::create([
                    'company_id' => $companyId, 'handoff_id' => $handoff->id, 'worker_id' => $wid, 'hr_person_id' => $worker->hr_person_id,
                    'regular_minutes' => $regular, 'overtime_minutes' => $overtime,
                    'sunday_minutes' => (int) $mine->sum('sunday_minutes'), 'holiday_minutes' => (int) $mine->sum('holiday_minutes'),
                    'night_minutes' => (int) $mine->sum('night_minutes'),
                    'piecework_amount' => round((float) $pieces->where('worker_id', $wid)->sum('amount') + (float) $shares->where('worker_id', $wid)->sum('amount'), 2),
                    'timesheets' => $mine->count(), 'piecework_lines' => $pieces->where('worker_id', $wid)->count() + $shares->where('worker_id', $wid)->count(),
                    'status' => 'prepared',
                ]);
                Timesheet::withoutGlobalScopes()->whereIn('id', $mine->pluck('id'))->update(['handoff_line_id' => $line->id]);
                PieceworkEntry::withoutGlobalScopes()->whereIn('id', $pieces->where('worker_id', $wid)->pluck('id'))->update(['handoff_line_id' => $line->id]);
                PieceworkShare::withoutGlobalScopes()->whereIn('id', $shares->where('worker_id', $wid)->pluck('id'))->update(['handoff_line_id' => $line->id]);
            }
            $handoff->update(['totals' => $this->totals($handoff->fresh())]);

            return $handoff;
        });
    }

    /**
     * Deliver to HR attendance and the payroll run. Safe to repeat: attendance is keyed by person and
     * day, and the run's lines carry this handoff's reference and are replaced, not added to.
     */
    public function send(PayrollHandoff $handoff, User $actor): PayrollHandoff
    {
        if ($handoff->status === 'reconciled') {
            throw ValidationException::withMessages(['handoff' => 'This handoff has been reconciled and closed.']);
        }
        $source = 'agri:'.$handoff->reference;
        $runs = DB::table('payroll_runs')->where('company_id', $handoff->company_id)->whereNull('deleted_at')
            ->whereIn('status', ['draft', 'processing', 'review'])
            ->whereDate('period_start', '<=', $handoff->period_end)->whereDate('period_end', '>=', $handoff->period_start)->get();
        $touchedRuns = [];

        DB::transaction(function () use ($handoff, $source, $runs, &$touchedRuns, $actor) {
            foreach ($handoff->lines()->get() as $line) {
                $minutes = $this->deliverHours($line, $actor);
                $userId = DB::table('hr_people')->where('id', $line->hr_person_id)->value('user_id');
                $deliveredPiece = null;
                $to = 'hr_attendance';
                $note = null;
                if ($line->piecework_amount > 0) {
                    $slip = $userId ? DB::table('payslips')->whereIn('payroll_run_id', $runs->pluck('id'))->where('employee_id', $userId)->whereNull('deleted_at')->first() : null;
                    if ($slip) {
                        DB::table('payroll_earnings_lines')->where('payslip_id', $slip->id)->where('source', $source)->delete();
                        DB::table('payroll_earnings_lines')->insert(['payslip_id' => $slip->id, 'company_id' => $handoff->company_id,
                            'line_type' => EarningsLineType::Piecework->value, 'description' => "Piecework ({$handoff->reference})",
                            'amount' => $line->piecework_amount, 'quantity' => 1, 'source' => $source, 'created_at' => now(), 'updated_at' => now()]);
                        $deliveredPiece = $line->piecework_amount;
                        $touchedRuns[$slip->payroll_run_id] = true;
                    } else {
                        $to = 'export';
                        $note = $userId ? 'No payslip in an open payroll run for this period: export for the payroll that pays this person.'
                            : 'No login, so not on the in-house payroll: export for the bureau or payroll that pays this person.';
                    }
                }
                $mismatch = $minutes !== null && $minutes !== $line->regular_minutes + $line->overtime_minutes;
                $line->update(['delivered_to' => $to, 'delivered_minutes' => $minutes, 'delivered_piecework' => $deliveredPiece,
                    'status' => $to === 'export' ? 'exported' : ($mismatch ? 'mismatch' : 'delivered'),
                    'note' => $mismatch ? trim(($note ?? '').' Some days already had attendance from another source and were left as they were.') : $note]);
            }
            $handoff->update(['status' => 'sent', 'sent_by' => $actor->id, 'sent_at' => now(), 'payroll_run_id' => array_key_first($touchedRuns),
                'totals' => $this->totals($handoff->fresh())]);
        });

        // Payroll re-calculates the payslips it now holds piecework for, under its own rules.
        foreach (array_keys($touchedRuns) as $runId) {
            try {
                $run = \App\Modules\HR\Payroll\Models\PayrollRun::withoutGlobalScopes()->find($runId);
                $run && app(\App\Modules\HR\Payroll\Services\PayrollService::class)->calculateAllPayslips($run, $actor->id);
            } catch (\Throwable $e) {
                Log::warning('Payroll recalculation after agri handoff failed', ['run' => $runId, 'error' => $e->getMessage()]);
            }
        }

        return $handoff->fresh();
    }

    public function reconcile(PayrollHandoff $handoff, User $actor): PayrollHandoff
    {
        $open = $handoff->lines()->whereIn('status', ['prepared', 'mismatch'])->count();
        if ($handoff->status !== 'sent' || $open) {
            throw ValidationException::withMessages(['handoff' => $open ? "{$open} line(s) are not delivered or do not match; resolve them first." : 'Send it first.']);
        }
        $handoff->update(['status' => 'reconciled']);

        return $handoff;
    }

    /** Each approved day into HR's attendance register (DailyRecords), weekly overtime carried on the period's last day. */
    private function deliverHours(PayrollHandoffLine $line, User $actor): ?int
    {
        $sheets = Timesheet::withoutGlobalScopes()->where('handoff_line_id', $line->id)->orderBy('work_date')->get();
        if ($sheets->isEmpty()) {
            return null;
        }
        $worker = Worker::withoutGlobalScopes()->findOrFail($line->worker_id);
        $weeklyExtra = $line->overtime_minutes - (int) $sheets->sum('overtime_minutes');
        $last = $sheets->last()->work_date->toDateString();
        $delivered = 0;
        foreach ($sheets->groupBy(fn ($s) => $s->work_date->toDateString())->keys() as $date) {
            $delivered += app(DailyRecords::class)->sync($worker, $date, $actor, $weeklyExtra > 0 && $date === $last ? $weeklyExtra : 0);
        }

        return $delivered;
    }

    /** Regular and overtime minutes: daily overtime, plus weekly overtime over the rule's weekly threshold. */
    private function splitWeekly(Collection $sheets, Worker $worker): array
    {
        $rules = $this->workforce->rulesFor($worker);
        $regular = 0;
        $overtime = 0;
        foreach ($sheets->groupBy(fn ($s) => $s->work_date->format('o-W')) as $week) {
            $worked = (int) $week->sum('worked_minutes');
            $daily = (int) $week->sum('overtime_minutes');
            $weeklyOver = max(0, ($worked - $daily) - (int) $rules->weekly_ot_after_minutes);
            $ot = $daily + $weeklyOver;
            $overtime += $ot;
            $regular += $worked - $ot;
        }

        return [$regular, $overtime];
    }

    public function totals(PayrollHandoff $handoff): array
    {
        $lines = $handoff->lines()->get();

        return [
            'workers' => $lines->count(),
            'regular_hours' => round($lines->sum('regular_minutes') / 60, 2),
            'overtime_hours' => round($lines->sum('overtime_minutes') / 60, 2),
            'piecework' => round((float) $lines->sum('piecework_amount'), 2),
            'delivered_hours' => round($lines->sum('delivered_minutes') / 60, 2),
            'delivered_piecework' => round((float) $lines->sum('delivered_piecework'), 2),
            'exported_piecework' => round((float) $lines->where('delivered_to', 'export')->sum('piecework_amount'), 2),
            'mismatches' => $lines->where('status', 'mismatch')->count(),
        ];
    }
}
