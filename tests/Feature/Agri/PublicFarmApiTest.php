<?php

namespace Tests\Feature\Agri;

use App\Core\User\Models\User;
use App\Modules\Agri\Api\ApiTokens;
use App\Modules\Agri\Api\Webhooks;
use App\Modules\Agri\Models\ApiCall;
use App\Modules\Agri\Models\ApiClient;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Agri\Services\Integrations;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The public farm API (v1): tokens issued with abilities, an expiry and farms, acting as their own
 * service user; every refusal a problem document; cursor paging and updated_since; idempotent
 * POSTs; signed, retried webhooks; the OpenAPI document; the call log and the screens.
 */
class PublicFarmApiTest extends AgriTestCase
{
    private Farm $north;

    private Farm $south;

    protected function setUp(): void
    {
        parent::setUp();
        $this->north = $this->farm('NORTH');
        $this->south = $this->farm('SOUTH');
    }

    /** @return array{0: string, 1: ApiClient} */
    private function issue(array $abilities, ?array $farms = null, array $extra = [], ?User $as = null): array
    {
        $as ??= $this->admin;
        $this->actingAs($as);
        app(FarmAccess::class)->flush();
        $r = $this->asCompany($this->company, fn () => app(ApiTokens::class)->issue($this->company->id, ['name' => 'Agronomy app', 'abilities' => $abilities,
            'farm_ids' => $farms ? array_map(fn (Farm $f) => $f->uuid, $farms) : null, 'expires_in_days' => 30] + $extra, $as));
        app(FarmAccess::class)->flush();

        return [$r['token'], $r['client']];
    }

    /** A call as a machine would make it: no session, only the bearer token. */
    private function api(string $token, string $method, string $uri, array $body = [], array $headers = [])
    {
        $this->app['auth']->forgetGuards();
        app(FarmAccess::class)->flush();
        $this->flushHeaders();

        return $this->withHeaders($headers + ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->json($method, '/api/agri/v1'.$uri, $body);
    }

    public function test_abilities_are_enforced_and_errors_are_problem_documents(): void
    {
        [$token, $client] = $this->issue(['farms:read']);

        $r = $this->api($token, 'GET', '/farms')->assertOk();
        $this->assertEqualsCanonicalizing(['NORTH', 'SOUTH'], array_column($r->json('data'), 'code'));
        $this->assertNotNull($r->headers->get('X-RateLimit-Limit'));

        $me = $this->api($token, 'GET', '/me')->assertOk();
        $this->assertSame(['farms:read'], $me->json('data.abilities'));
        $this->assertStringEndsWith('@agri-api.invalid', $me->json('data.service_user'));

        // No crops:read: refused, as a problem document naming the missing ability.
        $this->api($token, 'GET', '/crop-plans')->assertStatus(403)->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://mkhaya.app/problems/missing-ability')->assertJsonPath('ability', 'crops:read');
        $this->api($token, 'POST', '/field-activities', ['farm' => $this->north->uuid])->assertStatus(403);

        // The token acts as its own user, with only the permissions its abilities need.
        $user = User::find($client->service_user_id);
        $this->assertTrue($user->can(\App\Modules\Agri\Support\RoleCatalog::VIEW_FARMS));
        $this->assertFalse($user->can(\App\Modules\Agri\Support\RoleCatalog::RECORD_ACTIVITIES));

        // No token at all: a 401 problem, not a redirect to a login page.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->get('/api/agri/v1/farms')->assertStatus(401)->assertJsonPath('title', 'Unauthenticated');
        // A web session is not an API token.
        $this->actingAs($this->admin)->getJson('/api/agri/v1/farms')->assertStatus(401);
    }

    public function test_an_expired_or_revoked_token_is_refused(): void
    {
        [$token, $client] = $this->issue(['farms:read']);
        $this->api($token, 'GET', '/farms')->assertOk();

        $this->travel(31)->days();
        $this->api($token, 'GET', '/farms')->assertStatus(401);
        $this->travelBack();
        $this->api($token, 'GET', '/farms')->assertOk();

        $this->asCompany($this->company, fn () => app(ApiTokens::class)->revoke($client, $this->admin));
        $this->api($token, 'GET', '/farms')->assertStatus(401);
        $this->assertSame('revoked', $client->fresh()->state());
        $this->assertSame(0, (int) User::find($client->service_user_id)->status);
    }

    public function test_another_organisations_and_another_farms_data_is_unreachable(): void
    {
        $other = Company::create(['name' => 'Elders Boerdery']);
        $this->entitle($other);
        $theirs = $this->farm('THEIRS', $other);

        [$token] = $this->issue(['farms:read']);
        $this->assertNotContains('THEIRS', array_column($this->api($token, 'GET', '/farms')->json('data'), 'code'));
        $this->api($token, 'GET', '/farms/'.$theirs->uuid)->assertStatus(404)->assertHeader('Content-Type', 'application/problem+json');
        $this->api($token, 'GET', '/farms/not-a-uuid')->assertStatus(404);
        $this->api($token, 'GET', '/farms/'.$this->north->uuid)->assertOk()->assertJsonPath('data.code', 'NORTH');

        // A token for one farm sees only that farm, and the other farm's fields are not there.
        $this->field($this->south, 'S1');
        [$northOnly] = $this->issue(['farms:read'], [$this->north]);
        $this->assertSame(['NORTH'], array_column($this->api($northOnly, 'GET', '/farms')->json('data'), 'code'));
        $this->api($northOnly, 'GET', '/farms/'.$this->south->uuid)->assertStatus(404);
        $this->assertSame([], $this->api($northOnly, 'GET', '/fields')->json('data'));

        // Nobody issues more than they hold: a manager confined to NORTH cannot make an every-farm token.
        $this->member($this->manager, $this->north);
        $this->flushHeaders();
        $this->actingAs($this->manager)->post(route('agri.api.tokens.store'), ['name' => 'Wide', 'abilities' => ['farms:read'], 'expires_in_days' => 30])
            ->assertSessionHasErrors('farm_ids');
    }

    public function test_cursor_paging_updated_since_and_geojson(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $code) {
            $this->field($this->north, $code);
        }
        $this->asCompany($this->company, fn () => FarmField::withoutGlobalScopes()->where('code', 'A')->update(['boundary' => json_encode(['type' => 'Polygon',
            'coordinates' => [[[28.30, -28.22], [28.31, -28.22], [28.31, -28.23], [28.30, -28.22]]]])]));
        [$token] = $this->issue(['farms:read']);

        $page1 = $this->api($token, 'GET', '/fields?limit=2')->assertOk();
        $this->assertSame(['A', 'B'], array_column($page1->json('data'), 'code'));
        $this->assertTrue($page1->json('meta.has_more'));
        $this->assertSame('Polygon', $page1->json('data.0.boundary.type'));
        $page2 = $this->api($token, 'GET', '/fields?limit=2&cursor='.$page1->json('meta.next_cursor'))->assertOk();
        $this->assertSame(['C', 'D'], array_column($page2->json('data'), 'code'));
        $page3 = $this->api($token, 'GET', '/fields?limit=2&cursor='.$page2->json('meta.next_cursor'))->assertOk();
        $this->assertSame(['E'], array_column($page3->json('data'), 'code'));
        $this->assertNull($page3->json('meta.next_cursor'));
        $this->api($token, 'GET', '/fields?cursor=rubbish')->assertStatus(400);

        // Only what changed since the last sync.
        $this->travel(2)->hours();
        $since = now()->subMinute()->utc()->toIso8601ZuluString();
        $this->asCompany($this->company, fn () => FarmField::withoutGlobalScopes()->where('code', 'D')->first()->update(['notes' => 'Re-fenced']));
        $this->assertSame(['D'], array_column($this->api($token, 'GET', '/fields?updated_since='.urlencode($since))->json('data'), 'code'));
        $this->api($token, 'GET', '/fields?updated_since=yesterday-ish')->assertStatus(400);

        $geo = $this->api($token, 'GET', '/fields?format=geojson&limit=1')->assertOk();
        $this->assertSame('FeatureCollection', $geo->json('type'));
        $this->assertSame('Polygon', $geo->json('features.0.geometry.type'));
        $this->assertSame('A', $geo->json('features.0.properties.code'));
    }

    public function test_a_post_with_an_idempotency_key_is_done_once(): void
    {
        $field = $this->field($this->north, 'L1');
        [$token] = $this->issue(['farms:read', 'crops:read', 'activities:write']);
        $body = ['farm' => $this->north->uuid, 'field' => $field->uuid, 'activity_type' => 'spraying', 'title' => 'Post-emergence herbicide', 'planned_on' => today()->addDay()->toDateString()];

        $first = $this->api($token, 'POST', '/field-activities', $body, ['Idempotency-Key' => 'spray-L1-0001'])->assertCreated();
        $again = $this->api($token, 'POST', '/field-activities', $body, ['Idempotency-Key' => 'spray-L1-0001'])->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, FieldActivity::withoutGlobalScopes()->where('title', 'Post-emergence herbicide')->count());
        $this->assertSame($this->north->uuid, $first->json('data.farm'));
        $this->assertSame($field->uuid, $first->json('data.field'));

        // Same key, another body: refused. A validation failure is a problem document with the fields.
        $this->api($token, 'POST', '/field-activities', ['title' => 'Other'] + $body, ['Idempotency-Key' => 'spray-L1-0001'])->assertStatus(422)
            ->assertJsonPath('type', 'https://mkhaya.app/problems/idempotency-key-reused');
        $this->api($token, 'POST', '/field-activities', ['farm' => $this->north->uuid])->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')->assertJsonStructure(['errors' => ['activity_type', 'title', 'planned_on']]);

        // The activity was made by the integration's user, so the audit names it.
        $activity = FieldActivity::withoutGlobalScopes()->where('uuid', $first->json('data.id'))->first();
        $this->assertSame((int) ApiClient::withoutGlobalScopes()->first()->service_user_id, (int) $activity->created_by);
        $this->assertSame(1, $this->api($token, 'GET', '/field-activities?field='.$field->uuid)->json('meta.count'));
    }

    public function test_animal_events_and_job_status_go_through_the_same_rules_as_the_screens(): void
    {
        $this->catalogue();
        $this->member($this->manager, $this->north);
        $this->actingAs($this->manager)->post(route('agri.livestock.animals.store'), ['farm_id' => $this->north->uuid, 'species_id' => $this->speciesId('CATTLE'),
            'tag_number' => 'ZA-1001', 'sex' => 'female', 'acquisition' => 'born', 'birth_date' => today()->subYears(3)->toDateString()])->assertSessionHasNoErrors();
        $cow = \App\Modules\Agri\Models\Animal::withoutGlobalScopes()->where('tag_number', 'ZA-1001')->firstOrFail();
        $this->actingAs($this->manager)->post(route('agri.work-orders.store'), ['farm_id' => $this->north->uuid, 'category' => 'spraying', 'title' => 'Fungicide on L1',
            'priority' => 'high', 'planned_start' => now()->addHour()->toDateTimeString(), 'planned_end' => now()->addHours(6)->toDateTimeString()])->assertSessionHasNoErrors();
        $job = \App\Modules\Agri\Models\FarmWorkOrder::withoutGlobalScopes()->latest('id')->firstOrFail();

        [$token] = $this->issue(['farms:read', 'livestock:read', 'livestock:write', 'work_orders:read', 'work_orders:write']);
        $e = $this->api($token, 'POST', '/animal-events', ['animal' => $cow->uuid, 'event_type' => 'pregnancy_check', 'occurred_on' => today()->toDateString(), 'outcome' => 'pregnant'])
            ->assertCreated();
        $this->assertSame($cow->uuid, $e->json('data.animal'));
        $this->assertSame('pregnant', $e->json('data.outcome'));
        $this->api($token, 'POST', '/animal-events', ['animal' => $cow->uuid, 'event_type' => 'exit', 'occurred_on' => today()->toDateString()])->assertStatus(422);
        $this->assertSame(1, $this->api($token, 'GET', '/animal-events?event_type=pregnancy_check&animal='.$cow->uuid)->json('meta.count'));

        // Approving needs an approver: work_orders:write alone may not; cancelling it may.
        $this->api($token, 'POST', '/work-orders/'.$job->uuid.'/status', ['to' => 'approved'])->assertStatus(403);
        $this->api($token, 'POST', '/work-orders/'.$job->uuid.'/status', ['to' => 'completed'])->assertStatus(403);
        $this->api($token, 'POST', '/work-orders/'.$job->uuid.'/status', ['to' => 'cancelled', 'reason' => 'Rain all week'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->api($token, 'POST', '/work-orders/'.$job->uuid.'/status', ['to' => 'in_progress'])->assertStatus(422);
        $this->api($token, 'GET', '/work-orders/'.$job->uuid)->assertOk()->assertJsonPath('data.reference', $job->reference);
    }

    public function test_webhooks_are_signed_and_retried(): void
    {
        [$token] = $this->issue(['farms:read', 'webhooks:manage']);
        $sub = $this->api($token, 'POST', '/webhooks', ['name' => 'Packhouse ERP', 'url' => 'https://erp.example.co.za/hooks', 'events' => ['alert.raised']])->assertCreated();
        $secret = $sub->json('data.secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertCount(1, $this->api($token, 'GET', '/webhooks')->json('data'));
        $this->api($token, 'POST', '/webhooks', ['name' => 'Bad', 'url' => 'https://x.example', 'events' => ['nope']])->assertStatus(422);

        Http::fake(['erp.example.co.za/*' => Http::sequence()->push('down', 503)->push(['ok' => true], 200)]);
        $this->asCompany($this->company, fn () => FarmAlert::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'farm_id' => $this->north->id,
            'kind' => 'low_stock', 'severity' => 'warning', 'title' => 'Urea low', 'message' => 'Urea below reorder level', 'dedupe_key' => 'low:urea', 'status' => 'open', 'last_seen_at' => now()]));

        $event = IntegrationEvent::withoutGlobalScopes()->where('integration', 'webhook')->firstOrFail();
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('503', (string) $event->error);

        $this->travel(10)->minutes();
        $this->asCompany($this->company, fn () => app(Integrations::class)->retryDue($this->company->id));
        $this->assertSame('processed', $event->fresh()->status);

        Http::assertSentCount(2);
        Http::assertSent(function (HttpRequest $r) use ($secret) {
            $body = $r->body();

            return $r->url() === 'https://erp.example.co.za/hooks' && $r->hasHeader('X-Mkhaya-Event', 'alert.raised')
                && Webhooks::verify($secret, $body, $r->header('X-Mkhaya-Signature')[0])
                && json_decode($body, true)['data']['title'] === 'Urea low' && json_decode($body, true)['data']['farm'] === $this->north->uuid;
        });
        // A tampered body does not verify.
        $this->assertFalse(Webhooks::verify($secret, '{"forged":true}', 't='.time().',v1=abc'));
        $this->api($token, 'DELETE', '/webhooks/'.$sub->json('data.id'))->assertNoContent();
    }

    public function test_the_openapi_document_is_valid_and_lists_every_route(): void
    {
        $r = $this->getJson('/api/agri/v1/openapi.json')->assertOk();
        $spec = json_decode($r->getContent(), true);
        $this->assertIsArray($spec);
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('bearer', $spec['components']['securitySchemes']);
        $this->assertArrayHasKey('harvest.recorded', $spec['webhooks']);

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($rt) => str_starts_with((string) $rt->getName(), 'api.agri.v1.'));
        $this->assertGreaterThan(40, $routes->count());
        foreach ($routes as $rt) {
            $path = '/'.ltrim(substr($rt->uri(), strlen('api/agri/v1')), '/');
            $method = strtolower(collect($rt->methods())->first(fn ($m) => $m !== 'HEAD'));
            $this->assertArrayHasKey($path, $spec['paths'], "{$rt->getName()} is missing from the OpenAPI document.");
            $this->assertArrayHasKey($method, $spec['paths'][$path], "{$method} {$path} is missing from the OpenAPI document.");
            $this->assertArrayNotHasKey('x-undocumented', $spec['paths'][$path][$method], "{$method} {$path} has no description.");
            // Every $ref resolves.
            array_walk_recursive($spec['paths'][$path][$method], function ($v, $k) use ($spec) {
                if ($k === '$ref') {
                    $this->assertNotNull(data_get($spec, str_replace('/', '.', substr($v, 2))), "Dangling {$v}");
                }
            });
        }
    }

    public function test_every_call_is_logged_and_the_limit_is_per_token(): void
    {
        [$token, $client] = $this->issue(['farms:read'], null, ['rate_per_minute' => 10]);
        for ($i = 0; $i < 10; $i++) {
            $this->api($token, 'GET', '/farms')->assertOk();
        }
        $this->api($token, 'GET', '/farms')->assertStatus(429)->assertHeader('Retry-After');
        $this->api($token, 'GET', '/crop-plans')->assertStatus(429);

        $calls = ApiCall::withoutGlobalScopes()->where('client_id', $client->id)->get();
        $this->assertCount(12, $calls);
        $this->assertSame(10, $calls->where('status', 200)->count());
        $this->assertSame('api.agri.v1.farms.index', $calls->first()->route);
        $this->assertSame((int) $client->service_user_id, (int) $calls->first()->user_id);
        $this->assertNotNull($client->fresh()->last_used_at);

        // Another token has its own allowance.
        [$other] = $this->issue(['farms:read']);
        $this->api($other, 'GET', '/farms')->assertOk();

        $this->flushHeaders();
        $this->actingAs($this->admin)->get(route('agri.api.index', ['tab' => 'calls']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Api/Index')->where('tab', 'calls')->has('data.calls', 13));
    }

    public function test_the_screens_render_and_refuse_without_permission(): void
    {
        $this->actingAs($this->admin)->post(route('agri.api.tokens.store'), ['name' => 'Packhouse ERP', 'abilities' => ['farms:read', 'harvests:read'], 'expires_in_days' => 60])
            ->assertRedirect()->assertSessionHas('agri_api_reveal');
        $this->actingAs($this->admin)->get(route('agri.api.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Api/Index')->has('data.clients', 1)->where('data.clients.0.state', 'active')
                ->where('data.clients.0.abilities', ['farms:read', 'harvests:read']));
        $this->actingAs($this->admin)->post(route('agri.api.webhooks.store'), ['name' => 'Co-op', 'url' => 'https://coop.example/hook', 'events' => ['harvest.recorded', 'lot.dispatched']])
            ->assertRedirect();
        $this->actingAs($this->admin)->get(route('agri.api.index', ['tab' => 'webhooks']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Api/Index')->has('data.subscriptions', 1));
        $this->actingAs($this->admin)->get(route('agri.api.docs'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Modules/Agri/Api/Docs')->where('spec.openapi', '3.1.0'));

        $client = ApiClient::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->admin)->post(route('agri.api.tokens.revoke', $client->uuid))->assertRedirect();
        $this->assertNotNull($client->fresh()->revoked_at);

        foreach ([$this->outsider, $this->worker] as $who) {
            $this->actingAs($who)->get(route('agri.api.index'))->assertForbidden();
            $this->actingAs($who)->get(route('agri.api.docs'))->assertForbidden();
            $this->actingAs($who)->post(route('agri.api.tokens.store'), ['name' => 'x', 'abilities' => ['farms:read'], 'expires_in_days' => 1])->assertForbidden();
        }
        // A token cannot be given an ability its issuer does not hold.
        $this->member($this->supervisor, $this->north);
        $this->actingAs($this->supervisor)->post(route('agri.api.tokens.store'), ['name' => 'x', 'abilities' => ['farms:read'], 'expires_in_days' => 1])->assertForbidden();
        $this->supervisor->givePermissionTo(\App\Modules\Agri\Support\RoleCatalog::MANAGE_API);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->supervisor->fresh())->post(route('agri.api.tokens.store'), ['name' => 'x', 'abilities' => ['finance:read'], 'farm_ids' => [$this->north->uuid], 'expires_in_days' => 1])
            ->assertSessionHasErrors('abilities');
        $this->actingAs($this->supervisor->fresh())->post(route('agri.api.tokens.store'), ['name' => 'Scout app', 'abilities' => ['farms:read'], 'farm_ids' => [$this->north->uuid], 'expires_in_days' => 1])
            ->assertSessionHasNoErrors();
    }
}
