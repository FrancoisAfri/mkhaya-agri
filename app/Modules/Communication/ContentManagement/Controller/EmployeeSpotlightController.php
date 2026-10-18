<?php

namespace App\Modules\Communication\ContentManagement\Controller;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Http\Controllers\Controller;
use App\Modules\Communication\ContentManagement\Models\EmployeeSpotlight;
use App\Modules\Communication\ContentManagement\Requests\EmployeeSpotlightRequest;
use App\Modules\Communication\ContentManagement\Services\EmployeeSpotlightService;
use Illuminate\Http\Request;
use Inertia\Inertia;


class EmployeeSpotlightController extends Controller
{
    public function __construct(
        protected EmployeeSpotlightService $service
    ) {}



    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    public function store(EmployeeSpotlightRequest $request)
    {
        $this->service->store($request->validated());

        return back()->with('success', 'Spotlight created successfully');
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(EmployeeSpotlightRequest $request, int $id)
    {
        $this->service->update( $request->validated(),$id);

        return back()->with('success', 'Spotlight updated successfully');
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    public function destroy(EmployeeSpotlight $spotlight)
    {
        $spotlight->delete();

        return back()->with('success', 'Spotlight deleted successfully');
    }
        private function getCompanyUnits(): array
    {
        $types = ['division', 'department', 'team', 'section', 'unit'];

        $companyUnits = [];
        foreach ($types as $type) {
            $companyUnits[$type . 's'] = CompanyUnit::where('type', $type)
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id', 'type']);
        }

        return $companyUnits;
    }
}
