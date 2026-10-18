<?php
namespace App\Modules\Policies\Actions\Policy;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyVersion;
use App\Modules\Policies\Models\PolicyReviewer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class CreatePolicyAction {
    public function execute(array $data, int $createdBy): Policy {
        return DB::transaction(function () use ($data, $createdBy) {
            $policy = Policy::create([
                'name'                 => $data['name'],
                'slug'                 => Str::slug($data['name']),
                'description'          => $data['description'] ?? null,
                'category_id'          => $data['category_id'],
                'department_id'        => $data['department_id'] ?? null,
                'owner_id'             => $data['owner_id'],
                'effective_date'       => $data['effective_date'],
                'review_date'          => $data['review_date'] ?? null,
                'status'               => $data['status'] ?? 'draft',
                'requires_attestation' => $data['requires_attestation'] ?? false,
                'tags'                 => $data['tags'] ?? null,
            ]);

            $version = PolicyVersion::create([
                'policy_id'      => $policy->id,
                'version_number' => '1.0',
                'change_summary' => 'Initial version',
                'snapshot'       => $policy->toArray(),
                'created_by'     => $createdBy,
            ]);

            $policy->update(['current_version_id' => $version->id]);

            if (! empty($data['reviewer_ids'])) {
                $reviewers = collect($data['reviewer_ids'])->map(fn($id) => [
                    'policy_id'   => $policy->id,
                    'user_id'     => $id,
                    'role'        => 'reviewer',
                    'assigned_at' => now(),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                PolicyReviewer::insert($reviewers->all());
            }

            return $policy->load(['category','version','reviewers']);
        });
    }
}
