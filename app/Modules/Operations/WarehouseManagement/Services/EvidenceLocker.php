<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Models\Evidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Evidence behind a loss, a return or a recall (WMS-081). Files go to the documents disk -- the
 * encrypted tenant file store -- and the record of them can never be edited or removed, so an
 * investigation always sees what the decision was made on.
 */
class EvidenceLocker
{
    /** Images, PDFs and the office documents claims are made on; nothing executable. */
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf', 'text/plain',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'];

    public const MAX_BYTES = 15 * 1024 * 1024;

    public function attach(string $subjectType, int $subjectId, int $companyId, UploadedFile $file, User $by, string $kind = 'PHOTO', ?string $caption = null): Evidence
    {
        if (! in_array($subjectType, Evidence::SUBJECTS, true)) {
            throw new RuntimeException('Evidence cannot be attached to that.');
        }
        // Every upload passes the platform's malware scanner (structural checks; ClamAV where configured) -- WMS-221.
        if (($why = app(\App\Core\Storage\MalwareScanner::class)->scan($file)) !== null) {
            throw new RuntimeException("The file was refused: {$why}");
        }
        if (! in_array($kind, Evidence::KINDS, true)) {
            throw new RuntimeException("Unknown evidence type {$kind}.");
        }
        if (! in_array($file->getMimeType(), self::MIMES, true)) {
            throw new RuntimeException("{$file->getClientOriginalName()} is not a photograph or document this can keep.");
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException("{$file->getClientOriginalName()} is larger than 15 MB.");
        }

        $path = 'wms/evidence/'.strtolower($subjectType).'/'.$subjectId.'/'.Str::uuid().'-'
            .Str::limit(preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()), 80, '');
        Storage::disk('documents')->put($path, $file->get());

        return Evidence::create([
            'company_id' => $companyId, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'kind' => $kind, 'path' => $path,
            'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
            'caption' => $caption, 'uploaded_by' => $by->id,
        ]);
    }

    /** @return Collection<int, array> */
    public function for(string $subjectType, int $subjectId): Collection
    {
        return Evidence::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->with('uploader:id,email')->orderBy('id')->get()
            ->map(fn (Evidence $e) => ['id' => $e->uuid, 'kind' => $e->kind, 'name' => $e->original_name, 'caption' => $e->caption,
                'by' => $e->uploader?->email, 'at' => $e->created_at?->toIso8601String(), 'size' => (int) $e->size]);
    }
}
