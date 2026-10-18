<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Support\Api\OpenApiGenerator;
use App\Modules\Tenancy\Models\Company;
use Laravel\Sanctum\Sanctum;

/**
 * The published contract (EPIC-070).
 *
 * The point of these tests is drift. A hand-written spec is accurate the day it is written and
 * wrong by the end of the month, and the failure is silent: an integrator builds against a document
 * describing an endpoint that no longer behaves that way. Adding a route without documenting it
 * fails here, which is the only mechanism that reliably keeps a spec true.
 */
class OpenApiSpecTest extends ProjectManagementTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The API stack refuses a request with no tenant, and the tenant's plan has to include the
        // module — the same two gates every other endpoint sits behind.
        $company = Company::create(['name' => 'Alpha Construction']);
        $this->entitleCompany($company);
        $this->manager->forceFill(['company_id' => $company->id])->save();
        $this->manager = $this->manager->fresh();
    }

    public function test_every_api_route_is_described(): void
    {
        $undocumented = app(OpenApiGenerator::class)->undocumented();

        $this->assertSame(
            [],
            $undocumented,
            "These API routes have no description in OpenApiGenerator::DESCRIBED:\n  "
            . implode("\n  ", $undocumented)
            . "\n\nAn undocumented public endpoint is one an integrator will use anyway, guessing."
        );
    }

    public function test_the_document_covers_every_route_and_method(): void
    {
        $spec = app(OpenApiGenerator::class)->generate();

        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'api.pm.'));

        foreach ($routes as $route) {
            $path = '/' . ltrim($route->uri(), '/');

            $this->assertArrayHasKey($path, $spec['paths'], "{$path} is missing from the spec.");

            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $this->assertArrayHasKey(
                    strtolower($method),
                    $spec['paths'][$path],
                    "{$method} {$path} is missing from the spec."
                );
            }
        }
    }

    public function test_operation_ids_are_unique(): void
    {
        $spec = app(OpenApiGenerator::class)->generate();
        $ids = [];

        foreach ($spec['paths'] as $operations) {
            foreach ($operations as $operation) {
                $ids[] = $operation['operationId'];
            }
        }

        // Generators build client method names from these; a duplicate silently drops an endpoint
        // from the generated SDK.
        $this->assertSame(array_unique($ids), $ids, 'Two operations share an operationId.');
    }

    public function test_endpoints_that_require_an_idempotency_key_say_so(): void
    {
        $spec = app(OpenApiGenerator::class)->generate();

        $evidence = $spec['paths']['/api/v1/pm/evidence']['post'];
        $key = collect($evidence['parameters'])->firstWhere('name', 'Idempotency-Key');

        $this->assertNotNull($key);
        $this->assertTrue($key['required'], 'Evidence ingestion requires a key; the spec has to say so.');
    }

    public function test_every_operation_documents_the_problem_responses(): void
    {
        $spec = app(OpenApiGenerator::class)->generate();

        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                foreach (['401', '403', '404', '422'] as $status) {
                    $this->assertArrayHasKey(
                        $status,
                        $operation['responses'],
                        strtoupper($method) . " {$path} does not document its {$status}."
                    );
                }
            }
        }
    }

    public function test_the_document_is_served_to_an_authenticated_client(): void
    {
        Sanctum::actingAs($this->manager, ['*']);

        $body = $this->getJson('/api/v1/pm/openapi.json')->assertOk()->json();

        $this->assertSame('3.1.0', $body['openapi']);
        $this->assertSame(OpenApiGenerator::VERSION, $body['info']['version']);
        // The evidence vocabulary travels with the contract, so a client generating models has the
        // event types without a second call.
        $this->assertContains('quantity.installed', $body['x-evidence-taxonomy']['types']);
    }

    public function test_the_document_is_not_public(): void
    {
        // It describes a tenant-scoped API; the shape of the system is not something to hand out
        // to anyone who asks.
        $this->getJson('/api/v1/pm/openapi.json')->assertUnauthorized();
    }
}
