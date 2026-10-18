<?php

namespace App\Modules\Communication\Resources\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Resources\Models\Resource;
use App\Modules\Communication\Resources\Requests\ResourcesRequest;
use App\Modules\Communication\Resources\Services\ResourceDashboardService;
use App\Modules\Communication\Resources\Services\ResourceService;
use Inertia\Inertia;
use Inertia\Response;

class ResourcesController extends Controller
{
    public function __construct(
        protected ResourceService $resourceService,
        protected ResourceDashboardService $service
    ) {}

    public function index(): Response
    {
        return Inertia::render('Modules/Communication/Resources/Resource/Dashboard/index', [
            ...$this->service->getDashboardData(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    public function store(ResourcesRequest $request)
    {
        $data = $request->validated();

        $file = $request->file('file');
        // dd($data);

        $resource = $this->resourceService->create($data, $file);

        return redirect()->back()->with("success", 'Resources Created Successfully');
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(ResourcesRequest $request, int $id)
    {
        $data = $request->validated();

        $file = $request->file('file');

        $resource = $this->resourceService->update(
            $id,
            $data,
            $file
        );

        return redirect()->back()->with("success", 'Resources Updated Successfully');
    }
}
