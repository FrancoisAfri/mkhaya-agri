<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Services\CrmDuplicateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Duplicate detection + merge UI (spec §1 / §12).
 */
class DuplicateController extends Controller
{
    public function __construct(private CrmDuplicateService $service)
    {
    }

    public function index(Request $request): Response
    {
        $entityType = $request->input('entity_type', 'lead');
        if (! in_array($entityType, $this->service->entityTypes(), true)) {
            $entityType = 'lead';
        }

        $counts = [];
        foreach ($this->service->entityTypes() as $t) {
            $counts[$t] = $this->service->countDuplicates($t);
        }

        return Inertia::render('Modules/CRM/Duplicates', [
            'entityType' => $entityType,
            'entityTypes'=> $this->service->entityTypes(),
            'clusters'   => $this->service->clusters($entityType),
            'counts'     => $counts,
        ]);
    }

    public function merge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entity_type'  => 'required|in:lead,contact,account',
            'primary_id'   => 'required|integer',
            'duplicate_id' => 'required|integer|different:primary_id',
        ]);

        try {
            $this->service->merge($data['entity_type'], (int) $data['primary_id'], (int) $data['duplicate_id']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Records merged.');
    }
}
