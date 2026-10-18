<?php

namespace App\Modules\Communication\ContentManagement\Services;

use App\Modules\Communication\ContentManagement\Models\CeoMessage;
use App\Modules\Communication\ContentManagement\Models\AssetsFile;

class CeoMessageService
{
    protected $assetFileService;

    public function __construct(AssetFileService $assetFileService)
    {
        $this->assetFileService = $assetFileService;
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */
    public function ceoMessage()
    {
        return CeoMessage::with('assetsFile')->first();
    }

    public function storeCeoMessage($request)
    {
        $assetsFile = null;

        if ($request->hasFile('video')) {
            $assetsFile = $this->assetFileService->saveVideo($request->file('video'));
        }
        if ($request->hasFile('image')) {
            $assetsFile = $this->assetFileService->saveImage($request->file('image'));
        }

        return CeoMessage::create([
            'message' => $request->input('message'),
            'assets_file_id' => $assetsFile?->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function updateCeoMessage($request, int $id)
    {
        $ceoMessage = CeoMessage::findOrFail($id);

        $asset = $ceoMessage->assets_file_id
            ? AssetsFile::find($ceoMessage->assets_file_id)
            : null;

        if ($request->hasFile('video')) {
            $asset = $this->assetFileService->saveVideo(
                $request->file('video'),
                $asset
            );
        } elseif ($request->hasFile('image')) {
            $asset = $this->assetFileService->saveImage(
                $request->file('image'),
                $asset
            );
        }

        $ceoMessage->update([
            'message'        => $request->input('message'),
            'assets_file_id' => $asset?->id ?? $ceoMessage->assets_file_id,
        ]);

        return $ceoMessage;
    }
}
