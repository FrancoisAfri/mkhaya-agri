<?php

namespace App\Modules\SupplierManagement\DueDiligence\Support;

/**
 * The vocabulary of due-diligence checks.
 *
 * These strings are the contract between the module and every provider: the module asks for
 * `company.registry` and does not care whether CIPC, an aggregator or a person with a login
 * answered it. Adding a provider must never require a change here, and adding a check type is a
 * deliberate act — which is why they are constants rather than free text.
 *
 * `subject` says what the check is *about*, because a company check and a director screening are
 * dispatched, stored and displayed differently.
 */
final class CheckType
{
    // Layer 1 — verify who this supplier is.
    public const COMPANY_REGISTRY = 'company.registry';          // exists, name, status, type, registration date
    public const COMPANY_DIRECTORS = 'company.directors';        // who the registry says the directors are
    public const COMPANY_ADDRESS = 'company.address';
    public const OWNERSHIP_BENEFICIAL = 'ownership.beneficial';
    public const TAX_COMPLIANCE = 'tax.compliance_status';       // SARS TCS, via the supplier's PIN
    public const TAX_VAT_VENDOR = 'tax.vat_vendor';
    public const BANK_ACCOUNT = 'bank.account';                  // account exists + holder-name match
    public const GOVERNMENT_CSD = 'government.csd';
    public const BBBEE_CERTIFICATE = 'bbbee.certificate';
    public const DOCUMENT_AUTHENTICITY = 'document.authenticity';

    // Layer 2 — assess. Declared now so provider capabilities and the registry screen are complete;
    // the engine that consumes them lands in Phase 3.
    public const CREDIT_REPORT = 'credit.report';
    public const SCREENING_SANCTIONS = 'screening.sanctions';
    public const SCREENING_PEP = 'screening.pep';
    public const MEDIA_ADVERSE = 'media.adverse';
    public const CYBER_POSTURE = 'cyber.posture';

    /** Public social-media presence and mentions. Separate from adverse media: different sources,
     *  different terms of use, and a customer may lawfully have one and not the other. */
    public const SOCIAL_REPUTATION = 'social.reputation';
    public const LITIGATION = 'legal.litigation';

    /**
     * Every check type, with what it is about and how long an answer stays fresh.
     *
     * `valid_days` is the honest expiry of the answer, not a cache: a tax compliance status is a
     * statement about a moment, so a 30-day-old "compliant" is a 30-day-old statement and the
     * Passport must say so rather than showing a green tile forever.
     *
     * @return array<string, array{label:string, subject:string, layer:int, valid_days:int|null}>
     */
    public static function all(): array
    {
        return [
            self::COMPANY_REGISTRY => ['label' => 'Company registry', 'subject' => 'supplier', 'layer' => 1, 'valid_days' => 90],
            self::COMPANY_DIRECTORS => ['label' => 'Registered directors', 'subject' => 'supplier', 'layer' => 1, 'valid_days' => 90],
            self::COMPANY_ADDRESS => ['label' => 'Registered address', 'subject' => 'supplier', 'layer' => 1, 'valid_days' => 180],
            self::OWNERSHIP_BENEFICIAL => ['label' => 'Beneficial ownership', 'subject' => 'supplier', 'layer' => 1, 'valid_days' => 180],
            self::TAX_COMPLIANCE => ['label' => 'Tax compliance status', 'subject' => 'supplier', 'layer' => 1, 'valid_days' => 30],
            self::TAX_VAT_VENDOR => ['label' => 'VAT vendor', 'subject' => 'supplier', 'layer' => 1, 'valid_days' => 180],
            self::BANK_ACCOUNT => ['label' => 'Bank account', 'subject' => 'supplier', 'layer' => 1, 'valid_days' => null],
            self::GOVERNMENT_CSD => ['label' => 'Central Supplier Database', 'subject' => 'supplier', 'layer' => 1, 'valid_days' => 90],
            self::BBBEE_CERTIFICATE => ['label' => 'B-BBEE certificate', 'subject' => 'supplier', 'layer' => 1, 'valid_days' => 365],
            self::DOCUMENT_AUTHENTICITY => ['label' => 'Document authenticity', 'subject' => 'supplier', 'layer' => 1, 'valid_days' => null],
            self::CREDIT_REPORT => ['label' => 'Credit report', 'subject' => 'supplier', 'layer' => 2, 'valid_days' => 30],
            self::SCREENING_SANCTIONS => ['label' => 'Sanctions screening', 'subject' => 'both', 'layer' => 2, 'valid_days' => 30],
            self::SCREENING_PEP => ['label' => 'PEP screening', 'subject' => 'both', 'layer' => 2, 'valid_days' => 90],
            self::MEDIA_ADVERSE => ['label' => 'Adverse media', 'subject' => 'both', 'layer' => 2, 'valid_days' => 30],
            self::CYBER_POSTURE => ['label' => 'Cybersecurity posture', 'subject' => 'supplier', 'layer' => 2, 'valid_days' => 365],
            self::SOCIAL_REPUTATION => ['label' => 'Social media', 'subject' => 'both', 'layer' => 2, 'valid_days' => 30],
            self::LITIGATION => ['label' => 'Litigation', 'subject' => 'supplier', 'layer' => 2, 'valid_days' => 90],
        ];
    }

    /** The checks that make up Layer 1 — the ones Phase 1 wires up. */
    public static function verificationChecks(): array
    {
        return array_keys(array_filter(self::all(), fn ($m) => $m['layer'] === 1));
    }

    public static function exists(string $checkType): bool
    {
        return array_key_exists($checkType, self::all());
    }

    public static function label(string $checkType): string
    {
        return self::all()[$checkType]['label'] ?? $checkType;
    }

    /** How long an answer to this check stays fresh, in days (null = it does not go stale on its own). */
    public static function validDays(string $checkType): ?int
    {
        return self::all()[$checkType]['valid_days'] ?? null;
    }
}
