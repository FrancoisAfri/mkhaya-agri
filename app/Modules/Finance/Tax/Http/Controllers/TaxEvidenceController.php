<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxComputation;
use App\Modules\Finance\Tax\Models\TaxLiability;
use App\Modules\Finance\Tax\Models\TaxReconciliation;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxReturn;
use App\Modules\Finance\Tax\Services\TaxAudit;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * §15 Evidence on any tax record — registration certificates, SARS notices on liabilities,
 * reconciliation workings. Files go through Document Management (type guard, malware scan,
 * quotas, its own access rules for viewing) and are linked, never copied. Evidence is added, never
 * replaced or removed here: what supported an approved figure stays with it.
 */
class TaxEvidenceController extends TaxController
{
    /** type => [model, permission to add evidence] */
    private const TYPES = [
        'registrations' => [TaxRegistration::class, TaxPermissions::MANAGE_REGISTRATIONS],
        'liabilities' => [TaxLiability::class, TaxPermissions::RECONCILE],
        'reconciliations' => [TaxReconciliation::class, TaxPermissions::RECONCILE],
        'returns' => [TaxReturn::class, TaxPermissions::PREPARE],
        'computations' => [TaxComputation::class, TaxPermissions::PREPARE],
    ];

    public function store(Request $request, string $type, string $uuid, DocumentService $documents): RedirectResponse
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        [$class, $permission] = self::TYPES[$type];
        $this->allow($permission, "evidence.{$type}");

        /** @var Model $record */
        $record = $class::query()->where('uuid', $uuid)->firstOrFail();
        $d = $request->validate(['file' => ['required', 'file', 'max:20480'], 'title' => ['required', 'string', 'max:200']]);

        $stored = $documents->store($request->file('file'), ['title' => $d['title'], 'description' => 'Tax evidence', 'is_confidential' => true]);
        if (! $stored['document']) {
            return back()->with('error', $stored['error'] ?? 'The file could not be stored.');
        }

        $record->attachDocument($stored['document'], 'tax:evidence');
        app(TaxAudit::class)->record('evidence.attached', $record, ['period_id' => $record->period_id ?? null,
            'context' => ['document_id' => $stored['document']->id, 'title' => $d['title']]]);

        return back()->with('success', 'Filed in Document Management and linked.');
    }

    /** Evidence for a record, shaped for the screens. */
    public static function listFor(Model $record): array
    {
        return $record->libraryDocuments()->get(['documents.id', 'documents.uuid', 'documents.title', 'documents.created_at'])
            ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'added' => $d->created_at?->toDateString(),
                'url' => route('document-management.documents.preview', $d)])->values()->all();
    }
}
