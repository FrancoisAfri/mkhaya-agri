<?php

namespace App\Shared\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A database statement that is allowed to fail, without taking the rest of the migration with it.
 *
 * ── The trap this exists to close ────────────────────────────────────────────
 *
 * Migrations routinely wrap an optional step in try/catch — creating an extension, adding a
 * constraint that needs rights the application user may not have — so that a server which refuses
 * it still gets everything else. On Postgres, inside a transaction, that reasoning is wrong in a
 * way that leaves no trace.
 *
 * A failed statement puts a Postgres transaction into an *aborted* state. Catching the exception
 * does not clear it. Every later statement then fails too, and — this is the part that hides it —
 * the eventual COMMIT does not raise: it silently behaves as ROLLBACK. Laravel runs migrations in
 * a transaction, and records the migration as run *after* that commit returns. So the migration is
 * marked Ran while none of its work survives:
 *
 *     ALTER TABLE hr_people ADD COLUMN pay_frequency ...   -- succeeds
 *     CREATE EXTENSION btree_gist                          -- permission denied, caught, ignored
 *     <commit>                                             -- silently discards the ALTER
 *     INSERT INTO migrations ...                           -- "Ran"
 *
 * `migrate:status` then says Ran, `migrate` says nothing is pending, and the column does not
 * exist. That is precisely how `hr_people.pay_frequency` went missing on a live install while
 * every check said the migration had been applied.
 *
 * ── What this does instead ───────────────────────────────────────────────────
 *
 * The risky statement runs inside a SAVEPOINT. A failure rolls back to that point and nothing
 * else, so the transaction is healthy again and the work already done still commits. The optional
 * step is genuinely optional; everything around it is not.
 */
final class OptionalStatement
{
    /**
     * Run work that may fail, keeping the surrounding transaction usable either way.
     *
     * @param  string    $label  what was being attempted, for the log when it fails
     * @param  callable  $work   the statement(s) to try
     * @param  ?string   $consequence  what the database is missing if it fails, said plainly
     * @return bool  whether the work succeeded
     */
    public static function attempt(string $label, callable $work, ?string $consequence = null): bool
    {
        $connection = DB::connection();

        /*
         * Only meaningful inside a transaction. Outside one a failed statement harms nothing that
         * follows, and issuing SAVEPOINT there is an error in its own right.
         */
        $guarded = $connection->transactionLevel() > 0;

        // Unique per call: nested optional steps must not release each other's savepoint.
        $savepoint = 'optional_' . bin2hex(random_bytes(8));

        if ($guarded) {
            $connection->statement('SAVEPOINT ' . $savepoint);
        }

        try {
            $work();

            if ($guarded) {
                $connection->statement('RELEASE SAVEPOINT ' . $savepoint);
            }

            return true;
        } catch (Throwable $e) {
            if ($guarded) {
                // Undoes only the failed step; the transaction is usable from here.
                $connection->statement('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $connection->statement('RELEASE SAVEPOINT ' . $savepoint);
            }

            // Said out loud rather than swallowed. A silently skipped guard is worse than an
            // absent one, because everything downstream assumes it is there.
            logger()->warning('optional database step skipped: ' . $label, array_filter([
                'consequence' => $consequence,
                'error' => $e->getMessage(),
            ]));

            return false;
        }
    }
}
