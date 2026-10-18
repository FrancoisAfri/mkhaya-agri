<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Services\Ledger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The ledger has to find the accounts it posts against.
 *
 * The standard South African chart ships seeded with a null company — it is a library every tenant
 * reads, the same way public holidays and contract templates are. The posting engine matched the
 * company strictly, so those rows became invisible the moment a posting named one, and a correctly
 * seeded installation answered the first invoice somebody raised with
 * "Account [1200] not found for company 1". The chart was sitting right there; nothing would look
 * at it.
 *
 * That is the shape of the bug this guards. The company's own account still wins where it has one,
 * because a tenant renaming or re-purposing an account must not change anybody else's ledger.
 */
class ChartOfAccountsResolutionTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        if (! FinFiscalPeriod::forDate(now(), null)) {
            FinFiscalPeriod::create([
                'company_id' => null,
                'fiscal_year' => (int) now()->year,
                'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(),
                'end_date' => now()->endOfMonth(),
                'status' => 'open',
            ]);
        }
    }

    private function account(?int $companyId, string $code, string $name = 'Test account', bool $postable = true): FinAccount
    {
        return FinAccount::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'type' => 'asset',
            'is_postable' => $postable,
            'is_active' => true,
        ]);
    }

    /** Reaches the private resolver directly: it is the thing under test, and the bug lived in it. */
    private function resolve(string $code, ?int $companyId): FinAccount
    {
        $method = new \ReflectionMethod(Ledger::class, 'resolveAccount');
        $method->setAccessible(true);

        return $method->invoke(app(Ledger::class), $code, $companyId);
    }

    public function test_a_company_posts_against_the_shared_chart_when_it_has_none_of_its_own(): void
    {
        /*
         * The failure that was reported from the live site. A freshly seeded installation holds the
         * chart with a null company and nothing else, so this is the ordinary case rather than an
         * edge one.
         */
        $code = '19' . random_int(10, 99);
        $shared = $this->account(null, $code, 'Shared receivables');

        $resolved = $this->resolve($code, $this->companyId);

        $this->assertSame($shared->id, $resolved->id, 'A shared account has to be reachable from a company posting.');
    }

    public function test_the_companys_own_account_wins_over_the_shared_one(): void
    {
        /*
         * Copy-on-write: a tenant that has renamed or re-purposed an account must get theirs, or
         * the fallback would quietly post one company's transactions against another's definition.
         */
        $code = '19' . random_int(10, 99);
        $this->account(null, $code, 'Shared receivables');
        $mine = $this->account($this->companyId, $code, 'Our receivables');

        $this->assertSame($mine->id, $this->resolve($code, $this->companyId)->id);
    }

    public function test_another_companys_account_is_never_used(): void
    {
        // The fallback widens to shared rows, not to everybody's rows.
        $code = '19' . random_int(10, 99);

        $otherCompany = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Other ' . Str::random(5),
            'slug' => 'other-' . strtolower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->account($otherCompany, $code, 'Their receivables');

        $this->expectException(PostingException::class);
        $this->resolve($code, $this->companyId);
    }

    public function test_a_missing_account_says_what_to_do_about_it(): void
    {
        /*
         * The original message named a code and a company and stopped there, which tells somebody
         * looking at a failed invoice nothing they can act on.
         */
        try {
            $this->resolve('9' . random_int(100, 999), $this->companyId);
            $this->fail('A missing account should have thrown.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('chart of accounts', $e->getMessage());
            $this->assertStringContainsString('finance:ensure-chart', $e->getMessage());
        }
    }

    public function test_a_heading_is_refused_with_the_reason(): void
    {
        // Posting to a heading would put transactions above their own subtotals. Saying which of
        // the two problems it is saves somebody toggling the wrong flag.
        $code = '19' . random_int(10, 99);
        $this->account(null, $code, 'Current Assets', postable: false);

        try {
            $this->resolve($code, $this->companyId);
            $this->fail('A heading should not be postable.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('heading', $e->getMessage());
        }
    }

    // ── The command that reports and repairs ─────────────────────────────────

    public function test_the_command_reports_a_company_with_no_chart(): void
    {
        $bare = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Bare ' . Str::random(5),
            'slug' => 'bare-' . strtolower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('finance:ensure-chart', ['--company' => $bare])
            ->assertExitCode(1);
    }

    public function test_the_command_installs_what_is_missing(): void
    {
        $bare = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Bare ' . Str::random(5),
            'slug' => 'bare-' . strtolower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('finance:ensure-chart', ['--company' => $bare, '--install' => true])
            ->assertExitCode(0);

        $arControl = (string) config('finance.ar_control');

        $this->assertTrue(
            FinAccount::query()->where('company_id', $bare)->where('code', $arControl)->exists(),
            'The control account the ledger asks for has to exist afterwards.',
        );

        // And the whole point: an invoice can now resolve it.
        $this->assertSame($arControl, $this->resolve($arControl, $bare)->code);
    }

    public function test_the_command_is_safe_to_run_twice(): void
    {
        // It is the thing somebody reaches for when a posting has just failed; running it again
        // must not double up the chart.
        $bare = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Bare ' . Str::random(5),
            'slug' => 'bare-' . strtolower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('finance:ensure-chart', ['--company' => $bare, '--install' => true])->assertExitCode(0);
        $after = FinAccount::query()->where('company_id', $bare)->count();

        $this->artisan('finance:ensure-chart', ['--company' => $bare, '--install' => true])->assertExitCode(0);

        $this->assertSame($after, FinAccount::query()->where('company_id', $bare)->count());
    }

    public function test_a_company_reading_the_shared_chart_is_not_reported_as_broken(): void
    {
        /*
         * A company with no accounts of its own is perfectly able to post against the shared chart.
         * Reporting it as missing everything would send somebody installing a duplicate chart it
         * did not need.
         */
        $shared = [];

        foreach (['ar_control', 'ap_control', 'vat_output', 'vat_input', 'default_bank'] as $key) {
            $code = (string) config("finance.{$key}");

            if (! FinAccount::query()->whereNull('company_id')->where('code', $code)->exists()) {
                $shared[] = $this->account(null, $code, 'Shared ' . $code);
            }
        }

        if ($shared === []) {
            $this->markTestSkipped('The shared chart already holds these codes.');
        }

        $bare = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Bare ' . Str::random(5),
            'slug' => 'bare-' . strtolower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($shared as $account) {
            $this->assertSame(
                $account->id,
                $this->resolve($account->code, $bare)->id,
                'A company with no chart of its own posts against the shared one.',
            );
        }
    }
}
