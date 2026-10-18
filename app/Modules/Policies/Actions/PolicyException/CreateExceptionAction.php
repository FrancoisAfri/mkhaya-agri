<?php
namespace App\Modules\Policies\Actions\PolicyException;
use App\Modules\Policies\Models\PolicyException;
class CreateExceptionAction {
    public function execute(array $data, int $requestedBy): PolicyException {
        return PolicyException::create([
            'policy_id'   => $data['policy_id'],
            'rule_id'     => $data['rule_id'] ?? null,
            'requested_by'=> $requestedBy,
            'reason'      => $data['reason'],
            'evidence'    => $data['evidence'] ?? null,
            'valid_from'  => $data['valid_from'],
            'valid_until' => $data['valid_until'] ?? null,
            'status'      => 'pending',
        ]);
    }
}
