<?php

namespace App\Modules\Operations\ProjectManagement\Support\Api;

use App\Modules\Operations\ProjectManagement\Support\Evidence\EvidenceTaxonomy;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * The OpenAPI document for /api/v1/pm (EPIC-070).
 *
 * Generated from the registered routes rather than maintained by hand. A hand-written spec is
 * accurate on the day it is written and wrong by the end of the month, and the failure is silent:
 * an integrator builds against a document describing an endpoint that no longer behaves that way.
 *
 * Descriptions live in DESCRIBED, keyed by route name. A route with no entry still appears in the
 * document — undocumented but present — and OpenApiSpecTest fails until someone writes the prose,
 * which is the check that keeps this honest.
 */
class OpenApiGenerator
{
    public const VERSION = '1.0.0';

    /**
     * Prose and shapes per route name. Everything here is written by a person; everything else in
     * the document is derived.
     *
     * @var array<string, array{summary:string, description?:string, request?:array, response?:array}>
     */
    private const DESCRIBED = [
        'api.pm.openapi' => [
            'summary' => 'This document',
            'description' => 'The contract, generated from the registered routes so it cannot drift from them. '
                . 'Authenticated: it describes a tenant-scoped API, and the shape of the system is not something to '
                . 'hand to anyone who asks.',
        ],
        'api.pm.sync' => [
            'summary' => 'The field bundle',
            'description' => 'Everything a device needs to work for a day with no signal: the projects visible to the '
                . 'caller, their tasks, checklists and recent evidence, plus the evidence vocabulary so a client can '
                . 'validate before spending a bad connection. Pass `since` (ISO 8601) to receive only what changed.',
            'response' => ['projects' => 'array', 'tasks' => 'array', 'checklists' => 'array', 'evidence' => 'array'],
        ],
        'api.pm.projects.index' => [
            'summary' => 'List projects',
            'description' => 'Cursor-paginated. Filter with `filter[status]`, `filter[stage]`; narrow the payload with `fields`.',
        ],
        'api.pm.projects.show'    => ['summary' => 'Fetch one project'],
        'api.pm.projects.store'   => ['summary' => 'Create a project'],
        'api.pm.projects.update'  => ['summary' => 'Change a project'],
        'api.pm.projects.destroy' => [
            'summary' => 'Archive a project',
            'description' => 'Soft delete (PM-ADR-007). The project stops appearing in listings; nothing is destroyed.',
        ],
        'api.pm.evidence.index'  => ['summary' => 'List evidence'],
        'api.pm.evidence.schema' => [
            'summary' => 'The evidence vocabulary',
            'description' => 'Every event type with its required and optional payload fields, as JSON Schema. '
                . 'A device validates against this before syncing, so a bad record fails on the phone rather than '
                . 'after a round trip on one bar of signal.',
        ],
        'api.pm.evidence.store' => [
            'summary' => 'Record evidence',
            'description' => 'Single record or a batch of up to 500. **Requires** an Idempotency-Key: this is the sync '
                . 'path, and a retried batch that duplicated a shift\'s work would be the worst failure the field loop '
                . 'could have. Events are append-only — corrections are new events (PM-ADR-002).',
        ],
        'api.pm.evidence.supersede' => [
            'summary' => 'Correct a piece of evidence',
            'description' => 'Writes a new event that supersedes the original. The original stays, and stops counting '
                . 'towards derived figures.',
        ],
        'api.pm.evidence.dead-letters' => [
            'summary' => 'Evidence that was rejected',
            'description' => 'Records the ledger refused, with the reason, so a device operator can see exactly what '
                . 'did not sync rather than discovering a gap weeks later.',
        ],
        'api.pm.evidence.replay' => ['summary' => 'Re-attempt a rejected record'],
        'api.pm.tasks.update' => [
            'summary' => 'Update a task from the field',
            'description' => 'Status and progress only. Send an Idempotency-Key: a device that loses signal mid-sync '
                . 'retries its whole queue, and the second attempt must change nothing.',
        ],
        'api.pm.checklist.update' => ['summary' => 'Tick or untick a checklist item'],
        'api.pm.time.store' => [
            'summary' => 'Book hours',
            'description' => 'Hours arrive submitted, never approved — approval is a separate act by a different '
                . 'person, and only approved hours are costed.',
        ],
    ];

    /** @return array<string,mixed> */
    public function generate(): array
    {
        $paths = [];

        foreach ($this->routes() as $route) {
            $path = '/' . ltrim($route->uri(), '/');
            $path = preg_replace('/\{(\w+)\??\}/', '{$1}', $path);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$path][strtolower($method)] = $this->operation($route, $method);
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title'       => 'Mkhaya Hub — Project Management API',
                'version'     => self::VERSION,
                'description' => "Evidence-based project management.\n\n"
                    . "Every error is `application/problem+json` (RFC 7807). An id belonging to another tenant returns "
                    . "404 rather than 403 — a 403 would confirm the record exists.\n\n"
                    . 'Mutations accept an `Idempotency-Key` header; a repeat of a key replays the first response '
                    . 'rather than acting twice.',
            ],
            'servers'    => [['url' => rtrim(config('app.url', ''), '/')]],
            'security'   => [['bearerAuth' => []]],
            'paths'      => $paths,
            'components' => $this->components(),
            'x-evidence-taxonomy' => [
                'version' => EvidenceTaxonomy::VERSION,
                'types'   => EvidenceTaxonomy::types(),
            ],
        ];
    }

    /**
     * Route names in the API that carry no prose yet — what OpenApiSpecTest fails on.
     *
     * @return list<string>
     */
    public function undocumented(): array
    {
        $missing = [];

        foreach ($this->routes() as $route) {
            $name = $route->getName();

            if ($name && ! isset(self::DESCRIBED[$name])) {
                $missing[] = $name;
            }
        }

        return array_values(array_unique($missing));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<RoutingRoute> */
    private function routes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route) => str_starts_with((string) $route->getName(), 'api.pm.')
        ));
    }

    /** @return array<string,mixed> */
    private function operation(RoutingRoute $route, string $method): array
    {
        $name = (string) $route->getName();
        $described = self::DESCRIBED[$name] ?? [];
        $idempotent = in_array('pm.idempotent', $route->gatherMiddleware(), true);
        $required = in_array('pm.idempotent:required', $route->gatherMiddleware(), true);

        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[] = [
                'name'        => $parameter,
                'in'          => 'path',
                'required'    => true,
                'schema'      => ['type' => 'string', 'format' => 'uuid'],
                'description' => 'The public id. An id from another tenant returns 404.',
            ];
        }

        if ($name === 'api.pm.sync') {
            $parameters[] = [
                'name'        => 'since',
                'in'          => 'query',
                'required'    => false,
                'schema'      => ['type' => 'string', 'format' => 'date-time'],
                'description' => 'Return only what changed after this moment.',
            ];
        }

        if ($idempotent || $required) {
            $parameters[] = [
                'name'        => 'Idempotency-Key',
                'in'          => 'header',
                'required'    => $required,
                'schema'      => ['type' => 'string', 'maxLength' => 128],
                'description' => $required
                    ? 'Required. A repeat of a key replays the first response rather than acting twice.'
                    : 'Optional but strongly advised on any retryable client.',
            ];
        }

        return array_filter([
            'operationId' => str_replace(['api.pm.', '.', '-'], ['', '_', '_'], $name) . '_' . strtolower($method),
            'summary'     => $described['summary'] ?? 'Undocumented',
            'description' => $described['description'] ?? null,
            'tags'        => [$this->tagFor($name)],
            'parameters'  => $parameters ?: null,
            'responses'   => [
                '200' => ['description' => 'Success'],
                '401' => ['$ref' => '#/components/responses/Problem'],
                '403' => ['$ref' => '#/components/responses/Problem'],
                '404' => ['$ref' => '#/components/responses/Problem'],
                '422' => ['$ref' => '#/components/responses/Problem'],
            ],
        ], fn ($v) => $v !== null);
    }

    private function tagFor(string $name): string
    {
        return match (true) {
            str_contains($name, 'evidence')  => 'Evidence',
            str_contains($name, 'projects')  => 'Projects',
            str_contains($name, 'sync'),
            str_contains($name, 'tasks'),
            str_contains($name, 'checklist'),
            str_contains($name, 'time')      => 'Field',
            default                          => 'Project Management',
        };
    }

    /** @return array<string,mixed> */
    private function components(): array
    {
        return [
            'securitySchemes' => [
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'A Sanctum personal access token.'],
            ],
            'responses' => [
                'Problem' => [
                    'description' => 'An RFC 7807 problem document.',
                    'content'     => [
                        'application/problem+json' => [
                            'schema' => ['$ref' => '#/components/schemas/Problem'],
                        ],
                    ],
                ],
            ],
            'schemas' => [
                'Problem' => [
                    'type'     => 'object',
                    'required' => ['type', 'title', 'status'],
                    'properties' => [
                        'type'     => ['type' => 'string', 'format' => 'uri'],
                        'title'    => ['type' => 'string'],
                        'status'   => ['type' => 'integer'],
                        'detail'   => ['type' => 'string'],
                        'instance' => ['type' => 'string'],
                        'errors'   => ['type' => 'object', 'description' => 'Per-field detail on a validation failure.'],
                    ],
                ],
            ],
        ];
    }
}
