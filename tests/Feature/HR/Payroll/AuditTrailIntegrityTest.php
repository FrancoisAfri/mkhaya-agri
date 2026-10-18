<?php

namespace Tests\Feature\HR\Payroll;

use App\Modules\HR\Payroll\Models\PayrollAuditLog;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An audit trail that cannot be quietly edited.
 *
 * Project Management, SHEQ, Procurement Governance and Supplier Due Diligence all kept append-only,
 * hash-chained trails enforced by database triggers. Payroll — where the money actually moves — kept
 * an ordinary table, so anything with an UPDATE grant could rewrite who released a run, or delete
 * the row saying a payslip changed after approval, and nothing in the data would show it.
 *
 * Two mechanisms, and both are needed. The triggers stop anybody working through the application or
 * an ordinary SQL client. The hash chain covers what triggers cannot — somebody with rights to drop
 * the trigger — by making the tampering detectable afterwards rather than preventing it.
 */
class AuditTrailIntegrityTest extends PayrollTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (int) (DB::table('companies')->min('id')
            ?? Company::create(['name' => 'Audit Test Co'])->id);

        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);
    }

    private function entry(string $action, string $description = 'something happened'): PayrollAuditLog
    {
        return PayrollAuditLog::create([
            'uuid'        => (string) Str::uuid(),
            'company_id'  => $this->companyId,
            'user_id'     => $this->hrManager->id,
            'action'      => $action,
            'description' => $description,
        ]);
    }

    // ── The chain ────────────────────────────────────────────────────────────

    public function test_each_entry_is_hashed_over_the_one_before_it(): void
    {
        $first = $this->entry('run.created');
        $second = $this->entry('run.released');

        $this->assertNotNull($first->fresh()->hash);
        $this->assertNull($first->fresh()->prev_hash, 'The first entry for a company starts the chain.');
        $this->assertSame($first->fresh()->hash, $second->fresh()->prev_hash);
    }

    public function test_one_tenants_writes_do_not_disturb_anothers_chain(): void
    {
        // Chained per company so a tenant's trail can be verified on its own, and so a busy tenant
        // cannot renumber a quiet one's chain.
        $ours = $this->entry('run.created');

        $other = Company::create(['name' => 'Another Employer']);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $other->id]);
        DB::table('payroll_audit_logs')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $other->id,
            'user_id' => $this->hrManager->id, 'action' => 'run.created',
            'description' => 'theirs', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);

        $next = $this->entry('run.released');

        $this->assertSame($ours->fresh()->hash, $next->fresh()->prev_hash);
    }

    // ── Nothing may be edited or removed ─────────────────────────────────────

    /**
     * Run something that is expected to be refused, without poisoning the test's transaction.
     *
     * Postgres aborts the whole transaction when a statement raises, so a plain try/catch leaves
     * every later query in the test failing with "current transaction is aborted". A nested
     * transaction is a SAVEPOINT, which rolls back just the refused statement.
     */
    private function refused(callable $attempt): string
    {
        try {
            DB::transaction($attempt);
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        $this->fail('The audit trail accepted a write it should have refused.');
    }

    public function test_an_entry_cannot_be_updated(): void
    {
        $entry = $this->entry('run.released', 'Released by the payroll officer.');

        $message = $this->refused(fn () => DB::table('payroll_audit_logs')
            ->where('id', $entry->id)
            ->update(['description' => 'Released by somebody else.']));

        $this->assertStringContainsString('append-only', $message);

        $this->assertSame('Released by the payroll officer.', $entry->fresh()->description);
    }

    public function test_an_entry_cannot_be_deleted(): void
    {
        $entry = $this->entry('payslip.changed');

        $message = $this->refused(fn () => DB::table('payroll_audit_logs')
            ->where('id', $entry->id)->delete());

        $this->assertStringContainsString('append-only', $message);

        $this->assertNotNull($entry->fresh());
    }

    public function test_the_table_cannot_be_truncated(): void
    {
        $this->entry('run.created');

        $message = $this->refused(fn () => DB::statement('TRUNCATE payroll_audit_logs'));

        $this->assertStringContainsString('append-only', $message);
    }

    // ── Tampering past the triggers is still detectable ──────────────────────

    public function test_verification_passes_on_an_untouched_trail(): void
    {
        $this->entry('run.created');
        $this->entry('run.calculated');
        $this->entry('run.released');

        $this->artisan('payroll:verify-audit', ['--company' => $this->companyId])
            ->expectsOutputToContain('all intact')
            ->assertExitCode(0);
    }

    public function test_verification_finds_a_row_altered_behind_the_triggers_back(): void
    {
        /*
         * The case the triggers cannot cover: somebody with rights to disable them. The chain does
         * not prevent this — nothing at this layer can — it makes it visible afterwards, which is
         * what an auditor actually needs.
         */
        $this->entry('run.created');
        $tampered = $this->entry('run.released', 'Released by the payroll officer.');
        $this->entry('run.paid');

        DB::statement('ALTER TABLE payroll_audit_logs DISABLE TRIGGER payroll_audit_logs_no_update');

        try {
            DB::table('payroll_audit_logs')->where('id', $tampered->id)
                ->update(['description' => 'Released by somebody else entirely.']);
        } finally {
            DB::statement('ALTER TABLE payroll_audit_logs ENABLE TRIGGER payroll_audit_logs_no_update');
        }

        $this->artisan('payroll:verify-audit', ['--company' => $this->companyId])
            ->expectsOutputToContain('the chain breaks at entry')
            ->assertExitCode(1);
    }

    public function test_entries_written_before_the_chain_existed_are_not_reported_as_tampering(): void
    {
        // They are older than the guarantee, not evidence against it. Counting them as breaches
        // would make the first verification run after deployment look like a disaster.
        DB::statement('ALTER TABLE payroll_audit_logs DISABLE TRIGGER payroll_audit_logs_chain_trg');

        try {
            DB::table('payroll_audit_logs')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'user_id' => $this->hrManager->id, 'action' => 'run.created',
                'description' => 'before the chain', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } finally {
            DB::statement('ALTER TABLE payroll_audit_logs ENABLE TRIGGER payroll_audit_logs_chain_trg');
        }

        $this->entry('run.released');

        $this->artisan('payroll:verify-audit', ['--company' => $this->companyId])
            ->expectsOutputToContain('predate the hash chain')
            ->assertExitCode(0);
    }

    // ── A hash that does not depend on who is looking ────────────────────────

    /**
     * The same untouched row, hashed from two session timezones.
     *
     * The chain hashed `created_at AT TIME ZONE 'UTC'` on a `timestamp without time zone`, which
     * does not normalise a naked timestamp — it reinterprets it as UTC and renders it in the
     * session's zone. So the hash depended on the timezone of whichever connection inserted the
     * row, and the same row verified from one session and failed from another.
     *
     * A false alarm on a tamper-evident trail is worse than no alarm: it sends somebody hunting a
     * breach that never happened, and the second time it cries wolf the trail stops being believed.
     */
    /**
     * The trigger must render the timestamp the same way whatever timezone the session is in.
     *
     * Asserted on the expression rather than by writing a row and verifying it, because that route
     * proved not to reproduce reliably under the test harness — and a regression test that passes
     * for reasons it does not state is worse than none. This is the exact thing that was wrong:
     * `created_at` is `timestamp without time zone`, and `AT TIME ZONE 'UTC'` on a naked timestamp
     * does not normalise it, it reinterprets it as UTC and renders it in the session's zone. The
     * hash then depended on the timezone of whichever connection did the insert, and the same
     * untouched row verified from one session and failed from another.
     *
     * A false alarm on a tamper-evident trail is worse than no alarm: it sends somebody hunting a
     * breach that never happened, and the second time it cries wolf the trail stops being believed.
     */
    public function test_the_hashed_timestamp_is_the_same_from_any_session_timezone(): void
    {
        $expression = $this->timestampExpressionFromTheTrigger();

        $rendered = [];

        foreach (['UTC', 'Africa/Johannesburg', 'America/Chicago'] as $timezone) {
            // SET TIME ZONE takes no bind parameters; these are this test's own literals.
            DB::statement("SET TIME ZONE '{$timezone}'");

            $rendered[$timezone] = DB::selectOne(
                "select {$expression} as rendered from (select timestamp '2026-09-04 10:00:00' as created_at) NEW"
            )->rendered;
        }

        DB::statement("SET TIME ZONE 'UTC'");

        $this->assertSame(
            ['2026-09-04T10:00:00Z'],
            array_values(array_unique($rendered)),
            'The trigger renders the timestamp differently depending on the session timezone, so '
            .'the same untouched row will be reported as tampered with depending on who verifies it. '
            .'Rendered: '.json_encode($rendered)
        );
    }

    /**
     * The timestamp expression the live trigger actually uses.
     *
     * Read out of the installed function rather than repeated here, so this cannot pass against a
     * copy of the expression while the deployed one says something else.
     */
    private function timestampExpressionFromTheTrigger(): string
    {
        $source = DB::selectOne(
            'select prosrc from pg_proc where proname = ?', ['payroll_audit_logs_chain']
        )?->prosrc ?? '';

        $pattern = '/(to_char\(\s*NEW\.created_at.*?\))\s*,\s*\'\'\)/s';

        if (! preg_match($pattern, $source, $m)) {
            $this->fail('Could not find the timestamp expression in payroll_audit_logs_chain.');
        }

        return $m[1];
    }

    /** And the verifier must agree with the writer whatever timezone PHP is running in. */
    public function test_the_verifier_agrees_from_any_timezone(): void
    {
        $this->entry('run.created');
        $this->entry('run.released');

        $was = date_default_timezone_get();

        try {
            foreach (['UTC', 'Africa/Johannesburg', 'America/Chicago'] as $timezone) {
                date_default_timezone_set($timezone);

                $this->artisan('payroll:verify-audit', ['--company' => $this->companyId])
                    ->assertExitCode(0);
            }
        } finally {
            date_default_timezone_set($was);
        }
    }
}
