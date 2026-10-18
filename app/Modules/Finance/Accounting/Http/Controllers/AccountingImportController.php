<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Services\ExternalImportService;
use App\Modules\Finance\Accounting\Services\PartySyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Migration wizard: import customers, suppliers and items from a Sage / Xero / Zoho export.
 * Preview maps the file's columns onto our fields; commit upserts. The upload is stashed
 * under a token between the two steps.
 */
class AccountingImportController extends Controller
{
    public function index(PartySyncService $sync): Response
    {
        return Inertia::render('Modules/Finance/Accounting/Import', [
            'systems'  => ExternalImportService::SYSTEMS,
            'entities' => ExternalImportService::ENTITIES,
            // Drives the "also create these in CRM" option on the customer import.
            'crmActive' => $sync->crmActive(),
        ]);
    }

    public function preview(Request $request, ExternalImportService $service): RedirectResponse
    {
        $data = $this->validateUpload($request);

        $token = (string) Str::uuid();
        $request->file('file')->storeAs('fin-imports', $token . '.' . $this->ext($request));

        $preview = $service->preview($request->file('file'), $data['system'], $data['entity']);

        return back()->with('importPreview', array_merge($preview, [
            'token'     => $token,
            'system'    => $data['system'],
            'entity'    => $data['entity'],
            'file_name' => $request->file('file')->getClientOriginalName(),
        ]));
    }

    public function commit(Request $request, ExternalImportService $service, PartySyncService $sync): RedirectResponse
    {
        $data = $request->validate([
            'token'    => 'required|string',
            'system'   => 'required|string|in:' . implode(',', ExternalImportService::SYSTEMS),
            'entity'   => 'required|string|in:' . implode(',', ExternalImportService::ENTITIES),
            'ext'      => 'required|string|in:csv,txt,xlsx,xls',
            'sync_crm' => 'sometimes|boolean',
        ]);

        $path = 'fin-imports/' . $data['token'] . '.' . $data['ext'];
        abort_unless(Storage::exists($path), 422, 'Upload expired — please re-upload the file.');

        // Copy the stored contents to a real temp file, then wrap it as an UploadedFile so the
        // service reads it identically. (Storage::path() is not guaranteed to be an absolute,
        // readable path on every disk config, so don't hand it straight to the reader.)
        $tmp = tempnam(sys_get_temp_dir(), 'finimp') . '.' . $data['ext'];
        file_put_contents($tmp, Storage::get($path));
        try {
            $uploaded = new \Illuminate\Http\UploadedFile($tmp, 'import.' . $data['ext'], null, null, true);
            $result = $service->import($uploaded, $data['system'], $data['entity']);
        } finally {
            @unlink($tmp);
            Storage::delete($path);
        }

        // Opt-in: put the customers we just brought in on the CRM's books too.
        $crmNote = '';
        if (($data['sync_crm'] ?? false) && $data['entity'] === 'customers') {
            $crm = $sync->pushCustomersToCrm($result['party_ids'] ?? []);
            if ($crm['created'] || $crm['linked']) {
                $crmNote = " CRM: {$crm['created']} account(s) created, {$crm['linked']} linked to existing.";
            }
        }

        $skipped = count($result['skipped']);
        return back()->with('success', "Imported {$result['imported']} new, {$result['updated']} updated"
            . ($skipped ? ", {$skipped} skipped." : '.') . $crmNote)
            ->with('importIssues', $result['skipped']);
    }

    private function validateUpload(Request $request): array
    {
        $request->validate([
            'file'   => 'required|file|max:20480',
            'system' => 'required|string|in:' . implode(',', ExternalImportService::SYSTEMS),
            'entity' => 'required|string|in:' . implode(',', ExternalImportService::ENTITIES),
        ]);

        // Validate by extension (Excel-exported CSVs are often sniffed as octet-stream).
        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        abort_unless(in_array($ext, ['csv', 'txt', 'xlsx', 'xls'], true), 422, 'Please upload a CSV or Excel file.');

        return $request->only('system', 'entity');
    }

    private function ext(Request $request): string
    {
        return strtolower((string) $request->file('file')->getClientOriginalExtension());
    }
}
