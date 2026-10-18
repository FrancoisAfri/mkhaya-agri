<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentQuota;
use App\Modules\Operations\DocumentManagement\Services\QuotaService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Setting storage limits below the level of the whole company.
 *
 * A settings question rather than a document one: it says nothing about any particular document and
 * everything about how the account is shared out.
 */
class DocumentQuotaController extends Controller
{
    use \App\Modules\Operations\DocumentManagement\Concerns\PicksTenantSubjects;

    public function __construct(private readonly QuotaService $quotas) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Document::class);

        $data = $request->validate([
            'scope' => ['required', Rule::in(DocumentQuota::SCOPES)],
            'scope_id' => ['required', 'integer'],
            // In gigabytes, because that is the unit people think in. Bytes in the form would mean
            // somebody typing eleven digits and getting one of them wrong.
            'gigabytes' => ['required', 'numeric', 'min:0.1', 'max:100000'],
            'warn_percent' => ['nullable', 'integer', 'min:50', 'max:99'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DocumentQuota::updateOrCreate(
            [
                'scope' => $data['scope'],
                'scope_id' => $data['scope_id'],
            ],
            [
                'uuid' => (string) Str::uuid(),
                'bytes_limit' => (int) round($data['gigabytes'] * 1024 * 1024 * 1024),
                'warn_percent' => (int) ($data['warn_percent'] ?? 85),
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', __('document_management.quotas.saved'));
    }

    public function destroy(DocumentQuota $quota): RedirectResponse
    {
        $this->authorize('manageSettings', Document::class);

        $quota->delete();

        return back()->with('success', __('document_management.quotas.removed'));
    }

    /**
     * The limits, their usage, and the things a limit can be put on.
     *
     * Shaped here rather than in the storage controller so the page can be given it without that
     * controller having to know about quotas at all.
     *
     * @return array<string, mixed>
     */
    public function payload(?Request $request = null): array
    {
        // Falls back to the current request when called from another controller's payload, which is
        // how the storage screen assembles this section.
        $request ??= request();

        return [
            'quotas' => DocumentQuota::query()
                ->orderBy('scope')
                ->get()
                ->map(fn (DocumentQuota $quota) => $this->quotas->describe($quota))
                ->values(),

            'subjects' => [
                'departments' => $this->tenantDepartments($request),

                'folders' => DocumentFolder::query()->whereNull('archived_at')->orderBy('path')
                    ->get()->map(fn (DocumentFolder $f) => [
                        'id' => $f->id, 'name' => str_repeat('　', (int) $f->depth) . $f->name,
                    ]),

                'people' => $this->tenantPeople($request),
            ],
        ];
    }
}
