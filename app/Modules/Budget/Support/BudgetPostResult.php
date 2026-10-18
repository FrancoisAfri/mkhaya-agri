<?php

namespace App\Modules\Budget\Support;

/**
 * What happened when another module told the budget about money.
 *
 * Returned rather than thrown because the caller is a payroll run or a fleet job whose own work has
 * already succeeded. Telling the budget is bookkeeping that follows the fact; failing it must not
 * unwind a payroll run that has already been approved.
 *
 * The outcome is explicit so a caller that *does* care can act on it, and so the reason a posting
 * did not land is recorded somewhere a person can read rather than inferred from a silence.
 */
final class BudgetPostResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?int $budgetId = null,
        public readonly ?int $ledgerId = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function posted(int $budgetId, int $ledgerId): self
    {
        return new self('posted', $budgetId, $ledgerId);
    }

    /** Already posted under the same reference. Not an error — the point of the reference. */
    public static function duplicate(int $budgetId, int $ledgerId): self
    {
        return new self('duplicate', $budgetId, $ledgerId);
    }

    /** No budget matched the scope. The cost is real; the mapping is missing. */
    public static function unmapped(?string $reason = null): self
    {
        return new self('unmapped', null, null, $reason);
    }

    public static function disabled(): self
    {
        return new self('disabled');
    }

    public static function failed(string $reason): self
    {
        return new self('failed', null, null, $reason);
    }

    public function landed(): bool
    {
        return in_array($this->outcome, ['posted', 'duplicate'], true);
    }
}
