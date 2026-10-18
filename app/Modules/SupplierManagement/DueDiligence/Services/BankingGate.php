<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierSetting;
use App\Modules\SupplierManagement\Models\SupplierVerification;

/**
 * Whether money may leave for this supplier's bank account.
 *
 * The fraud this exists to stop is mundane and expensive: an email arrives on letterhead asking for
 * banking details to be updated, the details belong to somebody else, and the next payment run sends
 * a real amount to a real stranger. The single most effective control against it is comparing the
 * account holder's name at the bank against the supplier you believe you are paying — which is
 * precisely the comparison a `bank.account` verification records.
 *
 * Three verdicts, and the middle one is the reason this is a class rather than a boolean:
 *
 *  - BLOCKED. The bank says the account belongs to somebody else, or has no such account. Payment is
 *    refused, and no setting turns that off. A verified mismatch that only produced a warning would
 *    be a control that documents the loss instead of preventing it.
 *  - UNVERIFIED. Nobody has checked, or no source is configured. Refused only where the tenant has
 *    asked for verification to be mandatory (`require_bank_verification`) — because defaulting that
 *    on would stop every payment in every existing installation the day this ships, and a control
 *    that has to be switched off to get work done is a control nobody keeps.
 *  - OK. Checked, and the holder matches.
 *
 * Banking details changing after a check is handled by the check's own subject: the observation
 * records the account number it verified, so an account that has since been edited reads as
 * unverified rather than inheriting the old answer.
 */
class BankingGate
{
    public const OK = 'ok';

    public const BLOCKED = 'blocked';

    public const UNVERIFIED = 'unverified';

    /**
     * @return array{state:string, blocks:bool, reason:string|null, checked_at:string|null, verification_id:int|null}
     */
    public function verdict(Supplier $supplier): array
    {
        $observation = $this->latestBankCheck($supplier);
        $required = $this->verificationRequired();

        if (! $observation) {
            return $this->unverified(
                'This supplier\'s bank account has never been verified.',
                $required,
            );
        }

        // An observation about a different account tells you nothing about this one.
        if (! $this->coversCurrentAccount($supplier, $observation)) {
            return $this->unverified(
                'The banking details have changed since they were last verified.',
                $required,
                $observation,
            );
        }

        if ($observation->status === VerificationStatus::MISMATCH) {
            return [
                'state' => self::BLOCKED,
                'blocks' => true,
                'reason' => $observation->summary ?: 'The account holder does not match this supplier.',
                'checked_at' => $observation->checked_at?->toDateTimeString(),
                'verification_id' => $observation->id,
            ];
        }

        if ($observation->status === VerificationStatus::NOT_FOUND) {
            return [
                'state' => self::BLOCKED,
                'blocks' => true,
                'reason' => 'The bank has no record of this account.',
                'checked_at' => $observation->checked_at?->toDateTimeString(),
                'verification_id' => $observation->id,
            ];
        }

        if ($observation->status === VerificationStatus::MANUAL_REVIEW) {
            // A near-match on an account holder's name is the signature of the fraud this gate is
            // for, so "close enough" is treated as a stop rather than a pass.
            return [
                'state' => self::BLOCKED,
                'blocks' => true,
                'reason' => $observation->summary ?: 'The account holder name is close but not identical — confirm it before paying.',
                'checked_at' => $observation->checked_at?->toDateTimeString(),
                'verification_id' => $observation->id,
            ];
        }

        if ($observation->status === VerificationStatus::VERIFIED) {
            return [
                'state' => self::OK,
                'blocks' => false,
                'reason' => null,
                'checked_at' => $observation->checked_at?->toDateTimeString(),
                'verification_id' => $observation->id,
            ];
        }

        // UNAVAILABLE or ERROR: an attempt was made and produced no answer.
        return $this->unverified(
            $observation->summary ?: 'The last verification attempt produced no answer.',
            $required,
            $observation,
        );
    }

    public function blocksPayment(Supplier $supplier): bool
    {
        return $this->verdict($supplier)['blocks'];
    }

    /** The reason to show a person who has just been stopped, or null when nothing is wrong. */
    public function blockReason(Supplier $supplier): ?string
    {
        $verdict = $this->verdict($supplier);

        return $verdict['blocks'] ? $verdict['reason'] : null;
    }

    private function latestBankCheck(Supplier $supplier): ?SupplierVerification
    {
        return SupplierVerification::query()
            ->where('supplier_id', $supplier->id)
            ->check(CheckType::BANK_ACCOUNT)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Does this observation describe the account the supplier holds now?
     *
     * Compared on digits only: the same account number is written with and without spaces depending
     * on who typed it, and a formatting difference must not read as a changed account.
     */
    private function coversCurrentAccount(Supplier $supplier, SupplierVerification $observation): bool
    {
        $current = $this->digits((string) $supplier->bank_account_number);

        if ($current === '') {
            return false;
        }

        $claimed = $this->digits((string) (($observation->claimed ?? [])['account_number'] ?? ''));

        // An older observation that never recorded the account number cannot be shown to cover the
        // current one, so it is treated as not covering it.
        return $claimed !== '' && $claimed === $current;
    }

    private function unverified(string $reason, bool $required, ?SupplierVerification $observation = null): array
    {
        return [
            'state' => self::UNVERIFIED,
            'blocks' => $required,
            'reason' => $required
                ? $reason.' Bank verification is required before payment in this company\'s settings.'
                : $reason,
            'checked_at' => $observation?->checked_at?->toDateTimeString(),
            'verification_id' => $observation?->id,
        ];
    }

    private function verificationRequired(): bool
    {
        return (bool) (SupplierSetting::query()->first()->require_bank_verification ?? false);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
