<?php
namespace App\Modules\Policies\Actions\Policy;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyVersion;
use Illuminate\Support\Facades\DB;
class UpdatePolicyAction {
    public function execute(Policy $policy, array $data, int $updatedBy): Policy {
        return DB::transaction(function () use ($policy, $data, $updatedBy) {
            $policy->update(array_filter([
                'name'                 => $data['name'] ?? null,
                'description'          => $data['description'] ?? null,
                'category_id'          => $data['category_id'] ?? null,
                'department_id'        => $data['department_id'] ?? null,
                'owner_id'             => $data['owner_id'] ?? null,
                'effective_date'       => $data['effective_date'] ?? null,
                'review_date'          => $data['review_date'] ?? null,
                'status'               => $data['status'] ?? null,
                'requires_attestation' => $data['requires_attestation'] ?? null,
                'tags'                 => $data['tags'] ?? null,
            ], fn($v) => !is_null($v)));

            // Bump version on every meaningful update
            $latest = PolicyVersion::where('policy_id', $policy->id)
                ->orderByDesc('id')->first();
            [$major, $minor] = explode('.', $latest->version_number ?? '1.0');
            $newVersion = $major . '.' . ((int)$minor + 1);

            $version = PolicyVersion::create([
                'policy_id'      => $policy->id,
                'version_number' => $newVersion,
                'change_summary' => $data['change_summary'] ?? null,
                'snapshot'       => $policy->fresh()->toArray(),
                'created_by'     => $updatedBy,
            ]);

            $policy->update(['current_version_id' => $version->id]);

            return $policy->refresh()->load(['category','version','reviewers']);
        });
    }
}
