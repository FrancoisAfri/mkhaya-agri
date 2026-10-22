<?php

namespace App\Modules\Agri\Api;

use App\Modules\Agri\Models\AnimalEvent;
use App\Modules\Agri\Models\FarmWorkOrder;
use App\Modules\Agri\Models\FieldActivity;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * The OpenAPI 3.1 document for the farm API, generated from the registered routes and the
 * resource definitions — so every route is in it, and a field is documented exactly as returned.
 * Endpoints other than the resource list/show pairs are described in DOCS below; a route missing
 * from DOCS still appears (and the test suite fails on it).
 */
class ApiSpec
{
    public const PREFIX = 'api.agri.v1.';

    /** Hand-written descriptions of the endpoints that are not plain list/show. */
    private function docs(): array
    {
        $workTypes = FieldActivity::TYPES;

        return [
            'openapi' => ['summary' => 'This document.', 'tag' => 'Meta', 'public' => true],
            'me' => ['summary' => 'The calling token: its client, abilities, farms, limit and expiry.', 'tag' => 'Meta', 'ability' => null,
                'example' => ['data' => ['client' => '0192…', 'name' => 'Agronomy app', 'abilities' => ['farms:read', 'crops:read'], 'farms' => 'all', 'rate_per_minute' => 120]]],
            'lots.trace' => ['summary' => 'Trace a produce lot: backward to fields, crop plans, inputs and operations; forward to the lots made from it and the deliveries they went on.', 'tag' => 'Harvest & trace', 'ability' => 'harvests:read'],
            'stock.index' => ['summary' => 'Stock on hand in the farm stores the token reaches, by item, status and lot.', 'tag' => 'Stock', 'ability' => 'stock:read', 'paged' => true,
                'params' => ['farm' => 'Farm id', 'item' => 'Stock item code'],
                'example' => ['data' => [['farm' => '0192…', 'store' => '0192…', 'store_name' => 'Boplaas store', 'item_code' => 'UREA-46', 'item' => 'Urea 46%', 'uom' => 'kg', 'status' => 'available', 'lot' => null, 'quantity' => 1800.0, 'unit_cost' => 11.2, 'value' => 20160.0]]]],
            'finance.summary' => ['summary' => 'Income, direct costs and margin per farm for a period, from the books.', 'tag' => 'Finance', 'ability' => 'finance:read',
                'params' => ['from' => 'Start date (YYYY-MM-DD; default 1 January)', 'to' => 'End date (default today)', 'farm' => 'Farm id']],
            'field-activities.store' => ['summary' => 'Plan a field activity on a farm the token may write to.', 'tag' => 'Crops', 'ability' => 'activities:write', 'status' => 201, 'resource' => 'field-activities',
                'body' => ['required' => ['farm', 'activity_type', 'title', 'planned_on'], 'properties' => ['farm' => ['type' => 'string', 'format' => 'uuid'], 'field' => ['type' => 'string', 'format' => 'uuid'],
                    'crop_plan' => ['type' => 'string', 'format' => 'uuid'], 'activity_type' => ['type' => 'string', 'enum' => $workTypes], 'title' => ['type' => 'string', 'maxLength' => 200],
                    'planned_on' => ['type' => 'string', 'format' => 'date'], 'area_covered' => ['type' => 'number'], 'notes' => ['type' => 'string']]],
                'example_body' => ['farm' => '0192d3c4-…', 'field' => '0192d3c5-…', 'activity_type' => 'spraying', 'title' => 'Post-emergence herbicide', 'planned_on' => '2026-11-03']],
            'animal-events.store' => ['summary' => 'Record an event on a tagged animal (birth, weaning, mating, pregnancy check, milk recording, …).', 'tag' => 'Livestock', 'ability' => 'livestock:write', 'status' => 201, 'resource' => 'animal-events',
                'body' => ['required' => ['animal', 'event_type', 'occurred_on'], 'properties' => ['animal' => ['type' => 'string', 'format' => 'uuid'],
                    'event_type' => ['type' => 'string', 'enum' => array_values(array_diff(AnimalEvent::TYPES, ['exit']))], 'occurred_on' => ['type' => 'string', 'format' => 'date'],
                    'outcome' => ['type' => 'string'], 'details' => ['type' => 'object']]],
                'example_body' => ['animal' => '0192d3c6-…', 'event_type' => 'pregnancy_check', 'occurred_on' => '2026-10-20', 'outcome' => 'pregnant']],
            'work-orders.status' => ['summary' => 'Move a work order to another status. Approving needs work_orders:write and an approver; completing or rejecting needs a verifier.', 'tag' => 'Work orders',
                'ability' => 'work_orders:write', 'resource' => 'work-orders',
                'body' => ['required' => ['to'], 'properties' => ['to' => ['type' => 'string', 'enum' => FarmWorkOrder::STATUSES], 'reason' => ['type' => 'string'], 'actual_output' => ['type' => 'number']]],
                'example_body' => ['to' => 'in_progress']],
            'webhooks.events' => ['summary' => 'The events a webhook can subscribe to.', 'tag' => 'Webhooks', 'ability' => null],
            'webhooks.index' => ['summary' => 'This client\'s webhook subscriptions.', 'tag' => 'Webhooks', 'ability' => 'webhooks:manage'],
            'webhooks.store' => ['summary' => 'Subscribe a URL to farm events. The answer carries the signing secret, once.', 'tag' => 'Webhooks', 'ability' => 'webhooks:manage', 'status' => 201,
                'body' => ['required' => ['name', 'url', 'events'], 'properties' => ['name' => ['type' => 'string'], 'url' => ['type' => 'string', 'format' => 'uri'],
                    'events' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(Webhooks::EVENTS)]]]],
                'example_body' => ['name' => 'Packhouse ERP', 'url' => 'https://erp.example.co.za/hooks/mkhaya', 'events' => ['harvest.recorded', 'lot.dispatched']]],
            'webhooks.destroy' => ['summary' => 'Remove one of this client\'s subscriptions.', 'tag' => 'Webhooks', 'ability' => 'webhooks:manage', 'status' => 204],
        ];
    }

    /** @return list<Route> */
    public static function routes(): array
    {
        return collect(Router::getRoutes()->getRoutes())->filter(fn (Route $r) => str_starts_with((string) $r->getName(), self::PREFIX))->values()->all();
    }

    public function document(): array
    {
        $paths = [];
        $defs = ApiResources::definitions();
        $docs = $this->docs();
        foreach (self::routes() as $route) {
            $name = substr((string) $route->getName(), strlen(self::PREFIX));
            $method = strtolower(collect($route->methods())->first(fn ($m) => $m !== 'HEAD'));
            $path = '/'.ltrim(preg_replace('#^api/#', '', $route->uri()), '/');
            $path = '/'.ltrim(substr($path, strlen('/agri/v1')), '/');
            $resource = $route->defaults['resource'] ?? null;
            $op = $resource && isset($defs[$resource]) && in_array($name, ["{$resource}.index", "{$resource}.show"], true)
                ? $this->resourceOperation($resource, $defs[$resource], str_ends_with($name, '.show'))
                : $this->operation($name, $docs[$name] ?? ['summary' => $name, 'tag' => 'Other', 'undocumented' => true], $route);
            $op['operationId'] = str_replace(['.', '-'], ['_', '_'], $name);
            $paths[$path][$method] = $op;
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Mkhaya Agri farm API', 'version' => '1.0.0',
                'description' => "Read the farm's records and record field work, livestock events and job progress from your own systems.\n\n"
                    ."Authenticate with a bearer token issued under Farm Operations → API access. A token belongs to one organisation, acts as its own named user and reaches only the farms it was given; each endpoint needs the ability shown.\n\n"
                    ."Lists are paged by cursor: follow `meta.next_cursor` (or `links.next`) until it is null. To keep a copy in sync, send `updated_since` = when your previous sync started.\n\n"
                    ."Send `Idempotency-Key` on a POST to make it safe to retry: the same key and body answer with the first response (header `Idempotent-Replay: true`).\n\n"
                    .'Errors are RFC 9457 problem documents (`application/problem+json`). Each token has a per-minute limit (`X-RateLimit-Limit`, `X-RateLimit-Remaining`; a 429 carries `Retry-After`).'],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/agri/v1']],
            'security' => [['bearer' => []]],
            'tags' => collect(array_merge(array_column($defs, 'tag'), array_column($docs, 'tag')))->unique()->values()->map(fn ($t) => ['name' => $t])->all(),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'A farm API token. Abilities: '
                    .collect(ApiAbilities::MAP)->keys()->implode(', ').'.']],
                'parameters' => [
                    'cursor' => ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'meta.next_cursor from the previous page.'],
                    'limit' => ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => (int) config('agri.api.max_page', 200), 'default' => 50]],
                    'updated_since' => ['name' => 'updated_since', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date-time'], 'description' => 'Only records changed at or after this moment (ISO 8601).'],
                    'farm' => ['name' => 'farm', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'uuid'], 'description' => 'Only this farm.'],
                    'id' => ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    'idempotency' => ['name' => 'Idempotency-Key', 'in' => 'header', 'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 120], 'description' => 'Makes the POST safe to retry.'],
                ],
                'schemas' => $this->schemas($defs) + [
                    'Problem' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'],
                        'detail' => ['type' => 'string'], 'instance' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
                    'PageMeta' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer'], 'count' => ['type' => 'integer'], 'next_cursor' => ['type' => ['string', 'null']], 'has_more' => ['type' => 'boolean']]],
                    'WebhookDelivery' => ['type' => 'object', 'description' => 'The body POSTed to a subscriber. Header X-Mkhaya-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the subscription secret>.',
                        'properties' => ['id' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => array_keys(Webhooks::EVENTS)], 'created_at' => ['type' => 'string', 'format' => 'date-time'], 'data' => ['type' => 'object']]],
                ],
                'responses' => [
                    'Problem' => ['description' => 'An error', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem'],
                        'example' => ['type' => 'https://mkhaya.app/problems/missing-ability', 'title' => 'Forbidden', 'status' => 403, 'detail' => 'This token was not issued with the "crops:read" ability.', 'instance' => '/api/agri/v1/crop-plans']]]],
                ],
            ],
            'webhooks' => collect(Webhooks::EVENTS)->mapWithKeys(fn ($e, $k) => [$k => ['post' => ['summary' => $e[0], 'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/WebhookDelivery']]]],
                'responses' => ['200' => ['description' => 'Any 2xx answer is a delivery; anything else is retried with back-off, five times.']]]]])->all(),
        ];
    }

    private function resourceOperation(string $key, array $d, bool $show): array
    {
        $schema = ['$ref' => '#/components/schemas/'.$this->schemaName($key)];
        $params = $show ? [['$ref' => '#/components/parameters/id']] : [['$ref' => '#/components/parameters/cursor'], ['$ref' => '#/components/parameters/limit'], ['$ref' => '#/components/parameters/updated_since']];
        if (! $show) {
            if (($d['farm_column'] ?? 'farm_id') !== null) {
                $params[] = ['$ref' => '#/components/parameters/farm'];
            }
            foreach ($d['filters'] ?? [] as $p => $f) {
                $params[] = ['name' => $p, 'in' => 'query', 'schema' => $f[1] === 'uuid' ? ['type' => 'string', 'format' => 'uuid'] : ['type' => 'string']];
            }
            if (! empty($d['date'])) {
                $params[] = ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => "{$d['date']} on or after"];
                $params[] = ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => "{$d['date']} on or before"];
            }
            if (! empty($d['geojson'])) {
                $params[] = ['name' => 'format', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['json', 'geojson']], 'description' => 'geojson: a FeatureCollection'];
            }
        }

        return ['tags' => [$d['tag']], 'summary' => $show ? 'One of: '.lcfirst($d['summary']) : $d['summary'], 'x-ability' => $d['ability'],
            'description' => "Needs the `{$d['ability']}` ability.", 'parameters' => $params,
            'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => [
                'schema' => $show ? ['type' => 'object', 'properties' => ['data' => $schema]]
                    : ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => $schema], 'meta' => ['$ref' => '#/components/schemas/PageMeta'],
                        'links' => ['type' => 'object', 'properties' => ['next' => ['type' => ['string', 'null']]]]]],
                'example' => $show ? ['data' => $this->example($d)] : ['data' => [$this->example($d)], 'meta' => ['limit' => 50, 'count' => 1, 'next_cursor' => null, 'has_more' => false], 'links' => ['next' => null]]]]],
                '401' => ['$ref' => '#/components/responses/Problem'], '403' => ['$ref' => '#/components/responses/Problem'], '404' => ['$ref' => '#/components/responses/Problem'], '429' => ['$ref' => '#/components/responses/Problem']]];
    }

    private function operation(string $name, array $doc, Route $route): array
    {
        $params = [];
        if (in_array('id', $route->parameterNames(), true)) {
            $params[] = ['$ref' => '#/components/parameters/id'];
        }
        if (! empty($doc['paged'])) {
            array_push($params, ['$ref' => '#/components/parameters/cursor'], ['$ref' => '#/components/parameters/limit'], ['$ref' => '#/components/parameters/updated_since']);
        }
        foreach ($doc['params'] ?? [] as $p => $desc) {
            $params[] = ['name' => $p, 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => $desc];
        }
        $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
        if ($method === 'POST') {
            $params[] = ['$ref' => '#/components/parameters/idempotency'];
        }
        $status = (string) ($doc['status'] ?? 200);
        $content = ! empty($doc['resource']) ? ['schema' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/'.$this->schemaName($doc['resource'])]]]]
            : ['schema' => ['type' => 'object']];
        if (! empty($doc['example'])) {
            $content['example'] = $doc['example'];
        }
        $op = ['tags' => [$doc['tag']], 'summary' => $doc['summary'], 'parameters' => $params,
            'responses' => [$status => $status === '204' ? ['description' => 'Done'] : ['description' => 'OK', 'content' => ['application/json' => $content]]]];
        if (! empty($doc['public'])) {
            $op['security'] = [];
        } else {
            $op['x-ability'] = $doc['ability'] ?? null;
            $op['description'] = ! empty($doc['ability']) ? "Needs the `{$doc['ability']}` ability." : 'Any farm API token.';
            $op['responses'] += ['401' => ['$ref' => '#/components/responses/Problem'], '403' => ['$ref' => '#/components/responses/Problem'], '422' => ['$ref' => '#/components/responses/Problem']];
        }
        if (! empty($doc['body'])) {
            $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object'] + $doc['body'], 'example' => $doc['example_body'] ?? null]]];
        }
        if (! empty($doc['undocumented'])) {
            $op['x-undocumented'] = true;
        }

        return $op;
    }

    private function schemas(array $defs): array
    {
        $out = [];
        foreach ($defs as $key => $d) {
            $props = ['id' => ['type' => 'string', 'format' => 'uuid']];
            foreach (array_keys($d['refs'] ?? []) as $r) {
                $props[$r] = ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => "The {$r}'s id"];
            }
            foreach (array_keys($d['names'] ?? []) as $n) {
                $props[$n] = ['type' => ['string', 'null']];
            }
            foreach ($d['fields'] as $f => $type) {
                $props[$f] = match ($type) {
                    'number' => ['type' => ['number', 'null']],
                    'integer' => ['type' => ['integer', 'null']],
                    'boolean' => ['type' => ['boolean', 'null']],
                    'date' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'datetime' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'geojson' => ['type' => ['object', 'null'], 'description' => 'GeoJSON geometry (Polygon or MultiPolygon, WGS84 longitude/latitude)'],
                    'object' => ['type' => ['object', 'null']],
                    'array' => ['type' => ['array', 'null']],
                    default => ['type' => ['string', 'null']],
                };
            }
            $props['created_at'] = ['type' => 'string', 'format' => 'date-time'];
            $props['updated_at'] = ['type' => 'string', 'format' => 'date-time'];
            $out[$this->schemaName($key)] = ['type' => 'object', 'description' => $d['summary'], 'properties' => $props];
        }

        return $out;
    }

    private function example(array $d): array
    {
        $ex = ['id' => '0192d3c4-5e6f-7a8b-9c0d-1e2f3a4b5c6d'];
        foreach (array_keys($d['refs'] ?? []) as $r) {
            $ex[$r] = '0192d3c4-…';
        }
        foreach ($d['fields'] as $f => $type) {
            $ex[$f] = match ($type) {
                'number' => 12.5, 'integer' => 3, 'boolean' => true, 'date' => '2026-10-21', 'datetime' => '2026-10-21T08:30:00Z',
                'geojson' => ['type' => 'Polygon', 'coordinates' => [[[28.30, -28.22], [28.31, -28.22], [28.31, -28.23], [28.30, -28.22]]]],
                'object' => (object) [], 'array' => [], default => '…',
            };
        }

        return $ex + ['updated_at' => '2026-10-21T08:30:00Z'];
    }

    private function schemaName(string $key): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $key)));
    }
}
