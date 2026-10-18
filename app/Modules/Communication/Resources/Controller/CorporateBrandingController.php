<?php

namespace App\Modules\Communication\Resources\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Resources\Requests\CorporateBrandingRequest;
use App\Modules\Communication\Resources\Services\CorporateBrandingService;

class CorporateBrandingController extends Controller
{
    public function __construct(
        protected CorporateBrandingService $service
    ) {}

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    public function store(CorporateBrandingRequest $request)
    {
        $data = $request->validated();

        $files = [
            'logo_light' => $request->file('logo_light'),
            'logo_dark'  => $request->file('logo_dark'),
            'favicon'    => $request->file('favicon'),
        ];

        $branding = $this->service->create($data, $files);

        return redirect()->back();
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(CorporateBrandingRequest $request, int $id)
    {
        $data = $request->validated();

        $files = [
            'logo_light' => $request->file('logo_light'),
            'logo_dark'  => $request->file('logo_dark'),
            'favicon'    => $request->file('favicon'),
        ];

        $branding = $this->service->update($id, $data, $files);

        return redirect()->back();
    }
}
