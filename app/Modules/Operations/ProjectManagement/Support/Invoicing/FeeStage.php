<?php

namespace App\Modules\Operations\ProjectManagement\Support\Invoicing;

/**
 * One row of the Determination of Total Fee — a stage of the appointment.
 *
 * The client's Form 1 numbers these 3.1 to 3.9 and they are kept exactly as numbered: 3.1 Time
 * basis, 3.2 Project Initiation & Scoping, 3.3 Concept & Viability, 3.4 Design Review &
 * Development, 3.5 Documentation & Procurement, 3.6 Contract Admin & Inspection, 3.8 Close-out,
 * 3.9 Completion (feasibility). Note there is no 3.7 — that is the client's numbering, not a
 * transcription error, and renumbering to close the gap would make their account unrecognisable.
 *
 * ── Two kinds of row ────────────────────────────────────────────────────────
 *
 * Most stages earn a share of the total fee: apportionment × progress. Stage 3.1 does not — it is
 * time-based work whose value comes from Annexure 11, and the workbook writes it straight into the
 * column (`F27 = G27 + H27`, fed by `'Annexure 11 Time basis'!H25:H26`). Forcing it through an
 * apportionment it does not have would mean inventing a percentage.
 *
 * So a stage is either apportioned or fixed, never both, and `fixed()` is how the time-basis row
 * and anything like it enters the account.
 */
final class FeeStage
{
    private function __construct(
        public readonly string $code,
        public readonly string $label,
        /** Share of the total fee, as a proportion — 0.25, not 25. Null for a fixed row. */
        public readonly ?string $apportionment,
        /** Completion of this stage, as a proportion. Null for a fixed row. */
        public readonly ?string $progress,
        /** What earlier accounts have already claimed against this stage. */
        public readonly string $previouslyClaimed,
        /** Set only on a fixed row: the value to date, taken from elsewhere. */
        public readonly ?string $fixedValueToDate,
    ) {
    }

    /** A stage that earns its share of the fee: apportionment × progress. */
    public static function apportioned(
        string $code,
        string $label,
        string|float|int|null $apportionment,
        string|float|int|null $progress,
        string|float|int|null $previouslyClaimed = '0',
    ): self {
        return new self(
            $code,
            $label,
            Money::of($apportionment),
            Money::of($progress),
            Money::of($previouslyClaimed),
            null,
        );
    }

    /** A stage whose value to date is stated outright — 3.1 Time basis, from Annexure 11. */
    public static function fixed(
        string $code,
        string $label,
        string|float|int|null $valueToDate,
        string|float|int|null $previouslyClaimed = '0',
    ): self {
        return new self($code, $label, null, null, Money::of($previouslyClaimed), Money::of($valueToDate));
    }

    public function isFixed(): bool
    {
        return $this->fixedValueToDate !== null;
    }
}
