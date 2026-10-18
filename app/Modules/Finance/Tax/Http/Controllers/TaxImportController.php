<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxImportBatch;
use App\Modules\Finance\Tax\Services\TaxImportService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** §23 Historical data import: template, preview with row errors, second-person sign-off. */
class TaxImportController extends TaxController
{
    public function __construct(private readonly TaxImportService $imports)
    {
    }

    public function index(Request $request): Response
    {
        // Uploading is administration; signing a batch off is an approver's job, and the approver needs
        // this screen to see the preview they are signing (§23 second person).
        $user = $request->user();
        $this->allow($user->can(TaxPermissions::APPROVE) ? TaxPermissions::APPROVE : TaxPermissions::ADMIN, 'import.view');
        $names = collect($this->people())->pluck('name', 'id');
        $open = $request->query('batch') ? TaxImportBatch::where('uuid', $request->query('batch'))->first() : null;

        return Inertia::render('Modules/Finance/Tax/Import', [
            'batches' => TaxImportBatch::query()->orderByDesc('id')->limit(50)->get()->map(fn ($b) => [
                'uuid' => $b->uuid, 'kind' => $b->kind, 'source_system' => $b->source_system, 'file_name' => $b->file_name, 'status' => $b->status,
                'rows' => $b->row_count, 'valid' => $b->valid_count, 'errors' => $b->error_count, 'total' => (string) $b->amount_total,
                'uploaded_by' => $names[$b->uploaded_by] ?? null, 'uploaded_by_id' => $b->uploaded_by, 'signed_off_by' => $names[$b->signed_off_by] ?? null,
                'created_at' => $b->created_at?->toDateTimeString(), 'summary' => $b->summary,
            ]),
            'open' => $open ? ['uuid' => $open->uuid, 'kind' => $open->kind, 'status' => $open->status, 'headers' => TaxImportService::KINDS[$open->kind],
                'rows' => array_slice($open->rows, 0, 500), 'total' => (string) $open->amount_total, 'uploaded_by_id' => $open->uploaded_by] : null,
            'kinds' => array_keys(TaxImportService::KINDS),
            'can' => ['commit' => $user->can(TaxPermissions::APPROVE), 'upload' => $user->can(TaxPermissions::ADMIN)],
            'me' => $request->user()->id,
        ]);
    }

    public function template(string $kind): StreamedResponse
    {
        $this->allow(TaxPermissions::ADMIN, 'import.template');
        abort_unless(isset(TaxImportService::KINDS[$kind]), 404);
        [$headers, $example] = $this->imports->template($kind);

        return $this->csv("tax-import-template-{$kind}.csv", $headers, [$example]);
    }

    public function preview(Request $request): RedirectResponse
    {
        $this->allow(TaxPermissions::ADMIN, 'import.preview');
        $d = $request->validate(['kind' => ['required', 'in:' . implode(',', array_keys(TaxImportService::KINDS))],
            'source_system' => ['required', 'string', 'max:120'], 'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);

        try {
            $batch = $this->imports->preview($d['kind'], $request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName(), $d['source_system']);
        } catch (TaxControlException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.import.index', ['batch' => $batch->uuid])
            ->with($batch->error_count ? 'error' : 'success', "{$batch->valid_count} of {$batch->row_count} row(s) valid" . ($batch->error_count ? "; {$batch->error_count} with errors — they will not be imported." : '.'));
    }

    public function commit(Request $request, TaxImportBatch $batch): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE, 'import.commit');
        $d = $request->validate(['comment' => ['required', 'string', 'min:10', 'max:2000']]);

        return $this->attempt(fn () => "Imported {$this->imports->commit($batch, $d['comment'])->summary['created']} record(s).", 'Imported.');
    }

    public function reject(Request $request, TaxImportBatch $batch): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE, 'import.reject');
        $d = $request->validate(['comment' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->attempt(fn () => $this->imports->reject($batch, $d['comment']), 'Import rejected; nothing was written.');
    }
}
