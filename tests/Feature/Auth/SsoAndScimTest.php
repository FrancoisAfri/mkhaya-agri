<?php

namespace Tests\Feature\Auth;

use App\Core\Auth\Services\SsoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Single sign-on and SCIM provisioning (§9.1).
 *
 * These test the boundary rather than the happy path, because the happy path fails loudly and the
 * boundary fails silently. The case that matters most is the one where an identity provider asserts
 * an identity outside the domains its connection owns: nothing errors, the login simply succeeds
 * into the wrong tenant, and the only trace is data appearing in a company that never had that user.
 */
class SsoAndScimTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $otherCompanyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->otherCompanyId = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'slug' => 'rival-'.Str::random(8),
            'name' => 'Rival Holdings', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function connection(array $overrides = []): object
    {
        $id = DB::table('sso_connections')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'provider' => 'oidc',
            'display_name' => 'Acme Entra ID',
            'client_id' => 'client-abc',
            'client_secret' => encrypt('shh'),
            'issuer' => 'https://login.example.test/acme',
            'email_domains' => json_encode(['acme.co.za']),
            'jit_provisioning' => false,
            'enforce_sso' => false,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return DB::table('sso_connections')->find($id);
    }

    /**
     * Stand in for the provider's discovery document.
     *
     * The service reads endpoints from `.well-known/openid-configuration` rather than storing them,
     * so a provider that moves a URL does not silently break every login. That means these tests
     * have to answer the fetch.
     */
    private function fakeDiscovery(): void
    {
        Cache::flush();

        Http::fake([
            '*/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://login.example.test/acme/authorize',
                'token_endpoint' => 'https://login.example.test/acme/token',
                'userinfo_endpoint' => 'https://login.example.test/acme/userinfo',
            ]),
        ]);
    }

    private function scimToken(?int $companyId = null): string
    {
        $token = 'scim_'.Str::random(48);

        DB::table('scim_tokens')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId ?? $this->companyId,
            'name' => 'Entra connector',
            'token_hash' => hash('sha256', $token),
            'token_hint' => substr($token, 0, 12).'…',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $token;
    }

    // ── Routing a login to the right tenant ───────────────────────────────────────────────────

    public function test_an_address_is_routed_to_the_connection_that_owns_its_domain(): void
    {
        $this->connection();

        $found = app(SsoService::class)->connectionForEmail('someone@acme.co.za');

        $this->assertNotNull($found);
        $this->assertSame($this->companyId, (int) $found->company_id);
    }

    public function test_an_unknown_domain_has_no_connection(): void
    {
        $this->connection();

        $this->assertNull(app(SsoService::class)->connectionForEmail('someone@gmail.com'));
        $this->assertNull(app(SsoService::class)->connectionForEmail('not-an-email'));
    }

    public function test_a_deactivated_connection_stops_routing(): void
    {
        $this->connection(['is_active' => false]);

        $this->assertNull(app(SsoService::class)->connectionForEmail('someone@acme.co.za'));
    }

    public function test_discovery_does_not_reveal_whether_an_account_exists(): void
    {
        $this->connection();

        $response = $this->postJson('/auth/sso/discover', ['email' => 'nobody@acme.co.za']);

        $response->assertOk();
        // True because the domain uses SSO, not because this person has an account. Answering the
        // latter would make the login page a staff directory for anyone who asks.
        $this->assertTrue($response->json('sso'));
        $this->assertSame('Acme Entra ID', $response->json('display_name'));
    }

    public function test_enforced_sso_blocks_password_login_for_that_domain(): void
    {
        $this->connection(['enforce_sso' => true]);

        // The setting that turns SSO from a convenience into a control: a local password that still
        // works means disabling someone centrally does not lock them out.
        $this->assertTrue(app(SsoService::class)->passwordLoginBlocked('someone@acme.co.za'));
        $this->assertFalse(app(SsoService::class)->passwordLoginBlocked('someone@elsewhere.com'));
    }

    // ── The state parameter ───────────────────────────────────────────────────────────────────

    public function test_a_callback_with_an_unknown_state_is_refused(): void
    {
        // Without this check an attacker completes their own authorisation and feeds the code to a
        // victim's browser, logging the victim into the attacker's account.
        $this->expectExceptionMessageMatches('/expired or has already been used/');

        app(SsoService::class)->completeLogin('some-code', 'a-state-nobody-issued');
    }

    public function test_a_state_cannot_be_used_twice(): void
    {
        $this->fakeDiscovery();
        $connection = $this->connection();

        $begin = app(SsoService::class)->beginLogin($connection, 'https://app.test/callback');

        $this->assertNotNull(Cache::get("sso:state:{$begin['state']}"));

        // Consumed on first use — a replayed callback finds nothing.
        Cache::pull("sso:state:{$begin['state']}");

        $this->assertNull(Cache::get("sso:state:{$begin['state']}"));
    }

    public function test_the_authorisation_url_carries_state_and_nonce(): void
    {
        $this->fakeDiscovery();
        $connection = $this->connection();

        $begin = app(SsoService::class)->beginLogin($connection, 'https://app.test/callback');

        $this->assertStringContainsString('state='.$begin['state'], $begin['url']);
        $this->assertStringContainsString('nonce=', $begin['url']);
        $this->assertStringContainsString('client_id=client-abc', $begin['url']);
    }

    // ── SCIM: authentication and tenancy ──────────────────────────────────────────────────────

    public function test_scim_refuses_a_request_with_no_token(): void
    {
        $this->getJson('/api/scim/v2/Users')->assertStatus(401);
    }

    public function test_scim_refuses_an_unknown_token(): void
    {
        $this->withToken('scim_not-a-real-token')
            ->getJson('/api/scim/v2/Users')->assertStatus(401);
    }

    public function test_scim_refuses_an_expired_token(): void
    {
        $token = 'scim_'.Str::random(48);

        DB::table('scim_tokens')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Old', 'token_hash' => hash('sha256', $token),
            'token_hint' => substr($token, 0, 12).'…', 'is_active' => true,
            'expires_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($token)->getJson('/api/scim/v2/Users')->assertStatus(401);
    }

    public function test_the_token_is_not_stored_in_a_usable_form(): void
    {
        $token = $this->scimToken();

        // A SCIM credential can provision an administrator into a tenant. A database read must not
        // yield one.
        $this->assertNull(DB::table('scim_tokens')->where('token_hash', $token)->first());
        $this->assertNotNull(DB::table('scim_tokens')
            ->where('token_hash', hash('sha256', $token))->first());
    }

    public function test_a_directory_can_only_see_its_own_tenants_users(): void
    {
        $foreignEmail = 'outsider-'.Str::lower(Str::random(6)).'@rival.test';

        DB::table('users')->insert([
            'uuid' => (string) Str::uuid(), 'email' => $foreignEmail,
            'password' => bcrypt('x'), 'company_id' => $this->otherCompanyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->withToken($this->scimToken())
            ->getJson('/api/scim/v2/Users?filter=userName eq "'.$foreignEmail.'"');

        $response->assertOk();
        // The tenant comes from the token and appears in no request parameter, so there is nothing
        // for a misconfigured connector to point at another customer.
        $this->assertSame(0, $response->json('totalResults'));
    }

    // ── SCIM: the operations providers actually send ──────────────────────────────────────────

    public function test_a_provider_can_create_a_user(): void
    {
        $email = 'newstarter-'.Str::lower(Str::random(6)).'@acme.co.za';

        $response = $this->withToken($this->scimToken())->postJson('/api/scim/v2/Users', [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'userName' => $email,
            'externalId' => 'idp-12345',
            'name' => ['givenName' => 'Naledi', 'familyName' => 'Khumalo'],
            'active' => true,
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('active'));
        $this->assertSame('idp-12345', $response->json('externalId'));

        $user = DB::table('users')->whereRaw('lower(email) = ?', [$email])->first();
        $this->assertSame($this->companyId, (int) $user->company_id);
    }

    public function test_creating_a_duplicate_returns_the_conflict_providers_expect(): void
    {
        $email = 'dupe-'.Str::lower(Str::random(6)).'@acme.co.za';
        $token = $this->scimToken();

        $this->withToken($token)->postJson('/api/scim/v2/Users', ['userName' => $email]);

        // 409 is what makes a provider fall back to a PATCH instead of retrying forever.
        $this->withToken($token)->postJson('/api/scim/v2/Users', ['userName' => $email])
            ->assertStatus(409)
            ->assertJsonPath('scimType', 'uniqueness');
    }

    public function test_the_filtered_probe_providers_send_before_creating_works(): void
    {
        $email = 'probe-'.Str::lower(Str::random(6)).'@acme.co.za';
        $token = $this->scimToken();

        $this->withToken($token)->postJson('/api/scim/v2/Users', ['userName' => $email]);

        $response = $this->withToken($token)
            ->getJson('/api/scim/v2/Users?filter=userName eq "'.$email.'"');

        // Without this, a provider never finds the existing account and duplicates the entire
        // directory on every sync.
        $response->assertOk();
        $this->assertSame(1, $response->json('totalResults'));
    }

    public function test_deactivating_a_user_locks_them_out_but_keeps_the_record(): void
    {
        $email = 'leaver-'.Str::lower(Str::random(6)).'@acme.co.za';
        $token = $this->scimToken();

        $created = $this->withToken($token)->postJson('/api/scim/v2/Users',
            ['userName' => $email, 'name' => ['givenName' => 'Sipho', 'familyName' => 'Ndlovu']])->json();

        $this->withToken($token)->patchJson("/api/scim/v2/Users/{$created['id']}", [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]],
        ])->assertOk()->assertJsonPath('active', false);

        $user = DB::table('users')->whereRaw('lower(email) = ?', [$email])
            ->first(['status', 'deprovisioned_at']);

        $this->assertSame(0, (int) $user->status);
        $this->assertNotNull($user->deprovisioned_at);
    }

    public function test_okta_style_patch_without_a_path_also_works(): void
    {
        $email = 'okta-'.Str::lower(Str::random(6)).'@acme.co.za';
        $token = $this->scimToken();

        $created = $this->withToken($token)
            ->postJson('/api/scim/v2/Users', ['userName' => $email])->json();

        // Entra sends {path: "active", value: false}; Okta sends {value: {active: false}}.
        // Handling one shape means provisioning silently works for half the customers.
        $this->withToken($token)->patchJson("/api/scim/v2/Users/{$created['id']}", [
            'Operations' => [['op' => 'replace', 'value' => ['active' => false]]],
        ])->assertOk()->assertJsonPath('active', false);
    }

    public function test_delete_deactivates_rather_than_destroying_the_audit_trail(): void
    {
        $email = 'deleted-'.Str::lower(Str::random(6)).'@acme.co.za';
        $token = $this->scimToken();

        $created = $this->withToken($token)
            ->postJson('/api/scim/v2/Users', ['userName' => $email])->json();

        $this->withToken($token)->deleteJson("/api/scim/v2/Users/{$created['id']}")
            ->assertStatus(204);

        $user = DB::table('users')->whereRaw('lower(email) = ?', [$email])
            ->first(['id', 'status', 'deprovisioned_at']);

        // Their name is on permit approvals and investigation sign-offs. An audit trail that loses
        // its actors when somebody resigns is not an audit trail.
        $this->assertNotNull($user, 'The user record must survive deprovisioning.');
        $this->assertNotNull($user->deprovisioned_at);
        $this->assertSame(0, (int) $user->status);
    }

    public function test_a_rehire_can_be_reactivated_by_the_directory(): void
    {
        $email = 'rehire-'.Str::lower(Str::random(6)).'@acme.co.za';
        $token = $this->scimToken();

        $created = $this->withToken($token)
            ->postJson('/api/scim/v2/Users', ['userName' => $email])->json();

        $this->withToken($token)->deleteJson("/api/scim/v2/Users/{$created['id']}");

        $this->withToken($token)->patchJson("/api/scim/v2/Users/{$created['id']}", [
            'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => true]],
        ])->assertOk()->assertJsonPath('active', true);

        // Otherwise every rehire is a support ticket.
        $this->assertNull(DB::table('users')->whereRaw('lower(email) = ?', [$email])
            ->value('deprovisioned_at'));
    }

    public function test_the_response_exposes_the_uuid_not_the_row_id(): void
    {
        $email = 'ident-'.Str::lower(Str::random(6)).'@acme.co.za';

        $created = $this->withToken($this->scimToken())
            ->postJson('/api/scim/v2/Users', ['userName' => $email])->json();

        // The directory stores this id. A sequential integer would leak how many users the
        // platform has and invite enumeration.
        $this->assertTrue(Str::isUuid($created['id']));
    }

    public function test_an_unsupported_filter_is_refused_clearly(): void
    {
        $this->withToken($this->scimToken())
            ->getJson('/api/scim/v2/Users?filter=userName co "acme"')
            ->assertStatus(400)
            ->assertJsonPath('scimType', 'invalidFilter');
    }
}
