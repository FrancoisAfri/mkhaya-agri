<?php

namespace App\Modules\Operations\Sheq\Http\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Http\Api\OpenApiGenerator;
use App\Modules\Operations\Sheq\Http\Api\ProblemDetails;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The read surface of the SHEQ API (§8.3).
 *
 * One controller for the resource collections rather than a dozen near-identical ones. Every SHEQ
 * register is the same problem — filter by site and date, page, project a stable field list — and
 * twelve copies of that would drift within a month: one would forget the tenant scope, one would
 * page differently, one would leak a soft-deleted row. The variation lives in a declarative table
 * below where it can be read at a glance and audited in one place.
 *
 * Three rules hold for every resource here, without exception:
 *
 * 1. **The tenant comes from the token**, never from a parameter. There is no request field that
 *    changes which company you read.
 * 2. **Keyset pagination, not offset.** These registers are append-heavy; `OFFSET 4000` on a table
 *    receiving inserts silently skips and repeats rows between pages, and a consumer building a
 *    mirror ends up with gaps it cannot detect. A cursor on `id` cannot.
 * 3. **Explicit field lists.** `SELECT *` would publish every column added later as public API,
 *    including internal ones, and a consumer would start depending on it.
 */
class ResourceApiController extends Controller
{
    /** Enough to be useful in one round trip; small enough not to time out on a slow link. */
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function __construct(private readonly CurrentCompany $current) {}

    /**
     * The published resources.
     *
     * `fields` is the contract. Adding one is backwards-compatible; removing or repurposing one is
     * not, and needs a new API version — the same rule the event registry enforces for consumers.
     *
     * Every entry uses `sheq.report.view`, which is the module's existing "may read SHEQ data"
     * permission. Minting a parallel `sheq.*.view` family for the API would double the permission
     * surface an administrator has to reason about, and the first consequence of that is a role
     * that can read incidents on screen but not through the integration — with no way to tell from
     * the UI why.
     *
     * `confidential` marks resources that carry records not everyone may see even with read access.
     *
     * @return array<string, array{table:string, fields:list<string>, date:string,
     *                             permission:string, site:bool, confidential?:bool}>
     */
    public function resources(): array
    {
        return [
            'incidents' => [
                'table' => 'sheq_incidents', 'date' => 'occurred_at', 'site' => true,
                'permission' => 'sheq.report.view',
                // Harassment and assault reports live in this table behind `is_confidential`.
                'confidential' => true,
                'fields' => ['uuid', 'reference', 'site_id', 'occurred_at', 'reported_at',
                    'incident_type', 'description', 'actual_severity', 'potential_severity',
                    'statutory_category', 'is_reportable', 'status', 'days_lost', 'created_at'],
            ],
            'hazards' => [
                'table' => 'sheq_hazards', 'date' => 'reported_at', 'site' => true,
                'permission' => 'sheq.report.view',
                'fields' => ['uuid', 'reference', 'site_id', 'description', 'hazard_category',
                    'severity_est', 'status', 'reported_at', 'closed_at', 'created_at'],
            ],
            'actions' => [
                'table' => 'sheq_actions', 'date' => 'due_date', 'site' => false,
                'permission' => 'sheq.report.view',
                'fields' => ['uuid', 'reference', 'title', 'status', 'priority', 'due_date',
                    'assigned_to', 'source_type', 'source_id', 'created_at'],
            ],
            'permits' => [
                'table' => 'sheq_permits', 'date' => 'valid_from', 'site' => true,
                'permission' => 'sheq.report.view',
                'fields' => ['uuid', 'reference', 'site_id', 'permit_type', 'status',
                    'valid_from', 'valid_to', 'created_at'],
            ],
            'inspections' => [
                'table' => 'sheq_inspections', 'date' => 'completed_at', 'site' => true,
                'permission' => 'sheq.report.view',
                'fields' => ['uuid', 'site_id', 'template_id', 'score', 'passed',
                    'started_at', 'completed_at', 'created_at'],
            ],
            'risk-assessments' => [
                'table' => 'sheq_risk_assessments', 'date' => 'created_at', 'site' => true,
                'permission' => 'sheq.report.view',
                'fields' => ['uuid', 'reference', 'site_id', 'title', 'assessment_type',
                    'status', 'review_due', 'created_at'],
            ],
            'sites' => [
                'table' => 'sheq_sites', 'date' => 'created_at', 'site' => false,
                'permission' => 'sheq.report.view',
                'fields' => ['uuid', 'name', 'code', 'site_type', 'is_active', 'created_at'],
            ],
            'contractors' => [
                'table' => 'sheq_contractors', 'date' => 'created_at', 'site' => false,
                'permission' => 'sheq.report.view',
                'fields' => ['uuid', 'name', 'registration_number', 'status',
                    'compliance_status', 'created_at'],
            ],
        ];
    }

    /**
     * Hide records that read access alone does not entitle you to.
     *
     * `sheq_incidents.is_confidential` covers harassment, assault and substance cases — reports
     * whose subject is a person rather than a machine. Read access to the incident register is
     * given widely, because most of it is what everyone should see; these are the rows where that
     * assumption fails, and an integration pulling the register wholesale would export them to a
     * BI tool where nobody is checking who can open the dashboard.
     *
     * The screen already applies this. The API had to as well, or the boundary would hold in one
     * place and not the other, which is the same as not holding.
     */
    private function applyConfidentiality(Builder $query, array $spec, Request $request): void
    {
        if (empty($spec['confidential']) || $request->user()?->can('sheq.confidential.read')) {
            return;
        }

        $query->where(fn ($q) => $q->where('is_confidential', false)
            ->orWhereNull('is_confidential'));
    }

    /** `GET /api/v1/sheq/{resource}` */
    public function index(Request $request, string $resource): JsonResponse
    {
        $spec = $this->resources()[$resource] ?? null;

        if ($spec === null) {
            return ProblemDetails::make(404, 'unknown-resource', 'No such resource',
                "`{$resource}` is not a SHEQ API resource.",
                ['available' => array_keys($this->resources())]);
        }

        if (! $request->user()?->can($spec['permission'])) {
            return ProblemDetails::make(403, 'entitlement-required',
                'You do not have this permission',
                "Reading {$resource} requires the `{$spec['permission']}` permission.",
                ['required_permission' => $spec['permission']]);
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['nullable', 'integer', 'min:0'],
            'site_id' => ['nullable', 'integer'],
            'since' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $companyId = $this->companyId();

        $query = $this->scoped($spec['table'], $companyId);
        $this->applyConfidentiality($query, $spec, $request);

        if (isset($validated['site_id']) && $spec['site']) {
            $query->where('site_id', $validated['site_id']);
        }

        if (isset($validated['since'])) {
            $query->where($spec['date'], '>=', $validated['since']);
        }

        if (isset($validated['until'])) {
            $query->where($spec['date'], '<=', $validated['until']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['cursor'])) {
            $query->where('id', '>', $validated['cursor']);
        }

        // One extra row tells us whether another page exists without a second COUNT query — and
        // without the count going stale between the two round trips anyway.
        $rows = $query->orderBy('id')->limit($limit + 1)
            ->get(array_merge(['id'], $spec['fields']));

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        return response()->json([
            'data' => $page->map(function ($row) use ($spec) {
                $out = [];

                foreach ($spec['fields'] as $field) {
                    $out[$field] = $row->{$field};
                }

                return $out;
            })->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                // Null when exhausted, so a client loops until it is null rather than having to
                // compare counts.
                'next_cursor' => $hasMore ? (int) $page->last()->id : null,
            ],
        ]);
    }

    /** `GET /api/v1/sheq/{resource}/{uuid}` */
    public function show(Request $request, string $resource, string $uuid): JsonResponse
    {
        $spec = $this->resources()[$resource] ?? null;

        if ($spec === null) {
            return ProblemDetails::make(404, 'unknown-resource', 'No such resource',
                "`{$resource}` is not a SHEQ API resource.");
        }

        if (! $request->user()?->can($spec['permission'])) {
            return ProblemDetails::make(403, 'entitlement-required',
                'You do not have this permission',
                "Reading {$resource} requires the `{$spec['permission']}` permission.",
                ['required_permission' => $spec['permission']]);
        }

        $query = $this->scoped($spec['table'], $this->companyId())->where('uuid', $uuid);
        // Without this, a record hidden from the list would still be readable by identifier —
        // and identifiers travel, in emails and spreadsheets.
        $this->applyConfidentiality($query, $spec, $request);

        $row = $query->first($spec['fields']);

        if ($row === null) {
            // Same response whether it does not exist or belongs to another tenant. Distinguishing
            // them would turn this endpoint into an oracle for probing other companies' identifiers.
            return ProblemDetails::make(404, 'not-found', 'No such record',
                "No {$resource} record with that identifier exists in your company.");
        }

        return response()->json(['data' => (array) $row]);
    }

    /**
     * `GET /api/v1/sheq/openapi.json`
     *
     * Served rather than shipped as a file: what an integrator needs is what *this* instance
     * actually exposes, and a file in a repo drifts from the deployment that answers their calls.
     */
    public function openapi(OpenApiGenerator $generator): JsonResponse
    {
        return response()->json($generator->generate($this->resources()));
    }

    /** The list of resources and what each needs — enough to write a client against. */
    public function catalogue(): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($name, $spec) => [
            'resource' => $name,
            'path' => "/api/v1/sheq/{$name}",
            'permission' => $spec['permission'],
            'fields' => $spec['fields'],
            'filters' => array_values(array_filter([
                'since', 'until', 'status', $spec['site'] ? 'site_id' : null,
            ])),
        ], array_keys($this->resources()), $this->resources())]);
    }

    /**
     * Tenant scope plus soft-delete exclusion, in one place.
     *
     * Both are easy to forget on any single query and catastrophic in different ways — one leaks
     * across companies, the other resurrects deleted records into a consumer's mirror.
     */
    private function scoped(string $table, int $companyId): Builder
    {
        $query = DB::table($table)->where('company_id', $companyId);

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    private function companyId(): int
    {
        // `sheq.tenant` middleware has already refused anything unresolved, so this cannot be null
        // by the time a controller action runs.
        return (int) $this->current->id();
    }
}
