<?php

namespace App\Modules\Operations\ProjectManagement\Support\Invoicing;

/**
 * The client's standard professional fee stages, as data.
 *
 * Their Form 1 numbers these 3.1 to 3.9 and the numbering is kept exactly, gaps included — **there
 * is no 3.7**. That is their numbering, not a transcription error, and closing the gap would make
 * an account they have been issuing for years unrecognisable.
 *
 * Typing eight rows by hand on every account is how a stage ends up missing from one of them, so
 * "Use the standard stages" seeds the lot.
 *
 * ── The apportionments are the client's own ─────────────────────────────────
 *
 * 5 / 15 / 25 / 25 / 25 / 5, summing to exactly 100%. These are read out of Account 7's own
 * formulas (the U-column results divided by the total fee), not invented — which is why seeding
 * them is safe and leaving them blank was not. A seeded stage with no apportionment earns nothing,
 * and eight rows of zero read as a broken page rather than an unfinished one.
 *
 * They remain editable per account, because a different appointment splits its fee differently.
 *
 * Stage 3.1 is marked `fixed` because it is time-based work whose value comes from Annexure 11
 * rather than from an apportionment of the fee.
 */
final class FeeStageCatalogue
{
    /** @var list<array{code:string, label:string, apportionment:?string, fixed:bool}> */
    private const STAGES = [
        // Time-based work, valued from Annexure 11 rather than by apportioning the fee.
        ['code' => '3.1', 'label' => 'Time basis',                   'apportionment' => null,   'fixed' => true],
        ['code' => '3.2', 'label' => 'Project Initiation & Scoping', 'apportionment' => '0.05', 'fixed' => false],
        ['code' => '3.3', 'label' => 'Concept & Viability',          'apportionment' => '0.15', 'fixed' => false],
        ['code' => '3.4', 'label' => 'Design Review & Development',  'apportionment' => '0.25', 'fixed' => false],
        ['code' => '3.5', 'label' => 'Documentation & Procurement',  'apportionment' => '0.25', 'fixed' => false],
        ['code' => '3.6', 'label' => 'Contract Admin & Inspection',  'apportionment' => '0.25', 'fixed' => false],
        // No 3.7 — see the class docblock.
        ['code' => '3.8', 'label' => 'Close-out',                    'apportionment' => '0.05', 'fixed' => false],
        // Carried at zero: the client's accounts list it but Account 7 apportions nothing to it.
        ['code' => '3.9', 'label' => 'Completion (feasibility)',     'apportionment' => '0',    'fixed' => false],
    ];

    private function __construct()
    {
    }

    /** @return list<array{code:string, label:string, apportionment:?string, fixed:bool}> */
    public static function all(): array
    {
        return self::STAGES;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::STAGES, 'code');
    }

    /**
     * What the standard apportionments add up to — 1.00.
     *
     * Exposed so a screen can tell somebody their edited split no longer totals the fee, which is
     * the mistake that silently under- or over-claims an entire appointment.
     */
    public static function standardTotal(): string
    {
        return Money::money(Money::sum(array_column(self::STAGES, 'apportionment')));
    }
}
