<?php
namespace App\Modules\Policies\Actions\Policy;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyDocument;
use App\Modules\Policies\Jobs\ExtractPolicyClausesJob;
use App\Core\AI\Registry\AgentRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
class UploadPolicyDocumentAction {
    public function execute(Policy $policy, UploadedFile $file, int $uploadedBy): PolicyDocument {
        $path = Storage::disk('local')->putFile(
            "policies/{$policy->id}/documents",
            $file
        );

        $document = PolicyDocument::create([
            'policy_id'          => $policy->id,
            'version_id'         => $policy->current_version_id,
            'filename'           => $file->getClientOriginalName(),
            'disk'               => 'local',
            'path'               => $path,
            'mime_type'          => $file->getMimeType(),
            'size_bytes'         => $file->getSize(),
            'extraction_status'  => 'pending',
            'uploaded_by'        => $uploadedBy,
        ]);

        // Run AI extraction immediately so the review UI can show results right away.
        (new ExtractPolicyClausesJob($document))
            ->handle(app(AgentRegistry::class));

        return $document;
    }
}
