<?php

namespace App\Modules\HR\Contracts\Actions;

use App\Modules\HR\Contracts\Jobs\SendContractNotificationJob;
use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Contracts\Models\SignatureRequest;
use App\Modules\HR\Recruitment\Enums\ContractStatus;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendContractAction
{
    use PolicyValidationTrait;

    /**
     * Transition contract to sent_for_signing, create signature requests
     * for each signatory with SHA-256 hashed tokens, and dispatch notifications.
     *
     * @param  array<array{type: string, user_id: int|null, candidate_id: int|null}>  $signatories
     */
    public function execute(Contract $contract, array $signatories, int $senderId): Contract
    {
        if ($contract->status !== ContractStatus::Draft && $contract->status !== ContractStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => "Contract must be in draft or approved status to send.",
            ]);
        }

        if (empty($signatories)) {
            throw ValidationException::withMessages([
                'signatories' => 'At least one signatory is required.',
            ]);
        }

        $this->validatePolicyAction(
            module: 'Contracts',
            action: 'contract.sent',
            payload: ['contract_id' => $contract->id, 'sender_id' => $senderId],
            userId:  $senderId,
        );

        return DB::transaction(function () use ($contract, $signatories, $senderId) {
            $rawTokens = [];

            foreach ($signatories as $signatory) {
                $rawToken = Str::random(64);

                /*
                 * Built and saved once, with the token already on it.
                 *
                 * `create()` followed by assigning the token was two writes, and the first of them
                 * inserted a row with no `token_hash` — a NOT NULL column. Sending a contract for
                 * signature therefore failed outright. Keeping it to one insert also closes the
                 * window in which a signature request existed that nothing could ever sign.
                 *
                 * `token_hash` and `token_expires_at` stay out of $fillable on purpose: they are
                 * this action's to set, never a request payload's.
                 */
                $request = new SignatureRequest([
                    'contract_id'    => $contract->id,
                    'signatory_type' => $signatory['type'],
                    'user_id'        => $signatory['user_id'] ?? null,
                    'candidate_id'   => $signatory['candidate_id'] ?? null,
                ]);

                // Store only the hash — never the raw token in the database
                $request->token_hash       = hash('sha256', $rawToken);
                $request->token_expires_at = now()->addDays(7);
                $request->save();

                $rawTokens[$request->id] = $rawToken;
            }

            $contract->status   = ContractStatus::SentForSigning->value;
            $contract->sent_at  = now();
            $contract->expires_at = now()->addDays(7);
            $contract->save();

            $this->dispatchPolicyEvent(
                module:      'Contracts',
                action:      'contract.sent',
                subjectId:   $contract->id,
                subjectType: Contract::class,
                payload:     ['sender_id' => $senderId, 'signatory_count' => count($signatories)],
                userId:      $senderId,
            );

            // Dispatch notifications after commit — carries raw tokens, not hashes
            SendContractNotificationJob::dispatch($contract, $rawTokens)->onQueue('notifications');

            return $contract->fresh();
        });
    }
}
