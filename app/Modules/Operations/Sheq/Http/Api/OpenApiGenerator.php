<?php

namespace App\Modules\Operations\Sheq\Http\Api;

use App\Modules\Operations\Sheq\Events\EventRegistry;

/**
 * The OpenAPI description of the SHEQ API (§8.3).
 *
 * Generated from the same declarations the controllers read, never hand-written. A hand-maintained
 * spec is wrong within two sprints and worse than none at all: an integrator trusts it, builds
 * against a field that was renamed, and the failure surfaces as "your API is broken" rather than
 * "your documentation is stale". Deriving it means the description cannot disagree with the
 * implementation, because there is only one statement of what exists.
 *
 * Served at `/api/v1/sheq/openapi.json` rather than published as a file for the same reason — a
 * file in a repo drifts from the deployment, and what an integrator needs is what *this* instance
 * actually serves, including which events its packs publish.
 */
class OpenApiGenerator
{
    public function __construct(private readonly EventRegistry $events) {}

    /** @param array<string, array{permission:string, fields:list<string>, site:bool}> $resources */
    public function generate(array $resources): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Mkhaya SHEQ API',
                'version' => '1.0.0',
                'description' => $this->description(),
            ],
            'servers' => [['url' => url('/api/v1/sheq'), 'description' => 'This instance']],
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer',
                        'description' => 'A Sanctum token. The tenant is resolved from the token’s '
                            .'user — there is no parameter that changes which company you read.'],
                ],
                'schemas' => [
                    'Problem' => $this->problemSchema(),
                    'Pagination' => [
                        'type' => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer'],
                            'has_more' => ['type' => 'boolean'],
                            'next_cursor' => ['type' => ['integer', 'null'],
                                'description' => 'Pass as `cursor` for the next page. Null when '
                                    .'exhausted, so loop until null rather than comparing counts.'],
                        ],
                    ],
                ],
                'responses' => [
                    'Problem' => [
                        'description' => 'RFC 9457 problem details',
                        'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
                    ],
                ],
            ],
            'paths' => $this->paths($resources),
            'x-events' => $this->eventCatalogue(),
        ];
    }

    private function description(): string
    {
        return <<<'TEXT'
        The SHEQ module's public API.

        **Errors** are RFC 9457 problem details with `application/problem+json`. Branch on the
        `type` URI, not on `detail` — the URI is stable, the sentence may be improved.

        **Paging** is keyset, not offset. These registers are append-heavy, and `OFFSET` on a table
        receiving inserts skips and repeats rows between pages in a way a consumer cannot detect.
        Follow `pagination.next_cursor` until it is null.

        **Webhook signatures** are HMAC-SHA256 over the timestamp, a full stop, then the raw request
        body. Verify against the bytes as received: re-serialising the JSON first will fail
        intermittently, because key order and unicode escaping differ between parsers.

        **Tenancy** comes from the token. No request parameter changes which company you read, and
        an identifier belonging to another tenant returns 404 rather than 403 — distinguishing them
        would make the endpoint an oracle for probing other companies' data.
        TEXT;
    }

    private function problemSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'title', 'status', 'detail'],
            'properties' => [
                'type' => ['type' => 'string', 'format' => 'uri',
                    'description' => 'Stable identifier for this failure kind. Branch on this.'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string',
                    'description' => 'Written for a human debugging. May change between releases.'],
                'errors' => ['type' => 'object',
                    'description' => 'Present on validation failures: field name to messages.'],
                'required_permission' => ['type' => 'string',
                    'description' => 'Present on entitlement failures.'],
                'reference' => ['type' => 'string',
                    'description' => 'Present on 500s. Quote it to support; it locates the log line.'],
            ],
        ];
    }

    /** @param array<string, array{permission:string, fields:list<string>, site:bool}> $resources */
    private function paths(array $resources): array
    {
        $paths = [];

        foreach ($resources as $name => $spec) {
            $itemSchema = [
                'type' => 'object',
                'properties' => array_fill_keys($spec['fields'], ['type' => ['string', 'number', 'boolean', 'null']]),
            ];

            $parameters = [
                $this->param('limit', 'query', 'integer', 'Page size, 1–200. Defaults to 50.'),
                $this->param('cursor', 'query', 'integer', 'Keyset cursor from a previous page.'),
                $this->param('since', 'query', 'string', 'Only records on or after this date.'),
                $this->param('until', 'query', 'string', 'Only records on or before this date.'),
                $this->param('status', 'query', 'string', 'Exact status match.'),
            ];

            if ($spec['site']) {
                $parameters[] = $this->param('site_id', 'query', 'integer', 'Restrict to one site.');
            }

            $paths["/{$name}"] = [
                'get' => [
                    'summary' => "List {$name}",
                    'description' => "Requires the `{$spec['permission']}` permission.",
                    'operationId' => 'list'.str_replace(['-', ' '], '', ucwords($name, '- ')),
                    'parameters' => $parameters,
                    'responses' => [
                        '200' => [
                            'description' => 'A page of records',
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => ['type' => 'array', 'items' => $itemSchema],
                                    'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                                ],
                            ]]],
                        ],
                        '403' => ['$ref' => '#/components/responses/Problem'],
                        '422' => ['$ref' => '#/components/responses/Problem'],
                    ],
                ],
            ];

            $paths["/{$name}/{uuid}"] = [
                'get' => [
                    'summary' => "Fetch one record from {$name}",
                    'operationId' => 'get'.str_replace(['-', ' '], '', ucwords($name, '- ')),
                    'parameters' => [$this->param('uuid', 'path', 'string', 'The record identifier.', true)],
                    'responses' => [
                        '200' => ['description' => 'The record', 'content' => ['application/json' => ['schema' => ['type' => 'object',
                            'properties' => ['data' => $itemSchema]]]]],
                        '403' => ['$ref' => '#/components/responses/Problem'],
                        '404' => ['$ref' => '#/components/responses/Problem'],
                    ],
                ],
            ];
        }

        return $paths + $this->staticPaths();
    }

    private function staticPaths(): array
    {
        $problem = ['$ref' => '#/components/responses/Problem'];

        return [
            '/webhooks' => [
                'get' => [
                    'summary' => 'List webhook subscriptions',
                    'operationId' => 'listWebhooks',
                    'responses' => ['200' => ['description' => 'Subscriptions. Secrets are never '
                        .'returned — only a hint.'], '403' => $problem],
                ],
                'post' => [
                    'summary' => 'Create a webhook subscription',
                    'description' => 'The signing secret is returned once here and never again. '
                        .'Store it on receipt; if lost, rotate rather than retrieve.',
                    'operationId' => 'createWebhook',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['name', 'url', 'event_types'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'url' => ['type' => 'string', 'format' => 'uri',
                                'description' => 'Must be https.'],
                            'event_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ]]]]],
                    'responses' => ['201' => ['description' => 'Created, with the secret'],
                        '403' => $problem, '422' => $problem],
                ],
            ],
            '/webhooks/{uuid}' => [
                'patch' => ['summary' => 'Update a subscription', 'operationId' => 'updateWebhook',
                    'parameters' => [$this->param('uuid', 'path', 'string', 'Subscription id.', true)],
                    'responses' => ['200' => ['description' => 'Updated'], '404' => $problem]],
                'delete' => ['summary' => 'Delete a subscription', 'operationId' => 'deleteWebhook',
                    'parameters' => [$this->param('uuid', 'path', 'string', 'Subscription id.', true)],
                    'responses' => ['200' => ['description' => 'Deleted'], '404' => $problem]],
            ],
            '/webhooks/{uuid}/rotate' => [
                'post' => [
                    'summary' => 'Rotate the signing secret',
                    'description' => 'The previous secret stops working immediately.',
                    'operationId' => 'rotateWebhookSecret',
                    'parameters' => [$this->param('uuid', 'path', 'string', 'Subscription id.', true)],
                    'responses' => ['200' => ['description' => 'The new secret'], '404' => $problem],
                ],
            ],
            '/events' => [
                'get' => ['summary' => 'The events SHEQ publishes', 'operationId' => 'listEventTypes',
                    'responses' => ['200' => ['description' => 'Event catalogue with payload keys']]],
            ],
            '/sync/pull' => [
                'get' => ['summary' => 'Delta pull for offline clients',
                    'operationId' => 'syncPull',
                    'responses' => ['200' => ['description' => 'Changes since the given cursors']]],
            ],
            '/sync/push' => [
                'post' => ['summary' => 'Push queued offline operations',
                    'description' => 'Idempotency is per operation, not per request, so a retried '
                        .'batch where half already landed applies only the rest.',
                    'operationId' => 'syncPush',
                    'responses' => ['200' => ['description' => 'Per-operation results']]],
            ],
        ];
    }

    private function eventCatalogue(): array
    {
        return array_map(fn ($type, $schema) => [
            'type' => $type,
            'version' => $schema['version'],
            'describes' => $schema['describes'],
            'payload_keys' => $schema['required'],
        ], array_keys($this->events->all()), $this->events->all());
    }

    private function param(string $name, string $in, string $type, string $description,
        bool $required = false): array
    {
        return ['name' => $name, 'in' => $in, 'required' => $required,
            'description' => $description, 'schema' => ['type' => $type]];
    }
}
