<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Services\ContractService;
use Inertia\Inertia;
use Inertia\Response;

class ContractDashboardController extends Controller
{
    public function __construct(private readonly ContractService $contracts) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Contract::class);

        $stats = $this->contracts->dashboardStats();

        $recent = Contract::with(['type', 'category'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Contract $c) => [
                'id' => $c->id, 'uuid' => $c->uuid,
                'contract_number' => $c->contract_number,
                'title' => $c->title,
                'contract_status' => $c->contract_status,
                'contract_value' => $c->contract_value,
                'end_date' => $c->end_date?->toDateString(),
                'type' => $c->type?->name,
            ]);

        return Inertia::render('Modules/ContractManagement/Dashboard', [
            'stats' => $stats,
            'recent' => $recent,
        ]);
    }
}
