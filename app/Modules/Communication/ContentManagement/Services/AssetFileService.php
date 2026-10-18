<?php

namespace App\Modules\Communication\ContentManagement\Services;

use App\Core\User\Trait\FileStorage;
use App\Modules\Communication\ContentManagement\Models\AssetsFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetFileService
{
    use FileStorage;

    /*
    |--------------------------------------------------------------------------
    | IMAGE
    |--------------------------------------------------------------------------
    */

    public function saveImage(UploadedFile $image, ?AssetsFile $asset = null): AssetsFile
    {
        $fileData = $this->saveFileToDatabase($image);

        if ($this->isUpdate($asset)) {
            $this->deleteOldFiles($asset->image);

            $asset->update([
                'image' => [$fileData],
            ]);

            return $asset;
        }

        return AssetsFile::create([
            'image' => [$fileData],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT
    |--------------------------------------------------------------------------
    */

    public function saveDocument(UploadedFile $document, ?AssetsFile $asset = null): AssetsFile
    {
        $fileData = $this->saveFileToDatabase($document);

        if ($this->isUpdate($asset)) {
            $this->deleteOldFiles($asset->document);

            $asset->update([
                'document' => [$fileData],
            ]);

            return $asset;
        }

        return AssetsFile::create([
            'document' => [$fileData],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | VIDEO
    |--------------------------------------------------------------------------
    */

    public function saveVideo(UploadedFile $video, ?AssetsFile $asset = null): AssetsFile
    {
        $this->validateVideo($video);

        $fileName = Str::uuid() . '.' . $video->getClientOriginalExtension();
        $path = $video->storeAs('videos', $fileName, 'public');

        $fileData = [
            'path' => $path,
            'original_name' => $video->getClientOriginalName(),
            'mime' => $video->getMimeType(),
            'size' => $video->getSize(),
        ];

        if ($this->isUpdate($asset)) {
            if ($asset->video && isset($asset->video['path'])) {
                Storage::disk('public')->delete($asset->video['path']);
            }

            $asset->update([
                'video' => $fileData,
            ]);

            return $asset;
        }

        return AssetsFile::create([
            'video' => $fileData,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER : CREATE ou UPDATE
    |--------------------------------------------------------------------------
    */

    private function isUpdate(?AssetsFile $asset): bool
    {
        return $asset !== null && $asset->exists;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE OLD FILES
    |--------------------------------------------------------------------------
    */

    private function deleteOldFiles(?array $files): void
    {
        if (!$files) return;

        foreach ($files as $file) {
            if (isset($file['path'])) {
                Storage::disk('public')->delete($file['path']);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATION VIDEO
    |--------------------------------------------------------------------------
    */

private function validateVideo(UploadedFile $video): void
{
    $allowed = [
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/webm',
        'video/mpeg',
    ];

    throw_if(
        !in_array($video->getMimeType(), $allowed),
        \InvalidArgumentException::class,
        "Video type not supported: " . $video->getMimeType()
    );

    throw_if(
        $video->getSize() > 100 * 1024 * 1024,
        \InvalidArgumentException::class,
        "Video too heavy (max 100MB)"
    );
}
}
