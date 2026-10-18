<?php
namespace App\Modules\Policies\Actions\PolicyClause;
use App\Modules\Policies\Models\PolicyClause;
use Illuminate\Support\Facades\DB;
class ApproveClausesAction {
    public function execute(array $clauseIds, int $approvedBy): int {
        return DB::transaction(function () use ($clauseIds, $approvedBy) {
            return PolicyClause::whereIn('id', $clauseIds)
                ->where('approved', false)
                ->update([
                    'approved'    => true,
                    'approved_by' => $approvedBy,
                    'approved_at' => now(),
                ]);
        });
    }
}
