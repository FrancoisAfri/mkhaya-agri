<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Services\Edition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one company on a single-tenant installation.
 *
 * On a `self_hosted` edition there is one customer and one company. There is no tenant to choose
 * between, so code that refuses to act without an explicit choice is not being careful — it is
 * asking a question with exactly one possible answer and then stranding the user on the answer.
 *
 * The "exactly one" guard is the whole safety argument. An installation holding two live companies
 * is genuinely ambiguous again, and guessing there would be the cross-tenant leak that all of this
 * exists to prevent. Two answers means no answer, not a best guess.
 *
 * ── One company, or one that is actually in use ──────────────────────────────
 *
 * "Exactly one row" turned out to be too literal. A standalone install accumulates companies that
 * are not the customer: a trial created while evaluating, a `test` left over from setup. The
 * install still has one company it operates as — the active one — and refusing to name it sent
 * the administrator to "view as company", which is a multi-tenant control and reads as nonsense
 * on a single-tenant box.
 *
 * So there are two passes, and the uniqueness guard holds in both. One company means that one.
 * Otherwise exactly one *active* company means that one, because trial and suspended rows are not
 * what the install is being used as. Two active companies is still ambiguous, and still gets no
 * answer.
 *
 * ── Deliberately not cached ──────────────────────────────────────────────────
 *
 * This was memoised first, because it sits on the write path of every tenant-scoped model. That
 * was wrong, and a test caught it: create a second company and the cached answer still names the
 * first. The count is the entire safety argument, so caching it means a second tenant can appear
 * while this goes on insisting there is only one — the precise leak the guard exists to stop, now
 * held open by an optimisation.
 *
 * The cost of being right is near zero anyway. Every caller reaches this only after the active
 * company and the user's own have both come back empty, which on a working installation is not the
 * common path, and the query itself stops at two rows.
 */
final class SoleCompany
{
    /** The sole company's id, or null if this installation is not that shape. */
    public static function id(): ?int
    {
        if (! Edition::isSelfHosted()) {
            return null;
        }

        // Migrations run before the table exists, and this is reachable from a model observer
        // firing inside one.
        if (! Schema::hasTable('companies')) {
            return null;
        }

        $live = fn () => DB::table('companies')->when(
            Schema::hasColumn('companies', 'deleted_at'),
            fn ($q) => $q->whereNull('deleted_at')
        );

        $ids = $live()->limit(2)->pluck('id');

        if ($ids->count() === 1) {
            return (int) $ids->first();
        }

        // Several rows, but a standalone install still operates as one of them. The active one is
        // that company; trials and suspended leftovers are not what anybody is working in.
        if ($ids->count() > 1 && Schema::hasColumn('companies', 'status')) {
            $active = $live()->where('status', 'active')->limit(2)->pluck('id');

            if ($active->count() === 1) {
                return (int) $active->first();
            }
        }

        return null;
    }
}
