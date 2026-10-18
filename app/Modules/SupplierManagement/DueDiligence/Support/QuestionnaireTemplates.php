<?php

namespace App\Modules\SupplierManagement\DueDiligence\Support;

/**
 * The questions no registry can answer.
 *
 * Nobody sells an API for "do you have a whistleblower policy" or "who would your subcontractors
 * be". Those come from the supplier in writing, and the answers have to be as durable and as
 * attributable as an automated check or they are worth nothing when somebody asks a year later.
 *
 * Each question declares what a bad answer means, so the score is a property of the template rather
 * than a judgement made per answer — two reviewers reading the same "no" reach the same number.
 * `flag_when` is deliberately explicit: a supplier answering "no" to modern-slavery due diligence is
 * a finding, and a supplier answering "no" to "do you use subcontractors" is not.
 */
final class QuestionnaireTemplates
{
    public const ESG = 'esg';

    public const CYBER = 'cyber';

    public const GOVERNANCE = 'governance';

    /**
     * @return array<string, array{title:string, description:string, applies_when:string, questions:array}>
     */
    public static function all(): array
    {
        return [
            self::GOVERNANCE => [
                'title' => 'Governance & anti-bribery',
                'description' => 'Policies, conflicts and beneficial ownership declarations.',
                // Asked of everybody above the smallest engagements: it is the pack that speaks to
                // whether the company is run at all, and it is cheap to answer.
                'applies_when' => 'tier >= 2',
                'questions' => [
                    ['code' => 'anti_bribery_policy', 'question' => 'Do you have a written anti-bribery and anti-corruption policy?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'high'],
                    ['code' => 'whistleblower', 'question' => 'Do you have a whistleblowing channel your staff can use without retaliation?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'medium'],
                    ['code' => 'conflict_policy', 'question' => 'Do you have a conflict-of-interest policy covering your directors and staff?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'medium'],
                    ['code' => 'related_to_buyer', 'question' => 'Is any owner, director or employee of your company related to, or employed by, our organisation?', 'type' => 'boolean', 'flag_when' => 'yes', 'severity' => 'high'],
                    ['code' => 'political_office', 'question' => 'Does any owner or director hold, or have they held in the last 12 months, public office or a senior political position?', 'type' => 'boolean', 'flag_when' => 'yes', 'severity' => 'high'],
                    ['code' => 'prior_debarment', 'question' => 'Has your company or any director been debarred, blacklisted or excluded from tendering by any organ of state?', 'type' => 'boolean', 'flag_when' => 'yes', 'severity' => 'critical'],
                    ['code' => 'ownership_declaration', 'question' => 'Confirm that the beneficial owners you have declared are complete and current.', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'high'],
                    ['code' => 'subcontracting', 'question' => 'Would any part of this work be subcontracted? If so, to whom?', 'type' => 'text', 'flag_when' => null, 'severity' => null],
                ],
            ],

            self::CYBER => [
                'title' => 'Cybersecurity & data protection',
                'description' => 'For suppliers who would touch your data, systems or premises.',
                // Noise for a stationery supplier, essential for a payroll integration — which is why
                // it keys off the engagement's tier rather than being asked of everybody.
                'applies_when' => 'tier >= 3',
                'questions' => [
                    ['code' => 'processes_personal_data', 'question' => 'Would you process personal information belonging to us, our staff or our customers?', 'type' => 'boolean', 'flag_when' => null, 'severity' => null],
                    ['code' => 'dpa_signed', 'question' => 'Are you willing to sign a data-processing agreement under POPIA?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'critical'],
                    ['code' => 'iso27001', 'question' => 'Do you hold ISO 27001 certification, or an equivalent independently audited security certification?', 'type' => 'boolean', 'flag_when' => null, 'severity' => null],
                    ['code' => 'breach_history', 'question' => 'Have you suffered a data breach or security incident in the last 24 months?', 'type' => 'boolean', 'flag_when' => 'yes', 'severity' => 'high'],
                    ['code' => 'breach_notification', 'question' => 'Would you notify us of a breach affecting our data within 72 hours?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'high'],
                    ['code' => 'mfa', 'question' => 'Is multi-factor authentication enforced on your staff accounts?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'medium'],
                    ['code' => 'pen_test', 'question' => 'Have your systems been penetration tested in the last 12 months?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'medium'],
                    ['code' => 'cyber_insurance', 'question' => 'Do you carry cyber liability insurance?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'low'],
                    ['code' => 'offshore_processing', 'question' => 'Would any of our data be stored or processed outside South Africa?', 'type' => 'boolean', 'flag_when' => 'yes', 'severity' => 'medium'],
                ],
            ],

            self::ESG => [
                'title' => 'Environmental, social & governance',
                'description' => 'Labour practices, safety, environmental compliance and human rights.',
                'applies_when' => 'tier >= 3',
                'questions' => [
                    ['code' => 'child_labour', 'question' => 'Do you confirm that no person under 18 is employed in your operations or those of your subcontractors?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'critical'],
                    ['code' => 'forced_labour', 'question' => 'Do you have controls against forced labour and human trafficking in your supply chain?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'critical'],
                    ['code' => 'minimum_wage', 'question' => 'Are all your employees paid at or above the applicable minimum wage?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'high'],
                    ['code' => 'coida', 'question' => 'Is your COIDA (workmen\'s compensation) registration current?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'high'],
                    ['code' => 'safety_incidents', 'question' => 'Have you had a reportable health-and-safety incident in the last 24 months?', 'type' => 'boolean', 'flag_when' => 'yes', 'severity' => 'medium'],
                    ['code' => 'environmental_authorisation', 'question' => 'Do you hold all environmental authorisations your operations require?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'high'],
                    ['code' => 'environmental_prosecution', 'question' => 'Have you been prosecuted or fined for an environmental contravention in the last five years?', 'type' => 'boolean', 'flag_when' => 'yes', 'severity' => 'high'],
                    ['code' => 'waste_management', 'question' => 'Do you have a documented waste-management process?', 'type' => 'boolean', 'flag_when' => 'no', 'severity' => 'low'],
                    ['code' => 'carbon_reporting', 'question' => 'Do you measure and report your carbon emissions?', 'type' => 'boolean', 'flag_when' => null, 'severity' => null],
                ],
            ],
        ];
    }

    public static function get(string $pack): ?array
    {
        return self::all()[$pack] ?? null;
    }

    /**
     * Which packs an engagement at this tier should be asked.
     *
     * Tier-driven for the same reason the checks are: asking a stationery supplier nine ESG questions
     * produces nine unread answers and one supplier who stops replying to procurement.
     *
     * @return array<int, string>
     */
    public static function forTier(?int $tier): array
    {
        $tier ??= 2;

        return array_keys(array_filter(self::all(), function (array $pack) use ($tier) {
            preg_match('/tier >= (\d)/', $pack['applies_when'], $m);

            return $tier >= (int) ($m[1] ?? 0);
        }));
    }
}
