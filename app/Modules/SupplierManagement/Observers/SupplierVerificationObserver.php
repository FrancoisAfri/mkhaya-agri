<?php

namespace App\Modules\SupplierManagement\Observers;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns a failed check into something a person will actually see.
 *
 * The observation log is complete and nobody reads a log. Procurement works off the supplier's risk
 * flags, so a verification that comes back a mismatch has to arrive there — otherwise the check ran,
 * the answer was recorded, and the next purchase order goes out anyway.
 *
 * The reverse matters just as much: a supplier who fixes the problem and re-verifies has their flag
 * resolved automatically. A flag that can only be cleared by hand becomes a flag everyone learns to
 * ignore, which costs more than never raising it.
 *
 * Kept in an observer rather than in the engine so the engine stays a recorder — one place that
 * knows how to run and store a check, with no opinion about what the business does next.
 */
class SupplierVerificationObserver
{
    /** How seriously a failure on each check should be taken. */
    private const SEVERITY = [
        CheckType::BANK_ACCOUNT => 'critical',
        CheckType::COMPANY_REGISTRY => 'high',
        CheckType::OWNERSHIP_BENEFICIAL => 'high',
        CheckType::TAX_COMPLIANCE => 'high',
        CheckType::COMPANY_DIRECTORS => 'medium',
        CheckType::TAX_VAT_VENDOR => 'medium',
        CheckType::BBBEE_CERTIFICATE => 'medium',
        CheckType::GOVERNMENT_CSD => 'low',
        CheckType::COMPANY_ADDRESS => 'low',
    ];

    public function created(SupplierVerification $verification): void
    {
        $type = 'verification_'.str_replace('.', '_', $verification->check_type);

        if (VerificationStatus::needsAttention($verification->status)) {
            $this->raise($verification, $type);

            return;
        }

        if ($verification->passed()) {
            $this->resolve($verification, $type);
        }

        // UNAVAILABLE and ERROR raise nothing. A check that could not run is a coverage gap, shown
        // on the Passport and the Data Source Registry — turning it into a risk flag against the
        // supplier would blame them for our missing integration, and bury the real flags.
    }

    private function raise(SupplierVerification $verification, string $type): void
    {
        $subject = $verification->subject_type === 'person' && $verification->subject_label
            ? " ({$verification->subject_label})"
            : '';

        SupplierRiskFlag::query()->updateOrCreate(
            [
                'supplier_id' => $verification->supplier_id,
                'type' => $type,
                'status' => 'open',
            ],
            [
                'severity' => self::SEVERITY[$verification->check_type] ?? 'medium',
                'message' => Str::limit(
                    $verification->checkLabel().$subject.': '.($verification->summary ?: $verification->status),
                    480,
                    ''
                ),
                'context' => [
                    'check_type' => $verification->check_type,
                    'status' => $verification->status,
                    'provider' => $verification->provider_code,
                    'verification_id' => $verification->id,
                    'mismatched_fields' => $verification->mismatchedFields(),
                    'checked_at' => $verification->checked_at?->toDateTimeString(),
                ],
            ]
        );
    }

    /** A passing re-check closes the flag its own failure opened, and says why. */
    private function resolve(SupplierVerification $verification, string $type): void
    {
        SupplierRiskFlag::query()
            ->where('supplier_id', $verification->supplier_id)
            ->where('type', $type)
            ->where('status', 'open')
            ->each(function (SupplierRiskFlag $flag) use ($verification) {
                $flag->update([
                    'status' => 'resolved',
                    'resolved_at' => Carbon::now(),
                    'context' => array_merge($flag->context ?? [], [
                        'resolved_by_verification_id' => $verification->id,
                        'resolved_reason' => 'A later check of the same kind passed.',
                    ]),
                ]);
            });
    }
}
