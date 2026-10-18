<?php

namespace Tests\Feature\Database;

use App\Shared\Database\OptionalStatement;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A migration step that is allowed to fail must not silently discard the migration.
 *
 * This is the bug that lost `hr_people.pay_frequency` on a live install: the migration added the
 * column, then tried `CREATE EXTENSION btree_gist`, which the database user had no rights for. The
 * exception was caught and ignored, as intended — but on Postgres a failed statement aborts the
 * whole transaction, and the COMMIT that follows does not raise. It behaves as ROLLBACK.
 *
 * So the column was discarded, Laravel recorded the migration as run immediately afterwards, and
 * every subsequent check agreed the migration had been applied. `migrate:status` said Ran,
 * `migrate` said nothing was pending, and payroll died on a column that did not exist.
 */
class OptionalStatementTest extends TestCase
{
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The aborted-transaction behaviour under test is specific to Postgres.');
        }

        $this->table = 'zz_optional_' . bin2hex(random_bytes(4));
        DB::statement("CREATE TABLE {$this->table} (id int)");
    }

    protected function tearDown(): void
    {
        DB::statement("DROP TABLE IF EXISTS {$this->table}");
        parent::tearDown();
    }

    private function hasColumn(string $column): bool
    {
        return DB::select(
            'select 1 from information_schema.columns where table_name = ? and column_name = ?',
            [$this->table, $column],
        ) !== [];
    }

    /** A statement that cannot possibly succeed, standing in for "permission denied". */
    private function failingStatement(): callable
    {
        return fn () => DB::statement('CREATE EXTENSION IF NOT EXISTS definitely_not_a_real_extension');
    }

    // ── The bug, demonstrated ────────────────────────────────────────────────

    /**
     * Without the guard, catching the error is not enough — and nothing says so.
     *
     * Kept as a test because it is the whole reason the guard exists: if a future Postgres makes a
     * caught failure survivable on its own, this fails and the guard can go.
     */
    public function test_a_plain_try_catch_silently_discards_the_migrations_work(): void
    {
        DB::beginTransaction();
        DB::statement("ALTER TABLE {$this->table} ADD COLUMN pay_frequency varchar(20)");

        try {
            ($this->failingStatement())();
        } catch (\Throwable) {
            // Exactly what the migrations did: carry on without it.
        }

        DB::commit();

        $this->assertFalse($this->hasColumn('pay_frequency'),
            'Postgres no longer discards an aborted transaction on commit — the guard may be removable.');
    }

    /** And the commit gives no hint: it returns as though everything was written. */
    public function test_the_commit_does_not_raise_which_is_why_it_went_unnoticed(): void
    {
        DB::beginTransaction();
        DB::statement("ALTER TABLE {$this->table} ADD COLUMN pay_frequency varchar(20)");

        try {
            ($this->failingStatement())();
        } catch (\Throwable) {
        }

        DB::commit();

        // Reaching here at all is the point: Laravel logs the migration as run right after this.
        $this->assertTrue(true);
    }

    // ── The guard ────────────────────────────────────────────────────────────

    public function test_the_guard_keeps_the_work_that_already_succeeded(): void
    {
        DB::beginTransaction();
        DB::statement("ALTER TABLE {$this->table} ADD COLUMN pay_frequency varchar(20)");

        OptionalStatement::attempt('create a test extension', $this->failingStatement());

        DB::commit();

        $this->assertTrue($this->hasColumn('pay_frequency'),
            'The column was discarded despite the guard — the savepoint did not hold.');
    }

    /** And the transaction stays usable, so later steps still run. */
    public function test_work_after_a_failed_step_still_commits(): void
    {
        DB::beginTransaction();

        OptionalStatement::attempt('create a test extension', $this->failingStatement());

        DB::statement("ALTER TABLE {$this->table} ADD COLUMN added_after varchar(20)");
        DB::commit();

        $this->assertTrue($this->hasColumn('added_after'),
            'The transaction was still poisoned, so everything after the optional step was lost.');
    }

    public function test_it_reports_whether_the_step_succeeded(): void
    {
        DB::beginTransaction();

        $this->assertFalse(
            OptionalStatement::attempt('a step that cannot work', $this->failingStatement()),
            'A failed step reported success, so a migration cannot tell what it actually got.',
        );

        $this->assertTrue(
            OptionalStatement::attempt('a step that works', fn () => DB::statement('SELECT 1')),
        );

        DB::commit();
    }

    /** Two optional steps in a row: the first failing must not affect the second. */
    public function test_several_optional_steps_do_not_interfere(): void
    {
        DB::beginTransaction();

        OptionalStatement::attempt('first', $this->failingStatement());
        $second = OptionalStatement::attempt(
            'second',
            fn () => DB::statement("ALTER TABLE {$this->table} ADD COLUMN second_step varchar(20)"),
        );

        DB::commit();

        $this->assertTrue($second);
        $this->assertTrue($this->hasColumn('second_step'));
    }

    // ── Outside a transaction ────────────────────────────────────────────────

    /**
     * Some migrations disable the wrapping transaction. SAVEPOINT is an error outside one, so the
     * guard has to notice and simply run the work.
     */
    public function test_it_works_outside_a_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $ok = OptionalStatement::attempt(
            'outside a transaction',
            fn () => DB::statement("ALTER TABLE {$this->table} ADD COLUMN no_txn varchar(20)"),
        );

        $this->assertTrue($ok);
        $this->assertTrue($this->hasColumn('no_txn'));

        $this->assertFalse(
            OptionalStatement::attempt('failing outside a transaction', $this->failingStatement()),
        );
    }
}
