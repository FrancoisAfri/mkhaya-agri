<?php

namespace Tests\Feature\Sheq;

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use App\Modules\Tenancy\Support\ResolvesTenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SHEQ on a single-tenant (self-hosted) installation.
 *
 * The failure this guards against is a dead end rather than a crash. Self-hosted installs have one
 * company and no tenant switcher, so an administrator with no `users.company_id` was being told to
 * "use View as company" — a menu that does not exist on their build. Every SHEQ screen showed an
 * empty state and nothing they could do would change it.
 */
class SelfHostedTest extends TestCase
{
    use DatabaseTransactions;

    private string $originalEdition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEdition = (string) config('edition.edition', 'saas');
    }

    /**
     * Put the edition back.
     *
     * `config()` is process-global and survives the transaction rollback, so a test that switches
     * to self-hosted and walks away leaves every suite running after it on the wrong edition —
     * which changes entitlement behaviour and produced several hundred unrelated failures before
     * this existed.
     */
    protected function tearDown(): void
    {
        config(['edition.edition' => $this->originalEdition]);

        parent::tearDown();
    }

    /** A throwaway host for the trait, so the resolution is tested without an HTTP round trip. */
    private function resolver(): object
    {
        return new class
        {
            use ResolvesCompany {
                companyId as public;
                noCompanyMessage as public;
            }
        };
    }

    /**
     * The same, hosted on Procurement Governance's trait.
     *
     * Both now alias one implementation. This is asserted rather than assumed because the drift
     * between two hand-maintained copies is exactly what left the self-hosted dead end in one
     * module after it was fixed in the other.
     */
    private function procurementResolver(): object
    {
        return new class
        {
            use \App\Modules\Procurement\Governance\Support\ResolvesCompany {
                companyId as public;
                noCompanyMessage as public;
            }
        };
    }

    private function request(?object $user = null): Request
    {
        $request = Request::create('/sheq');

        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_a_single_tenant_install_resolves_its_only_company(): void
    {
        if (DB::table('companies')->count() !== 1) {
            $this->markTestSkipped('This database holds more than one company.');
        }

        config(['edition.edition' => 'self_hosted']);

        $expected = (int) DB::table('companies')->value('id');

        // No CurrentCompany, no users.company_id — the exact state that stranded an admin.
        $this->assertSame($expected, $this->resolver()->companyId($this->request()));
    }

    public function test_saas_still_refuses_rather_than_guessing(): void
    {
        config(['edition.edition' => 'saas']);

        // On SaaS the same state is genuinely ambiguous, and guessing would be the cross-tenant
        // leak the whole trait exists to prevent.
        $this->assertNull($this->resolver()->companyId($this->request()));
    }

    /**
     * Two companies that are both in use is still a choice nobody may make silently.
     *
     * This is the original guarantee and it has not moved. What has moved is what counts as "two":
     * see the test below.
     */
    public function test_two_active_companies_are_ambiguous_even_when_self_hosted(): void
    {
        config(['edition.edition' => 'self_hosted']);

        DB::table('companies')->update(['status' => 'active']);
        DB::table('companies')->insert([
            'uuid' => (string) Str::uuid(), 'slug' => 'second-'.Str::random(8),
            'name' => 'Second Company', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if (DB::table('companies')->where('status', 'active')->count() < 2) {
            $this->markTestSkipped('Could not create a second active company.');
        }

        // Two live answers is no answer. Guessing here is the cross-tenant leak the trait exists
        // to prevent.
        $this->assertNull($this->resolver()->companyId($this->request()));
    }

    /**
     * A leftover is not a second company.
     *
     * "Exactly one row" was the original rule and it was too literal. A standalone install
     * accumulates companies that are not the customer — a trial from evaluating the product, a
     * `test` from setup — and refusing to resolve because of them sent the administrator to
     * "view as company", a multi-tenant control that means nothing on a single-tenant box.
     *
     * The edition is what makes this safe rather than a guess: self-hosted is one customer by
     * definition, so choosing between their own rows is not choosing between tenants. On SaaS
     * none of this applies and the refusal above is unchanged.
     */
    public function test_a_leftover_trial_does_not_make_a_standalone_install_ambiguous(): void
    {
        config(['edition.edition' => 'self_hosted']);

        $active = (int) DB::table('companies')->min('id');
        DB::table('companies')->where('id', $active)->update(['status' => 'active']);

        DB::table('companies')->insert([
            'uuid' => (string) Str::uuid(), 'slug' => 'leftover-'.Str::random(8),
            'name' => 'Left over from setup', 'status' => 'trial',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(
            $active,
            $this->resolver()->companyId($this->request()),
            'A standalone install could not name the one company it is actually being used as.'
        );
    }

    public function test_a_users_own_company_still_wins_on_self_hosted(): void
    {
        config(['edition.edition' => 'self_hosted']);

        $companyId = (int) DB::table('companies')->min('id');

        $user = (object) ['company_id' => $companyId, 'is_platform_admin' => false];

        $this->assertSame($companyId, $this->resolver()->companyId($this->request($user)));
    }

    public function test_the_refusal_does_not_send_a_self_hosted_admin_to_a_menu_that_does_not_exist(): void
    {
        $resolver = $this->resolver();
        $admin = (object) ['company_id' => null, 'is_platform_admin' => true];

        config(['edition.edition' => 'saas']);
        $saas = $resolver->noCompanyMessage($this->request($admin));

        config(['edition.edition' => 'self_hosted']);
        $selfHosted = $resolver->noCompanyMessage($this->request($admin));

        $this->assertStringContainsString('View as company', $saas);
        // The advice that would send them hunting for a tenant switcher they do not have.
        $this->assertStringNotContainsString('View as company', $selfHosted);
        $this->assertStringContainsString('create one', $selfHosted);
    }

    public function test_the_front_end_is_told_which_edition_it_is(): void
    {
        // The notice component picks its wording from this; without it the screen would keep
        // showing SaaS advice regardless of the build.
        $request = $this->request();

        // share() reads flash state, so it needs a session store attached — the real request
        // always has one.
        $request->setLaravelSession(app('session.store'));

        $shared = (new HandleInertiaRequests)->share($request);

        $this->assertArrayHasKey('edition', $shared);
        $this->assertContains($shared['edition'], ['saas', 'self_hosted']);
    }

    public function test_procurement_governance_resolves_the_same_way(): void
    {
        if (DB::table('companies')->count() !== 1) {
            $this->markTestSkipped('This database holds more than one company.');
        }

        config(['edition.edition' => 'self_hosted']);

        $expected = (int) DB::table('companies')->value('id');

        $this->assertSame($expected,
            $this->procurementResolver()->companyId($this->request()));
    }

    public function test_procurement_governance_refuses_on_saas_like_sheq(): void
    {
        config(['edition.edition' => 'saas']);

        $this->assertNull($this->procurementResolver()->companyId($this->request()));
    }

    public function test_both_modules_share_one_implementation(): void
    {
        // If these ever diverge again, one module gets a fix and the other silently does not.
        foreach ([
            ResolvesCompany::class,
            \App\Modules\Procurement\Governance\Support\ResolvesCompany::class,
        ] as $trait) {
            $this->assertContains(
                ResolvesTenant::class,
                class_uses($trait),
                "{$trait} no longer shares the tenant-resolution implementation."
            );
        }
    }
}
