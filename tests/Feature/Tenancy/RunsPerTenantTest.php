<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The shared fan-out guard, tested on its own rather than through any one command.
 *
 * Fifty-odd scheduled commands now lean on this trait, and they lean on it in shapes it does not
 * control: some return `self::SUCCESS` from the middle of handle(), some declare `handle(): void`
 * and cannot signal failure at all, several have two exit paths and take whichever one applies.
 *
 * That is why the exit code is corrected in run() rather than at each return. Patching "the last
 * return in handle()" was the obvious approach and it is wrong in exactly the cases that matter:
 * on a command with a `--sync` branch and a queued branch it lands the check on whichever happens
 * to appear last in the file, so the command reports success on the very path that failed.
 */
class RunsPerTenantTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::table('companies')->count() === 0) {
            $this->markTestSkipped('No company in the test database.');
        }

        Cache::flush();
        app(CurrentCompany::class)->clear();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    /** A command in the awkward shape: it always returns SUCCESS, whatever happened per tenant. */
    private function alwaysSucceedsCommand(callable $work): Command
    {
        return new class($work) extends Command
        {
            use RunsPerTenant;

            protected $signature = 'test:always-succeeds';

            public function __construct(private $work)
            {
                parent::__construct();
            }

            public function handle(TenantContext $tenants): int
            {
                $this->eachTenant($tenants, $this->work);

                // The shape the trait has to survive: an unconditional success.
                return self::SUCCESS;
            }
        };
    }

    /** Named execute() rather than run(): PHPUnit's TestCase::run() is final. */
    private function execute(Command $command): int
    {
        $command->setLaravel($this->app);

        return $command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\BufferedOutput,
        );
    }

    public function test_a_command_that_always_returns_success_still_fails_when_a_tenant_did(): void
    {
        $command = $this->alwaysSucceedsCommand(function (int $companyId) {
            throw new RuntimeException("company {$companyId} is broken");
        });

        $this->assertSame(
            Command::FAILURE,
            $this->execute($command),
            'handle() returned SUCCESS and the trait did not correct it — nothing keyed on exit status would fire.',
        );
    }

    public function test_a_clean_run_is_left_alone(): void
    {
        // The guard must not turn healthy runs red, or it gets muted and stops being read.
        $command = $this->alwaysSucceedsCommand(fn (int $companyId) => null);

        $this->assertSame(Command::SUCCESS, $this->execute($command));
    }

    public function test_every_tenant_is_visited_even_after_one_throws(): void
    {
        /*
         * The behaviour the exit code is only a signal for. Before isolation, a throw on the first
         * company ended the pass and every company behind it was silently skipped.
         */
        $seen = [];

        // A second active company is created rather than hoped for: on a single-tenant database
        // this test would skip, and "the walk continues past a failure" is precisely the property
        // that cannot be observed with one tenant.
        DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Fan-out Fixture',
            'slug' => 'fan-out-fixture-'.Str::random(6),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $companies = DB::table('companies')->whereIn('status', ['active', 'trial'])->pluck('id');

        $this->assertGreaterThanOrEqual(2, $companies->count());

        $command = $this->alwaysSucceedsCommand(function (int $companyId) use (&$seen, $companies) {
            $seen[] = $companyId;

            if ($companyId === (int) $companies->first()) {
                throw new RuntimeException('the first tenant is broken');
            }
        });

        $this->execute($command);

        $this->assertSame(
            $companies->map(fn ($id) => (int) $id)->all(),
            $seen,
            'The walk stopped at the failing tenant instead of carrying on.',
        );
    }

    public function test_the_work_runs_inside_its_tenants_context(): void
    {
        // Without this the console pass has no tenant applied, row-level security is inert, and
        // every query sees the whole estate.
        $applied = [];

        $command = $this->alwaysSucceedsCommand(function (int $companyId) use (&$applied) {
            $applied[$companyId] = app(CurrentCompany::class)->id();
        });

        $this->execute($command);

        $this->assertNotEmpty($applied);

        foreach ($applied as $companyId => $active) {
            $this->assertSame($companyId, $active, "Work for company {$companyId} ran as tenant ".var_export($active, true));
        }
    }

    public function test_the_tenant_is_cleared_once_the_command_is_done(): void
    {
        // The scheduler runs commands back to back in one process; a leaked tenant is handed to
        // whatever runs next.
        $this->execute($this->alwaysSucceedsCommand(fn (int $companyId) => null));

        $this->assertNull(app(CurrentCompany::class)->id());
    }
}
