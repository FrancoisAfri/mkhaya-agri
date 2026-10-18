<?php

namespace App\Modules\Communication\ContentManagement\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Communication\ContentManagement\Services\CommunicationDashboardService;
use Inertia\Inertia;
use Inertia\Response;

class CommunicationDashboardController extends Controller
{
    public function __construct(
        protected CommunicationDashboardService $service
    ) {}

    public function index(): Response
    {
        return Inertia::render('Modules/Communication/ContentManagement/Dashbaord/index', [
            ...$this->service->getDashboardData(),
        ]);
    }
}
