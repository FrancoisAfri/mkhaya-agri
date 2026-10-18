<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Services\DocumentDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The module's front page, and the storage screen behind it.
 *
 * Every number is counted through the same visibility scope as the library. A total of "1,482
 * documents" shown to somebody who can open four hundred of them discloses how much they are not
 * being shown, so the only honest total is the one that changes depending on who is looking.
 */
class DocumentDashboardController extends Controller
{
    public function __construct(private readonly DocumentDashboard $dashboard)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        return Inertia::render('Modules/DocumentManagement/Pages/Dashboard', [
            'stats' => $this->dashboard->for($request->user()),
        ]);
    }

    /**
     * Storage: what is being used, by what, and which files are the largest.
     *
     * Its own screen rather than a panel on the dashboard, because the question it answers — "we
     * are near the limit, what do I do about it" — needs the list of offenders next to the number.
     */
    public function storage(Request $request): Response
    {
        // Reading how much of the company's storage is used is a settings question, not a document
        // one: it says nothing about any particular document and everything about the account.
        $this->authorize('manageSettings', Document::class);

        $stats = $this->dashboard->for($request->user());

        return Inertia::render('Modules/DocumentManagement/Pages/Storage', array_merge([
            'storage' => $stats['storage'],
            'largest' => $this->dashboard->largestFiles(),
        ], app(DocumentQuotaController::class)->payload()));
    }
}
