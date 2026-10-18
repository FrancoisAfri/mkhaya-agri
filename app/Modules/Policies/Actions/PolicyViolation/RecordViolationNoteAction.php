<?php
namespace App\Modules\Policies\Actions\PolicyViolation;
use App\Modules\Policies\Models\{PolicyViolation,ViolationNote};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
class RecordViolationNoteAction {
    public function execute(PolicyViolation $violation, array $data, int $authorId, ?UploadedFile $attachment = null): ViolationNote {
        $attachmentPath = null;
        if ($attachment) {
            $attachmentPath = Storage::disk('local')->putFile(
                "violations/{$violation->id}/evidence",
                $attachment
            );
        }

        return ViolationNote::create([
            'violation_id'    => $violation->id,
            'author_id'       => $authorId,
            'content'         => $data['content'],
            'type'            => $data['type'],
            'attachment_path' => $attachmentPath,
        ]);
    }
}
