<?php

namespace App\Modules\Communication\Resources\Services;

use App\Modules\Communication\Resources\Models\CorporateBranding;
use App\Modules\Communication\ContentManagement\Services\AssetFileService;
use Illuminate\Support\Facades\Auth;

class CorporateBrandingService
{
    public function __construct(
        protected AssetFileService $assetService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function create(array $data, array $files = []): CorporateBranding
    {
        $data = $this->handleAssets($data, $files);

        $data['user_id'] = Auth::id();

        return CorporateBranding::create($data);
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(int $id , array $data, array $files = []): CorporateBranding
    {
        $branding=CorporateBranding::findOrFail($id);
        $data = $this->handleAssets($data, $files, $branding);

        $branding->update($data);

        return $branding;
    }

    /*
    |--------------------------------------------------------------------------
    | HANDLE ASSETS
    |--------------------------------------------------------------------------
    */

    private function handleAssets(array $data, array $files, ?CorporateBranding $branding = null): array
    {
        // LOGO LIGHT
        if (isset($files['logo_light'])) {

            $asset = $this->assetService->saveImage(
                $files['logo_light'],
                $branding?->logoLight
            );

            $data['logo_light_id'] = $asset->id;
        }

        // LOGO DARK
        if (isset($files['logo_dark'])) {

            $asset = $this->assetService->saveImage(
                $files['logo_dark'],
                $branding?->logoDark
            );

            $data['logo_dark_id'] = $asset->id;
        }

        // FAVICON
        if (isset($files['favicon'])) {

            $asset = $this->assetService->saveImage(
                $files['favicon'],
                $branding?->favicon
            );

            $data['favicon_id'] = $asset->id;
        }

        return $data;
    }
}
