<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxRegistrationVersion;
use App\Modules\Finance\Tax\Models\TaxType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tax registrations per legal entity (§4).
 *
 * A registration is created `pending` with applicability unconfirmed (TAX-REG-003): nothing is
 * generated for it — no periods, no deadlines — until someone confirms the obligation really applies
 * to this business. Every change after creation needs a reason and an effective date, and leaves a
 * version row (TAX-REG-005).
 */
class TaxRegistrationService
{
    private const TRACKED = [
        'reference', 'effective_from', 'effective_to', 'filing_frequency', 'year_end_month', 'authority', 'status',
        'preparer_id', 'reviewer_id', 'escalation_id', 'notes',
    ];

    public function __construct(private readonly TaxAudit $audit)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): TaxRegistration
    {
        $type = TaxType::findOrFail($data['tax_type_id']);
        $this->assertFrequency($type, $data['filing_frequency'] ?? null);
        $this->assertNoOverlap($data['entity_id'] ?? null, $type->id, $data['effective_from'], $data['effective_to'] ?? null);

        return DB::transaction(function () use ($data, $type) {
            $registration = TaxRegistration::create(array_intersect_key($data, array_flip(self::TRACKED)) + [
                'entity_id' => $data['entity_id'] ?? null,
                'tax_type_id' => $type->id,
                'status' => 'pending',
                'applicability_confirmed' => false,
                'version' => 1,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $this->snapshot($registration, null, $data['reason'] ?? 'Registration recorded', Carbon::parse($registration->effective_from));
            $this->audit->record('registration.created', $registration, ['tax_type' => $type->code, 'after' => $registration->only(self::TRACKED)]);

            return $registration;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(TaxRegistration $registration, array $data, string $reason, Carbon|string $effectiveDate): TaxRegistration
    {
        if (trim($reason) === '') {
            throw new TaxControlException('A reason is required to change a tax registration.');
        }
        $type = $registration->taxType;
        if (isset($data['filing_frequency'])) {
            $this->assertFrequency($type, $data['filing_frequency']);
        }
        $from = $data['effective_from'] ?? $registration->effective_from;
        $to = array_key_exists('effective_to', $data) ? $data['effective_to'] : $registration->effective_to;
        $this->assertNoOverlap($registration->entity_id, $type->id, $from, $to, $registration->id);

        return DB::transaction(function () use ($registration, $data, $reason, $effectiveDate, $type) {
            $before = $registration->only(self::TRACKED);
            $registration->fill(array_intersect_key($data, array_flip(self::TRACKED)));
            $dirty = array_keys($registration->getDirty());
            if ($dirty === []) {
                return $registration;
            }

            $registration->version = $registration->version + 1;
            $registration->updated_by = Auth::id();
            $registration->save();

            $after = $registration->only(self::TRACKED);
            $this->snapshot($registration, $this->diff($before, $after), $reason, Carbon::parse($effectiveDate));
            $this->audit->record('registration.updated', $registration, ['tax_type' => $type->code, 'reason' => $reason,
                'before' => array_intersect_key($before, array_flip($dirty)), 'after' => array_intersect_key($after, array_flip($dirty)),
                'context' => ['effective_date' => Carbon::parse($effectiveDate)->toDateString(), 'version' => $registration->version]]);

            return $registration;
        });
    }

    /**
     * TAX-REG-003: someone with authority says this obligation applies to this business. Only then
     * does the registration become active and start producing periods and deadlines.
     */
    public function confirmApplicability(TaxRegistration $registration, string $reason): TaxRegistration
    {
        $missing = $this->missingDetails($registration, forConfirmation: true);
        if ($missing !== []) {
            throw new TaxControlException('Complete the registration first: ' . implode('; ', $missing) . '.');
        }

        return DB::transaction(function () use ($registration, $reason) {
            $registration->forceFill([
                'applicability_confirmed' => true, 'confirmed_by' => Auth::id(), 'confirmed_at' => now(),
                'status' => 'active', 'version' => $registration->version + 1, 'updated_by' => Auth::id(),
            ])->save();
            $this->snapshot($registration, ['status' => ['pending', 'active']], $reason, Carbon::parse($registration->effective_from));
            $this->audit->record('registration.confirmed', $registration, ['tax_type' => $registration->taxType?->code, 'reason' => $reason]);

            return $registration;
        });
    }

    /**
     * TAX-REG-004: what is missing before a return for this registration can be finalised.
     *
     * @return list<string>
     */
    public function missingDetails(TaxRegistration $registration, bool $forConfirmation = false): array
    {
        $missing = [];
        $code = $registration->taxType?->code;

        if (blank($registration->reference)) {
            $missing[] = 'the ' . ($code === 'VAT' ? 'VAT number' : 'tax reference number');
        } elseif ($code === 'VAT' && ! preg_match('/^4\d{9}$/', $registration->reference)) {
            $missing[] = 'a valid VAT number (10 digits starting with 4)';
        } elseif (in_array($code, ['PAYE', 'UIF', 'SDL'], true) && ! preg_match('/^[7LU]\d{9}$/', $registration->reference)) {
            $missing[] = 'a valid ' . $code . ' reference (a letter 7, L or U followed by 9 digits)';
        }
        if (! $registration->preparer_id) {
            $missing[] = 'a responsible preparer';
        }
        if (! $registration->reviewer_id) {
            $missing[] = 'a reviewer';
        } elseif ($registration->reviewer_id === $registration->preparer_id) {
            $missing[] = 'a reviewer who is not also the preparer';
        }
        if (in_array($registration->filing_frequency, ['four_monthly', 'six_monthly', 'annual'], true) && ! $registration->year_end_month) {
            $missing[] = 'the financial year-end month';
        }
        if (! $forConfirmation && ! $registration->applicability_confirmed) {
            $missing[] = 'confirmation that this obligation applies';
        }

        return $missing;
    }

    private function snapshot(TaxRegistration $registration, ?array $changes, string $reason, Carbon $effective): void
    {
        TaxRegistrationVersion::create([
            'company_id' => $registration->company_id,
            'registration_id' => $registration->id,
            'version' => $registration->version,
            'snapshot' => $registration->only(array_merge(self::TRACKED, ['applicability_confirmed', 'tax_type_id', 'entity_id'])),
            'changes' => $changes,
            'reason' => $reason,
            'effective_date' => $effective->toDateString(),
            'changed_by' => Auth::id(),
            'created_at' => now(),
        ]);
    }

    private function diff(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $v) {
            $b = $before[$k] ?? null;
            $norm = fn ($x) => $x instanceof \DateTimeInterface ? $x->format('Y-m-d') : $x;
            if ($norm($b) != $norm($v)) {
                $out[$k] = [$norm($b), $norm($v)];
            }
        }

        return $out;
    }

    private function assertFrequency(TaxType $type, ?string $frequency): void
    {
        if (! $frequency || ! in_array($frequency, $type->frequencies ?? [], true)) {
            throw new TaxControlException("{$type->name} cannot be filed " . str_replace('_', ' ', (string) $frequency)
                . '. Allowed: ' . implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $type->frequencies ?? [])) . '.');
        }
    }

    /** TAX-REG-002 allows many registrations per entity, but never two of one tax over the same dates. */
    private function assertNoOverlap(?int $entityId, int $typeId, $from, $to, ?int $ignoreId = null): void
    {
        $from = Carbon::parse($from)->toDateString();
        $to = $to ? Carbon::parse($to)->toDateString() : null;

        $clash = TaxRegistration::query()->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class)
            ->where('tax_type_id', $typeId)
            ->where(fn ($q) => $entityId ? $q->where('entity_id', $entityId) : $q->whereNull('entity_id'))
            ->whereNotIn('status', ['deregistered'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('effective_from', '<=', $to))
            ->exists();

        if ($clash) {
            throw new TaxControlException('This business already has a registration for this tax over those dates. End the existing one first.');
        }
    }
}
