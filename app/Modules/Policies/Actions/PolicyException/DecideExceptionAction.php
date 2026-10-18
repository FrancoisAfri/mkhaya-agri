<?php
namespace App\Modules\Policies\Actions\PolicyException;
use App\Modules\Policies\Models\PolicyException;
use Illuminate\Support\Facades\DB;
class DecideExceptionAction {
    public function execute(PolicyException $exception, string $decision, int $decidedBy, ?string $notes = null): PolicyException {
        return DB::transaction(function () use ($exception, $decision, $decidedBy, $notes) {
            $exception->update([
                'status'         => $decision,       // 'approved' | 'rejected'
                'approved_by'    => $decidedBy,
                'decided_at'     => now(),
                'decision_notes' => $notes,
            ]);
            return $exception->refresh();
        });
    }
}
