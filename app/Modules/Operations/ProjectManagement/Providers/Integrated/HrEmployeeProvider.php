<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\AvailabilityResult;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\EmployeeProvider;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * People, backed by HR (PM-1301b).
 *
 * Selected when the tenant has `hr.employees`. The identity, the name and — crucially — leave come
 * from the module that actually owns them, so PM stops guessing whether someone is available.
 *
 * Names come from `hr_people.first_name + surname`: there is no `users.name` column on this
 * platform, and a provider that assumed one would return blanks for everybody.
 */
class HrEmployeeProvider implements EmployeeProvider
{
    /**
     * The BCEA ordinary month: 45 hours a week × 52 weeks ÷ 12 months.
     *
     * The divisor South African payroll uses to turn a monthly package into an hourly rate. Named
     * rather than inlined because the number is a legal convention, not an arithmetic convenience.
     */
    private const ORDINARY_MONTH_HOURS = 195.0;

    public function search(string $query, int $limit = 20): array
    {
        return HRPerson::query()
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'ilike', '%' . $query . '%')
                ->orWhere('surname', 'ilike', '%' . $query . '%')
                ->orWhere('email', 'ilike', '%' . $query . '%')))
            ->limit($limit)
            ->get()
            ->map(fn (HRPerson $p) => $this->present($p))
            ->all();
    }

    public function get(string $id): ?ProvidedRecord
    {
        $person = $this->find($id);

        return $person ? $this->present($person) : null;
    }

    /**
     * An id may arrive as a bigint or a uuid. Comparing a value to the column it cannot match
     * raises in Postgres and aborts the transaction, so each comparison is made only when the
     * value has the right shape.
     */
    private function find(string $id): ?HRPerson
    {
        $isUuid = Str::isUuid($id);
        $isNumeric = ctype_digit($id);

        if (! $isUuid && ! $isNumeric) {
            return null;
        }

        return HRPerson::query()
            ->where(fn ($q) => $q
                ->when($isUuid, fn ($w) => $w->orWhere('uuid', $id))
                ->when($isNumeric, fn ($w) => $w->orWhere('id', (int) $id)))
            ->first();
    }

    /**
     * Leave is the reason this integration exists: booking a foreman who is on leave next week is
     * the mistake standalone mode cannot catch.
     *
     * The leave table is checked defensively — a tenant may hold `hr.employees` through the people
     * module without leave management installed, and a missing table must degrade to "not checked"
     * rather than throw (PM-1043).
     */
    public function isAvailable(string $id, string $from, string $to): AvailabilityResult
    {
        $person = $this->find($id);

        if (! $person) {
            return AvailabilityResult::unknown();
        }

        if (! $this->supportsLeave()) {
            return AvailabilityResult::unknown();
        }

        // The column is `employee_id`, and it foreign-keys to hr_people.id — not `hr_person_id`,
        // which would have matched nothing and quietly reported everyone as available.
        $onLeave = DB::table('leave_requests')
            ->where('employee_id', $person->id)
            ->whereIn('status', ['approved', 'Approved'])
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->exists();

        return new AvailabilityResult(
            available: ! $onLeave,
            checked: ['leave'],
            conflicts: $onLeave ? ['on_leave'] : [],
        );
    }

    public function supportsLeave(): bool
    {
        return Schema::hasTable('leave_requests');
    }

    /**
     * What an hour of this person costs, from payroll (`hr.labour_rates`).
     *
     * Derived from the most recent payslip's basic earnings over the standard month, because this
     * platform has no per-employee hourly rate column — pay is a monthly package. The divisor is
     * the BCEA ordinary month (45 hours a week × 52 ÷ 12 = 195), which is the figure South African
     * payroll uses to turn a salary into an hourly rate for overtime.
     *
     * Null whenever any part of that is missing. A rate is the multiplier on every costed hour on
     * the project, so a guessed one is not a small error — and unlike a blank, nobody can see it
     * was guessed.
     */
    public function hourlyRateFor(string $id): ?float
    {
        $person = $this->find($id);

        if (! $person || ! ModuleTable::ready('payslips', ['employee_id', 'basic_salary'])) {
            return null;
        }

        $basic = ModuleTable::query('payslips')
            ?->where('employee_id', $person->id)
            ->orderByDesc('id')
            ->value('basic_salary');

        return $basic > 0 ? round(((float) $basic) / self::ORDINARY_MONTH_HOURS, 2) : null;
    }

    /**
     * Hours attendance actually recorded across a window (`hr.attendance`).
     *
     * Shown beside booked hours rather than instead of them. The two disagreeing is the useful
     * signal — somebody on site whose time was never booked, or eight hours booked on a day nobody
     * clocked in — and collapsing them into one number would hide exactly that.
     *
     * Null, never zero, when attendance is not tracked: zero against forty booked reads as a
     * discrepancy the system never actually found.
     */
    public function attendedHours(string $id, string $from, string $to): ?float
    {
        $person = $this->find($id);

        if (! $person || ! ModuleTable::ready('attendance_records', ['employee_id', 'date', 'hours_worked'])) {
            return null;
        }

        $hours = ModuleTable::query('attendance_records')
            ?->where('employee_id', $person->id)
            ->whereBetween('date', [$from, $to])
            ->sum('hours_worked');

        return round((float) $hours, 2);
    }

    /**
     * Certifications the person holds, and whether they have lapsed (`lms.competencies`).
     *
     * Both sources are read where present: the learning module issues certificates, and SHEQ keeps
     * the statutory competency records that matter on a site. A person may be qualified through
     * either, and reading only one would report a competent operator as unqualified.
     *
     * @return list<array{name:string, expires_at:?string, expired:bool}>
     */
    public function qualificationsFor(string $id): array
    {
        $person = $this->find($id);

        if (! $person) {
            return [];
        }

        $out = [];

        // The learning module issues certificates against the HR person, and names them through
        // lms_certifications — left-joined so a certificate whose definition was removed still
        // appears, under its number, rather than vanishing.
        if (ModuleTable::ready('lms_certificates', ['hr_person_id', 'certificate_number', 'expires_at'])) {
            $query = ModuleTable::query('lms_certificates')?->where('hr_person_id', $person->id)->limit(50);

            if ($query && ModuleTable::ready('lms_certifications', ['id', 'name'])) {
                $query->leftJoin('lms_certifications', 'lms_certifications.id', '=', 'lms_certificates.certification_id')
                    ->select('lms_certificates.*', 'lms_certifications.name as certification_name');
            }

            foreach ($query?->get() ?? [] as $row) {
                $out[] = $this->qualification(
                    $row->certification_name ?? $row->certificate_number ?? 'Certificate',
                    $row->expires_at ?? null,
                );
            }
        }

        // SHEQ keeps the statutory competencies that actually matter on a site. A person may be
        // qualified through either register, so reading only one would report a competent operator
        // as unqualified.
        if (ModuleTable::ready('sheq_competency_records', ['hr_person_id', 'status'])) {
            $rows = ModuleTable::query('sheq_competency_records')
                ?->where('hr_person_id', $person->id)
                ->limit(50)
                ->get() ?? [];

            foreach ($rows as $row) {
                $lapsed = in_array((string) ($row->status ?? ''), ['expired', 'lapsed', 'revoked'], true);

                $out[] = $this->qualification(
                    $row->certificate_ref ?: ($row->certificate_number ?: 'Competency'),
                    $row->expires_at ?? null,
                    $lapsed,
                );
            }
        }

        return $out;
    }

    /**
     * @return array{name:string, expires_at:?string, expired:bool}
     */
    private function qualification(string $name, mixed $expires, bool $lapsed = false): array
    {
        $expiresAt = $expires ? (string) $expires : null;

        return [
            'name'       => $name,
            'expires_at' => $expiresAt,
            // A date in the past counts as expired whatever the status says: a certificate that ran
            // out in March is not current because nobody got round to updating its row.
            'expired'    => $lapsed || ($expiresAt !== null && strtotime($expiresAt) < time()),
        ];
    }

    private function present(HRPerson $person): ProvidedRecord
    {
        $name = trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''));

        return new ProvidedRecord(
            id: (string) $person->id,
            label: $name !== '' ? $name : ($person->email ?? 'Unnamed employee'),
            source: 'hr',
            email: $person->email,
            meta: ['employee_number' => $person->employee_number ?? null],
        );
    }
}
