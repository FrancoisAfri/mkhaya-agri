<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Jobs\ExportToWarehouseJob;
use App\Modules\Analytics\Models\BiConnection;
use App\Modules\Analytics\Services\BiDatasets;
use App\Modules\Analytics\Services\DatabricksExporter;
use App\Modules\Tenancy\Support\ResolvesTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connecting a tenant's warehouse, so their own BI tools can read their own data.
 *
 * Power BI does not connect to this platform; it connects to a warehouse. So what is offered here
 * is a push into the warehouse the customer already points Power BI at, rather than a Power BI
 * integration that would have to reimplement half of one.
 */
class BiExportController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly DatabricksExporter $exporter)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorise($request);

        $companyId = $this->companyId($request);
        $connection = $companyId ? BiConnection::where('company_id', $companyId)->first() : null;

        return Inertia::render('Modules/Analytics/BiExport', [
            'noCompany'  => $companyId === null,
            // forDisplay(), never the model: the token must not reach a page's props.
            'connection' => $connection?->forDisplay(),
            'providers'  => BiConnection::PROVIDERS,
            /*
             * Grouped by module, and only the modules this tenant runs.
             *
             * "What can I get out of CRM?" is the question somebody actually has; "what tables are
             * there?" is not. Listing modules they have not bought would make this a sales page,
             * and listing a dataset whose table has never been migrated would make a missing
             * module look like a broken export.
             */
            'modules'    => BiDatasets::forTenant(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $this->authorise($request);
        $companyId = $this->requireCompanyId($request);

        $data = $request->validate([
            'provider'  => ['required', Rule::in(BiConnection::PROVIDERS)],
            'name'      => ['nullable', 'string', 'max:120'],
            'host'      => ['required', 'string', 'max:255'],
            'http_path' => ['required', 'string', 'max:255'],
            'catalog'   => ['nullable', 'string', 'max:120'],
            'schema'    => ['nullable', 'string', 'max:120'],
            // Optional on save: somebody correcting a catalog name should not have to paste their
            // token again, and asking them to would train them to keep it somewhere convenient.
            'token'     => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);

        $connection = BiConnection::firstOrNew([
            'company_id' => $companyId,
            'provider'   => $data['provider'],
        ]);

        $connection->fill([
            'name'        => $data['name'] ?? null,
            'host'        => $data['host'],
            'http_path'   => $data['http_path'],
            'catalog'     => $data['catalog'] ?? null,
            'schema_name' => $data['schema'] ?? null,
            'is_active'   => (bool) ($data['is_active'] ?? false),
            'company_id'  => $companyId,
            'created_by'  => $connection->created_by ?? $request->user()->id,
        ]);

        if (filled($data['token'] ?? null)) {
            $connection->credentials = ['token' => $data['token']];
        }

        $connection->save();

        return back()->with('success', __('analytics.bi.flash.saved'));
    }

    /** Prove the host, the token and the warehouse path together, before anybody relies on them. */
    public function test(Request $request): RedirectResponse
    {
        $this->authorise($request);
        $connection = $this->connection($request);

        $result = $this->exporter->test($connection);

        $connection->forceFill([
            'last_tested_at'  => now(),
            'last_test_error' => $result['ok'] ? null : $result['error'],
        ])->save();

        return back()->with(
            $result['ok'] ? 'success' : 'warning',
            $result['ok'] ? __('analytics.bi.flash.tested') : __('analytics.bi.flash.test_failed'),
        );
    }

    public function export(Request $request): RedirectResponse
    {
        $this->authorise($request);
        $connection = $this->connection($request);

        $data = $request->validate([
            'datasets'   => ['required', 'array', 'min:1'],
            'datasets.*' => [Rule::in(BiDatasets::keys())],
        ]);

        // Queued, and the company travels with it: a worker has no session, so a job that resolved
        // its own tenant would resolve none — and write one customer's rows into another's
        // warehouse, permanently and outside this platform's reach.
        ExportToWarehouseJob::dispatch(
            (int) $connection->company_id,
            $connection->uuid,
            array_values($data['datasets']),
        );

        return back()->with('success', __('analytics.bi.flash.export_queued'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->authorise($request);

        $this->connection($request)->delete();

        return back()->with('success', __('analytics.bi.flash.removed'));
    }

    private function connection(Request $request): BiConnection
    {
        $companyId = $this->requireCompanyId($request);

        return BiConnection::where('company_id', $companyId)->firstOrFail();
    }

    /**
     * A warehouse credential and a company's whole dataset: this is an administrator's screen.
     *
     * `settings.manage` rather than a module permission, because the thing being handed out is not
     * one module's data — the datasets span projects and finance, and anybody who can configure
     * this can read all of it from outside the platform afterwards.
     */
    private function authorise(Request $request): void
    {
        abort_unless(
            $request->user()?->is_platform_admin || $request->user()?->can('settings.manage'),
            403,
        );
    }

    private function requireCompanyId(Request $request): int
    {
        $companyId = $this->companyId($request);

        abort_if($companyId === null, 409, __('analytics.bi.no_company'));

        return $companyId;
    }
}
