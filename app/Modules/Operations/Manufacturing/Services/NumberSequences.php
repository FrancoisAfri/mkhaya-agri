<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\NumberSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MFG-001.06 — production order, job and document numbering.
 *
 * The number is taken under a row lock inside a transaction. Reading `next_number` through the
 * model and saving it back is the classic way two work orders released in the same second get the
 * same number, and the duplicate is only noticed weeks later when somebody reconciles job cards.
 *
 * Callers must already be inside the tenant context: the sequence row is tenant-scoped, so a
 * lookup from an unstamped connection finds nothing and this reports that rather than inventing
 * a sequence.
 */
class NumberSequences
{
    /**
     * Take the next number for a document type, formatted.
     *
     * A factory-specific sequence wins over the company-wide one, so a group can number per plant
     * where it wants to and centrally where it does not.
     */
    public function next(int $companyId, string $documentType, ?int $factoryId = null): string
    {
        return DB::transaction(function () use ($companyId, $documentType, $factoryId) {
            $sequence = $this->lockSequence($companyId, $documentType, $factoryId);

            if (! $sequence) {
                throw new RuntimeException(
                    "No active number sequence for '{$documentType}'. Run mfg:install-reference-data "
                    .'or configure one under Manufacturing setup.'
                );
            }

            $periodKey = $this->periodKeyFor($sequence->reset_period);

            /*
             * A sequence that resets yearly starts again at 1 when the year turns. The period it
             * last issued into is stored, so the reset happens on first use in the new period
             * rather than needing a scheduled job that might not run.
             */
            if ($periodKey !== null && $sequence->period_key !== $periodKey) {
                /*
                 * A null period key means the sequence has never issued a number, which is not
                 * the same as the period having rolled over. Only a recorded, different period
                 * restarts the count -- otherwise a sequence configured to start at 100 would
                 * quietly start at 1 on its first use and the start number would be ignored.
                 */
                if ($sequence->period_key !== null) {
                    $sequence->next_number = 1;
                }

                $sequence->period_key = $periodKey;
            }

            $number = (int) $sequence->next_number;
            $sequence->next_number = $number + 1;
            $sequence->save();

            return $this->format($sequence, $number, $periodKey);
        });
    }

    /** What the next number would be, without consuming it. For a form preview. */
    public function preview(int $companyId, string $documentType, ?int $factoryId = null): ?string
    {
        $sequence = $this->findSequence($companyId, $documentType, $factoryId);

        if (! $sequence) {
            return null;
        }

        $periodKey = $this->periodKeyFor($sequence->reset_period);
        $rolledOver = $periodKey !== null
            && $sequence->period_key !== null
            && $sequence->period_key !== $periodKey;

        $number = $rolledOver ? 1 : (int) $sequence->next_number;

        return $this->format($sequence, $number, $periodKey);
    }

    /**
     * The period segment is part of the number, not only of the reset.
     *
     * WO-2026-00001 restarting at WO-2027-00001 is unambiguous; WO-00001 appearing twice in two
     * years is not, and the second one looks like a duplicate to anybody reading a ledger.
     */
    private function format(NumberSequence $sequence, int $number, ?string $periodKey): string
    {
        $padded = str_pad((string) $number, (int) $sequence->padding, '0', STR_PAD_LEFT);

        return $sequence->prefix.($periodKey ? $periodKey.'-' : '').$padded.$sequence->suffix;
    }

    private function periodKeyFor(string $resetPeriod): ?string
    {
        return match ($resetPeriod) {
            'YEAR' => Carbon::now()->format('Y'),
            'MONTH' => Carbon::now()->format('Y-m'),
            default => null,
        };
    }

    private function lockSequence(int $companyId, string $documentType, ?int $factoryId): ?NumberSequence
    {
        foreach ($this->scopes($factoryId) as $scope) {
            $sequence = NumberSequence::query()
                ->where('company_id', $companyId)
                ->where('document_type', $documentType)
                ->where('is_active', true)
                ->when($scope === null, fn ($q) => $q->whereNull('factory_id'))
                ->when($scope !== null, fn ($q) => $q->where('factory_id', $scope))
                ->lockForUpdate()
                ->first();

            if ($sequence) {
                return $sequence;
            }
        }

        return null;
    }

    private function findSequence(int $companyId, string $documentType, ?int $factoryId): ?NumberSequence
    {
        foreach ($this->scopes($factoryId) as $scope) {
            $sequence = NumberSequence::query()
                ->where('company_id', $companyId)
                ->where('document_type', $documentType)
                ->where('is_active', true)
                ->when($scope === null, fn ($q) => $q->whereNull('factory_id'))
                ->when($scope !== null, fn ($q) => $q->where('factory_id', $scope))
                ->first();

            if ($sequence) {
                return $sequence;
            }
        }

        return null;
    }

    /** Most specific first: this factory's own sequence, then the company-wide fallback. */
    private function scopes(?int $factoryId): array
    {
        return $factoryId === null ? [null] : [$factoryId, null];
    }
}
