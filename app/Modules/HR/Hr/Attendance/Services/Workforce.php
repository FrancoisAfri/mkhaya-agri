<?php

namespace App\Modules\HR\Hr\Attendance\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Attendance\Support\Sites;
use Illuminate\Database\Eloquent\Model;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use App\Modules\HR\Hr\Attendance\Models\RuleSet;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Farm workers (Phase 2 §1.1): the HR person plus what the farm needs.
 *
 * Personal details — names, ID numbers, contacts, emergency contact, contract documents — are HR's
 * and are entered there or here into HR's own record; this module never keeps a second copy.
 * Seasonal and casual people are created as HR people without a login, within the organisation's
 * non-login allowance, exactly as HR creates them.
 */
class Workforce
{
    

    /**
     * The T&A profile of an HR person, made the first time their time is kept — organisation staff
     * clocking in through T&A get one with no site, the default rules and no consent to GPS or
     * biometric clocking recorded (consent is recorded on the person, never assumed).
     */
    public function profileFor(int $hrPersonId): Worker
    {
        return Worker::withoutGlobalScopes()->where('hr_person_id', $hrPersonId)->first()
            ?? DB::transaction(function () use ($hrPersonId) {
                $person = HRPerson::withoutGlobalScopes()->findOrFail($hrPersonId);

                return Worker::withoutGlobalScopes()->firstOrCreate(['company_id' => $person->company_id, 'hr_person_id' => $person->id],
                    ['category' => 'permanent', 'badge_token' => Str::random(40), 'status' => Worker::ACTIVE, 'gps_allowed' => false, 'biometric_allowed' => false]);
            });
    }

    /** Put an existing HR person to work on a farm, or on the organisation's own time (no site). */
    public function enlist(?Model $site, HRPerson $person, array $data, User $actor): Worker
    {
        Sites::assertOperational($site);
        if ($site && (int) $person->company_id !== (int) $site->company_id) {
            throw ValidationException::withMessages(['hr_person_id' => 'That person is not an employee of this organisation.']);
        }
        if (Worker::withoutGlobalScopes()->where('company_id', $person->company_id)->where('hr_person_id', $person->id)->exists()) {
            throw ValidationException::withMessages(['hr_person_id' => 'That person already has a Time & Attendance profile.']);
        }

        return Worker::create($this->workerData($data) + [
            'company_id' => $person->company_id, 'farm_id' => $site?->id, 'hr_person_id' => $person->id,
            'badge_token' => Str::random(40), 'status' => Worker::ACTIVE,
        ]);
    }

    /**
     * Register people who are new to the organisation — a season's pickers — as HR people without a
     * login, and as farm workers, in one go. All or nothing: a row that fails stops the batch, and
     * the error names the row.
     *
     * @param  list<array{first_name: string, surname: string, phone_number?: string, id_number?: string, employee_number?: string}>  $people
     * @return list<Worker>
     */
    public function registerMany(?Model $site, array $people, array $data, User $actor): array
    {
        Sites::assertOperational($site);
        $entitlements = app(CompanyEntitlements::class);

        $companyId = (int) ($site?->company_id ?? $actor->company_id);

        return DB::transaction(function () use ($site, $people, $data, $actor, $entitlements, $companyId) {
            $made = [];
            foreach (array_values($people) as $i => $p) {
                if (! $entitlements->currentCanAddNonLoginEmployee()) {
                    throw ValidationException::withMessages(['people' => 'Row '.($i + 1).': the organisation has used all its non-login employee places. Add places under billing, then register the rest.']);
                }
                if (blank($p['first_name'] ?? null) || blank($p['surname'] ?? null)) {
                    throw ValidationException::withMessages(['people' => 'Row '.($i + 1).': first name and surname are required.']);
                }
                $number = $p['employee_number'] ?? null ?: $this->nextEmployeeNumber($companyId);
                if (HRPerson::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_number', $number)->exists()) {
                    throw ValidationException::withMessages(['people' => 'Row '.($i + 1).": employee number {$number} is already used."]);
                }
                $person = new HRPerson([
                    'uuid' => (string) Str::uuid(), 'user_id' => null,
                    'first_name' => trim($p['first_name']), 'surname' => trim($p['surname']),
                    'phone_number' => $p['phone_number'] ?? null, 'id_number' => $p['id_number'] ?? null,
                    'employee_number' => $number, 'status' => 1,
                    'date_joined' => isset($data['contract_start']) ? strtotime($data['contract_start']) : time(),
                ]);
                // company_id is not mass-assignable on HR people; it is the farm's organisation.
                $person->company_id = $companyId;
                $person->save();
                $made[] = $this->enlist($site, $person, $data, $actor);
            }

            return $made;
        });
    }

    public function update(Worker $worker, array $data): Worker
    {
        $worker->update($this->workerData($data));

        return $worker;
    }

    /** Suspend, terminate or reactivate, always with a reason; history and pay records stay. */
    public function setStatus(Worker $worker, string $status, string $reason): Worker
    {
        if (! in_array($status, [Worker::ACTIVE, Worker::SUSPENDED, Worker::TERMINATED], true)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }
        DB::transaction(function () use ($worker, $status, $reason) {
            $worker->update(['status' => $status, 'status_reason' => $reason]
                + ($status === Worker::TERMINATED ? ['contract_end' => $worker->contract_end ?? today()] : []));
            if ($status !== Worker::ACTIVE) {
                // Future shifts go back to the roster rather than wait for somebody who is not coming.
                \App\Modules\HR\Hr\Attendance\Models\ShiftAssignment::withoutGlobalScopes()->where('worker_id', $worker->id)->where('status', 'assigned')
                    ->whereHas('shift', fn ($q) => $q->withoutGlobalScopes()->where('starts_at', '>', now()))
                    ->update(['status' => 'cancelled', 'warnings' => "Worker {$status}: {$reason}", 'updated_at' => now()]);
            }
        });

        return $worker;
    }

    /** Record that the worker agreed to location or biometric capture (POPIA), or withdrew it. */
    public function recordConsent(Worker $worker, bool $gps, bool $biometric, User $actor): Worker
    {
        $worker->update(['gps_allowed' => $gps, 'biometric_allowed' => $biometric, 'consent_recorded_at' => now(), 'consent_recorded_by' => $actor->id]);

        return $worker;
    }

    public function newBadge(Worker $worker): Worker
    {
        $worker->forceFill(['badge_token' => Str::random(40)])->save();

        return $worker;
    }

    /** The rules that apply to a worker: their own set, else the default for their category, else the default. */
    public function rulesFor(Worker $worker): RuleSet
    {
        if ($worker->labour_rule_set_id && ($own = RuleSet::withoutGlobalScopes()->find($worker->labour_rule_set_id))) {
            return $own;
        }
        $sets = RuleSet::withoutGlobalScopes()->where('company_id', $worker->company_id)->orderByDesc('is_default')->orderBy('id')->get();
        $match = $sets->first(fn ($s) => is_array($s->applies_to) && in_array($worker->category, $s->applies_to, true))
            ?? $sets->firstWhere('is_default', true) ?? $sets->first();

        return $match ?? $this->defaultRules($worker->company_id);
    }

    /**
     * The organisation's starting rule set. Its values are a common South African starting point and
     * it is marked as needing review: overtime, Sunday and public-holiday rules depend on the Basic
     * Conditions of Employment Act, the farm-worker sectoral determination, any collective agreement
     * and each person's contract, which software cannot assume.
     */
    public function defaultRules(int $companyId): RuleSet
    {
        return RuleSet::withoutGlobalScopes()->where('company_id', $companyId)->where('is_default', true)->first()
            ?? RuleSet::create([
                'company_id' => $companyId, 'name' => 'Default labour rules (review before use)', 'is_default' => true,
                'needs_review' => true,
                'notes' => 'Starting values only. Confirm against the BCEA, the agricultural sectoral determination, collective agreements and contracts before relying on them for pay.',
            ]);
    }

    private function workerData(array $data): array
    {
        return array_intersect_key($data, array_flip(['category', 'crew_id', 'labour_rule_set_id', 'hourly_cost_rate', 'contract_start',
            'contract_end', 'contract_type', 'farm_id']));
    }

    private function nextEmployeeNumber(int $companyId): string
    {
        $n = (int) HRPerson::withoutGlobalScopes()->where('company_id', $companyId)->count() + 1;
        do {
            $number = 'FW'.str_pad((string) $n++, 5, '0', STR_PAD_LEFT);
        } while (HRPerson::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_number', $number)->exists());

        return $number;
    }
}
