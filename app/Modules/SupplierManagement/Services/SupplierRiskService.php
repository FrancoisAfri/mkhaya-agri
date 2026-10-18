<?php

namespace App\Modules\SupplierManagement\Services;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SupplierRiskService
{
    /**
     * Scan a supplier for fraud / duplicate / conflict-of-interest signals,
     * upserting open risk flags (no duplicate open flags of the same type per supplier).
     *
     * @return array<int, \App\Modules\SupplierManagement\Models\SupplierRiskFlag> current open flags
     */
    public function scan(Supplier $supplier): array
    {
        $this->checkDuplicateBanking($supplier);
        $this->checkDuplicateRegistration($supplier);
        $this->checkDuplicateEmail($supplier);
        $this->checkFuzzyDuplicate($supplier);
        $this->checkConflictOfInterest($supplier);
        $this->checkBankVsEmployee($supplier);

        return SupplierRiskFlag::query()
            ->where('supplier_id', $supplier->id)
            ->open()
            ->get()
            ->all();
    }

    /**
     * Mark a flag resolved/dismissed.
     */
    public function resolve(SupplierRiskFlag $flag, int $userId, string $status = 'resolved'): SupplierRiskFlag
    {
        $flag->update([
            'status'      => in_array($status, ['resolved', 'dismissed'], true) ? $status : 'resolved',
            'resolved_by' => $userId,
            'resolved_at' => Carbon::now(),
        ]);

        return $flag;
    }

    // ── Detectors ────────────────────────────────────────────────────────────────

    /** Duplicate banking (critical): another supplier with the same bank_account_number. */
    private function checkDuplicateBanking(Supplier $supplier): void
    {
        $value = trim((string) $supplier->bank_account_number);
        if ($value === '') {
            return;
        }

        $matches = Supplier::query()
            ->where('id', '!=', $supplier->id)
            ->where('bank_account_number', $value)
            ->get(['id', 'company_name', 'bank_account_number']);

        if ($matches->isEmpty()) {
            return;
        }

        $this->upsertFlag($supplier, 'duplicate_banking', 'critical', sprintf(
            'Bank account number matches %d other supplier(s): %s.',
            $matches->count(),
            $this->names($matches)
        ), [
            'bank_account_number' => $value,
            'matched_supplier_ids' => $matches->pluck('id')->all(),
        ]);
    }

    /** Duplicate company registration (high). */
    private function checkDuplicateRegistration(Supplier $supplier): void
    {
        $value = trim((string) $supplier->company_registration_number);
        if ($value === '') {
            return;
        }

        $matches = Supplier::query()
            ->where('id', '!=', $supplier->id)
            ->where('company_registration_number', $value)
            ->get(['id', 'company_name']);

        if ($matches->isEmpty()) {
            return;
        }

        $this->upsertFlag($supplier, 'duplicate_registration', 'high', sprintf(
            'Company registration number matches %d other supplier(s): %s.',
            $matches->count(),
            $this->names($matches)
        ), [
            'company_registration_number' => $value,
            'matched_supplier_ids' => $matches->pluck('id')->all(),
        ]);
    }

    /** Duplicate email (medium). */
    private function checkDuplicateEmail(Supplier $supplier): void
    {
        $value = trim((string) $supplier->email);
        if ($value === '') {
            return;
        }

        $matches = Supplier::query()
            ->where('id', '!=', $supplier->id)
            ->where('email', $value)
            ->get(['id', 'company_name']);

        if ($matches->isEmpty()) {
            return;
        }

        $this->upsertFlag($supplier, 'duplicate_email', 'medium', sprintf(
            'Email address matches %d other supplier(s): %s.',
            $matches->count(),
            $this->names($matches)
        ), [
            'email' => $value,
            'matched_supplier_ids' => $matches->pluck('id')->all(),
        ]);
    }

    /**
     * Potential duplicate (medium): another supplier with a very similar company
     * name (entity resolution / fuzzy match) — catches near-duplicate vendors that
     * the exact-match checks miss.
     */
    private function checkFuzzyDuplicate(Supplier $supplier): void
    {
        try {
            $name = $this->normaliseName((string) $supplier->company_name);
            if ($name === '' || strlen($name) < 4) {
                return;
            }

            $others = Supplier::query()
                ->where('id', '!=', $supplier->id)
                ->get(['id', 'company_name']);

            $matches = [];
            foreach ($others as $other) {
                $otherName = $this->normaliseName((string) $other->company_name);
                if ($otherName === '') {
                    continue;
                }

                // Identical normalised names (after dropping Pty/Ltd/etc.) are the
                // strongest duplicate signal; otherwise use fuzzy similarity.
                if ($otherName === $name) {
                    $matches[] = $other->company_name.' (#'.$other->id.')';
                    continue;
                }

                similar_text($name, $otherName, $percent);
                if ($percent >= 85.0) {
                    $matches[] = $other->company_name.' (#'.$other->id.')';
                }
            }

            if (empty($matches)) {
                return;
            }

            $this->upsertFlag($supplier, 'potential_duplicate', 'medium', sprintf(
                'Company name is very similar to %d existing supplier(s): %s. Possible duplicate vendor.',
                count($matches),
                implode(', ', $matches)
            ), ['matches' => $matches]);
        } catch (\Throwable $e) {
            Log::warning('SupplierRiskService fuzzy-duplicate check failed: '.$e->getMessage(), [
                'supplier_id' => $supplier->id,
            ]);
        }
    }

    /** Normalise a company name for fuzzy comparison (lowercase, drop suffixes/punctuation). */
    private function normaliseName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\b(pty|ltd|limited|inc|incorporated|cc|proprietary|holdings|group|co|company)\b/u', '', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9 ]/u', '', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /**
     * Conflict of interest (high): supplier email or any director email matches an employee email.
     * Employee emails resolved from hr_people.email and users.email (hr_people.user_id -> users.id).
     */
    private function checkConflictOfInterest(Supplier $supplier): void
    {
        try {
            $candidates = collect([$supplier->email]);

            $supplier->loadMissing('directors');
            $candidates = $candidates
                ->merge($supplier->directors->pluck('email'))
                ->map(fn ($e) => strtolower(trim((string) $e)))
                ->filter()
                ->unique()
                ->values();

            if ($candidates->isEmpty()) {
                return;
            }

            $employeeEmails = collect();

            // hr_people.email
            if (Schema::hasTable('hr_people') && Schema::hasColumn('hr_people', 'email')) {
                $employeeEmails = $employeeEmails->merge(
                    DB::table('hr_people')
                        ->when(Schema::hasColumn('hr_people', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                        ->whereIn(DB::raw('LOWER(email)'), $candidates->all())
                        ->pluck('email')
                );
            }

            // users.email (linked via hr_people.user_id)
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'email')) {
                $employeeEmails = $employeeEmails->merge(
                    DB::table('users')
                        ->whereIn(DB::raw('LOWER(email)'), $candidates->all())
                        ->pluck('email')
                );
            }

            $matchedEmails = $employeeEmails
                ->map(fn ($e) => strtolower(trim((string) $e)))
                ->filter()
                ->unique()
                ->intersect($candidates)
                ->values();

            if ($matchedEmails->isEmpty()) {
                return;
            }

            $this->upsertFlag($supplier, 'conflict_of_interest', 'high', sprintf(
                'Supplier/director email matches an employee email: %s.',
                $matchedEmails->implode(', ')
            ), [
                'matched_emails' => $matchedEmails->all(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SupplierRiskService COI check failed: ' . $e->getMessage(), [
                'supplier_id' => $supplier->id,
            ]);
        }
    }

    /**
     * Bank-vs-employee (critical): supplier bank_account_number matches an employee bank account.
     * Employee bank accounts live on hr_people.account_number (guarded).
     */
    private function checkBankVsEmployee(Supplier $supplier): void
    {
        try {
            $value = trim((string) $supplier->bank_account_number);
            if ($value === '') {
                return;
            }

            // hr_people.account_number is the employee banking column in this schema.
            if (! Schema::hasTable('hr_people') || ! Schema::hasColumn('hr_people', 'account_number')) {
                // TODO: No employee banking column found; bank-vs-employee check skipped.
                return;
            }

            $match = DB::table('hr_people')
                ->when(Schema::hasColumn('hr_people', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->where('account_number', $value)
                ->first(['id', 'first_name', 'surname']);

            if (! $match) {
                return;
            }

            $name = trim(($match->first_name ?? '') . ' ' . ($match->surname ?? ''));

            $this->upsertFlag($supplier, 'bank_matches_employee', 'critical', sprintf(
                'Supplier bank account number matches an employee bank account%s.',
                $name !== '' ? ' (' . $name . ')' : ''
            ), [
                'bank_account_number' => $value,
                'matched_hr_person_id' => $match->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SupplierRiskService bank-vs-employee check failed: ' . $e->getMessage(), [
                'supplier_id' => $supplier->id,
            ]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    /**
     * Create an open flag of the given type if one does not already exist for the supplier.
     * Refreshes the message/context if an open flag already exists.
     */
    private function upsertFlag(Supplier $supplier, string $type, string $severity, string $message, ?array $context = null): void
    {
        SupplierRiskFlag::query()->updateOrCreate(
            [
                'supplier_id' => $supplier->id,
                'type'        => $type,
                'status'      => 'open',
            ],
            [
                'severity' => $severity,
                'message'  => $message,
                'context'  => $context,
            ]
        );
    }

    private function names(Collection $suppliers): string
    {
        return $suppliers
            ->map(fn ($s) => $s->company_name . ' (#' . $s->id . ')')
            ->implode(', ');
    }
}
