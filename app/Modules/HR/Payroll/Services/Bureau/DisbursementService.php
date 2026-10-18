<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauDisbursement;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Support\PayRunLifecycle;
use App\Modules\HR\Payroll\Models\Bureau\BureauPaymentIntegration;
use App\Modules\HR\Payroll\Services\Bureau\Payouts\PayoutProviderManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Real-time salary payouts. A client connects their own payout provider
 * (credentials encrypted); a finalised run can then be submitted as a payout
 * batch. Mirrors the Mkhaya Move / travel integration pattern.
 */
class DisbursementService
{
    public function __construct(private readonly PayoutProviderManager $providers)
    {
    }

    /** Save (or update) a company's payout-provider credentials. Secrets only overwrite when supplied. */
    public function connect(BureauCompany $company, array $data): BureauPaymentIntegration
    {
        if (! in_array($data['provider'] ?? '', BureauPaymentIntegration::PROVIDERS, true)) {
            throw ValidationException::withMessages(['provider' => 'Unknown payout provider.']);
        }

        $integration = BureauPaymentIntegration::firstOrNew([
            'bureau_company_id' => $company->id,
            'provider'          => $data['provider'],
        ]);
        if (! empty($data['api_key'])) {
            $integration->api_key = $data['api_key'];
        }
        if (! empty($data['api_secret'])) {
            $integration->api_secret = $data['api_secret'];
        }
        $integration->fill([
            'label'      => $data['label'] ?? ucfirst($data['provider']),
            'base_url'   => $data['base_url'] ?? $integration->base_url,
            'sandbox'    => $data['sandbox'] ?? true,
            'created_by' => $integration->created_by ?? Auth::id(),
        ])->save();

        return $integration;
    }

    public function setActive(BureauPaymentIntegration $integration, bool $active): BureauPaymentIntegration
    {
        $integration->update(['active' => $active]);

        return $integration;
    }

    /** Poll the provider for the latest batch status and update the record. */
    public function refreshStatus(BureauDisbursement $disbursement): BureauDisbursement
    {
        $integration = BureauPaymentIntegration::where('bureau_company_id', $disbursement->bureau_company_id)
            ->where('provider', $disbursement->provider)->first();
        if (! $integration || ! $integration->hasCredentials() || blank($disbursement->reference)) {
            throw ValidationException::withMessages(['payout' => 'Cannot refresh: missing provider credentials or batch reference.']);
        }

        $result = $this->providers->adapter($disbursement->provider)->batchStatus($integration, $disbursement->reference);
        $disbursement->update(['status' => $this->normalise($result['status']), 'response' => $result['raw'] ?? $disbursement->response]);

        return $disbursement;
    }

    /** Apply a provider webhook status update (matched by batch reference). */
    public function applyWebhookStatus(string $provider, string $reference, string $status, array $raw = []): ?BureauDisbursement
    {
        $disbursement = BureauDisbursement::where('provider', $provider)->where('reference', $reference)->first();
        if (! $disbursement) {
            return null;
        }
        $was = $disbursement->status;
        $disbursement->update(['status' => $this->normalise($status), 'response' => $raw ?: $disbursement->response]);

        // What the provider said back, and when. A failed batch discovered days later is answered by
        // this line — the webhook arrives with nobody logged in, so nothing else records it.
        if ($was !== $disbursement->status && $run = $disbursement->run) {
            app(BureauAuditService::class)->run(
                $run,
                BureauAuditService::DISBURSEMENT_RESPONSE,
                "{$provider} reported batch {$reference} as {$disbursement->status}.",
                ['provider' => $provider, 'reference' => $reference, 'from' => $was, 'to' => $disbursement->status],
            );
        }

        return $disbursement;
    }

    /** Normalise provider-specific status strings to submitted|processing|paid|failed. */
    private function normalise(string $status): string
    {
        $s = strtolower(trim($status));

        return match (true) {
            in_array($s, ['paid', 'completed', 'complete', 'settled', 'success', 'successful'], true) => 'paid',
            in_array($s, ['failed', 'failure', 'error', 'declined', 'rejected', 'cancelled'], true)   => 'failed',
            in_array($s, ['submitted', 'queued', 'created'], true)                                     => 'submitted',
            default                                                                                    => 'processing',
        };
    }

    public function testConnection(BureauPaymentIntegration $integration): array
    {
        $result = $this->providers->adapter($integration->provider)->testConnection($integration);
        $integration->update([
            'last_sync_status'  => $result['success'] ? 'success' : 'error',
            'last_sync_message' => $result['message'],
            'last_sync_at'      => now(),
        ]);

        return $result;
    }

    /** Submit a finalised run's net salaries as a payout batch to the active provider. */
    public function submitRun(BureauPayRun $run): BureauDisbursement
    {
        $company = $run->company;
        if (! $run->isFinalised()) {
            throw ValidationException::withMessages(['run' => 'Finalise the run before disbursing.']);
        }

        /*
         * Once only.
         *
         * This is the most consequential button in the module and it had no guard at all: pressing
         * it twice, or retrying after a timeout on a request that had actually succeeded, sent
         * every employee's net pay a second time. A disbursement that genuinely failed is excluded
         * so a rejected batch can still be corrected and re-sent.
         *
         * A unique index enforces the same rule, because this check is a read followed by a write
         * and two concurrent requests can both pass it.
         */
        $existing = BureauDisbursement::where('bureau_pay_run_id', $run->id)
            ->where('status', '<>', 'failed')
            ->first();

        if ($existing) {
            throw ValidationException::withMessages(['payout' => __(
                'This run was already sent to :provider on :when (reference :reference). '
                . 'Check its status rather than sending it again.',
                [
                    'provider' => $existing->provider,
                    'when' => $existing->created_at?->format('d M Y H:i') ?? 'an earlier run',
                    'reference' => $existing->reference ?: 'not yet issued',
                ],
            )]);
        }

        $integration = BureauPaymentIntegration::where('bureau_company_id', $company->id)
            ->where('active', true)->first();
        if (! $integration || ! $integration->hasCredentials()) {
            throw ValidationException::withMessages(['payout' => 'Connect and activate a payout provider first.']);
        }

        $instructions = [];
        foreach ($run->payslips()->with('employee')->get() as $slip) {
            $e = $slip->employee;
            if (! $e || blank($e->account_number) || (float) $slip->net_pay <= 0) {
                continue;
            }
            $instructions[] = [
                'name'      => $e->account_holder ?: $e->fullName(),
                'branch'    => preg_replace('/\D/', '', (string) $e->branch_code),
                'account'   => preg_replace('/\D/', '', (string) $e->account_number),
                'amount'    => round((float) $slip->net_pay, 2),
                'reference' => substr($company->name, 0, 20),
            ];
        }
        if (empty($instructions)) {
            throw ValidationException::withMessages(['payout' => 'No payable employees with bank accounts.']);
        }

        /*
         * The record is written *before* the money is asked for, not after.
         *
         * Writing it afterwards looks tidier and loses payroll. If the provider receives the batch
         * and the response never arrives — a timeout, a dropped connection — the old code caught
         * the exception, wrote nothing, and left the operator looking at a run that appears
         * un-sent. They press the button again and everybody is paid twice. The provider already
         * had the first batch; nothing here knew.
         *
         * Claiming the run first means a retry meets the guard above instead. The cost is that a
         * request which genuinely never reached the provider also leaves a row behind — which is
         * why the failure paths below distinguish "the provider said no" from "we do not know".
         */
        $disbursement = BureauDisbursement::create([
            'bureau_pay_run_id'  => $run->id,
            'bureau_company_id'  => $company->id,
            'provider'           => $integration->provider,
            'reference'          => null,
            'status'             => 'submitted',
            'count'              => count($instructions),
            'total_amount'       => round(array_sum(array_column($instructions, 'amount')), 2),
            'created_by'         => Auth::id(),
        ]);

        try {
            $result = $this->providers->adapter($integration->provider)->createBatch($integration, $instructions);
        } catch (\Throwable $e) {
            $integration->update(['last_sync_status' => 'error', 'last_sync_message' => $e->getMessage(), 'last_sync_at' => now()]);

            /*
             * Refused, or merely unanswered?
             *
             * A provider that answered with a refusal moved no money, so the row is marked failed
             * and the run can be corrected and sent again. Anything else — a timeout, a connection
             * that dropped, a response we could not read — means the batch may well be executing.
             * That is recorded as `unknown`, which the guard above treats as live, so nobody can
             * re-send it until a person has checked with the provider and said which it was.
             */
            $refused = $e instanceof \RuntimeException
                && str_contains($e->getMessage(), 'payout failed:');

            $disbursement->update([
                'status'   => $refused ? 'failed' : 'unknown',
                'response' => ['error' => $e->getMessage(), 'refused' => $refused],
            ]);

            throw ValidationException::withMessages(['payout' => $refused
                ? 'The provider refused the batch: '.$e->getMessage().' No money was sent; correct it and try again.'
                : 'The provider did not answer: '.$e->getMessage()
                    .' The batch may already be running — check with them before sending it again.',
            ]);
        }

        $integration->update(['last_sync_status' => 'success', 'last_sync_message' => 'Batch submitted', 'last_sync_at' => now()]);

        $disbursement->update([
            'reference' => $result['reference'] ?? null,
            'status'    => $result['status'] ?? 'submitted',
            'response'  => $result['raw'] ?? null,
        ]);

        // The run itself moves on: money has left the client's account, and a run sitting at
        // "finalised" forever is one the bureau dashboard chases every day for no reason.
        if (PayRunLifecycle::canMove($run->status, PayRunLifecycle::PAID)) {
            app(BureauPayRunService::class)->markPaid($run, [
                'provider'  => $integration->provider,
                'reference' => $disbursement->reference,
            ]);
        }

        // The single most consequential action in the module: money leaving a client's account.
        // Who released it, to how many people, for how much, and what the provider said back.
        app(BureauAuditService::class)->run(
            $run,
            BureauAuditService::DISBURSEMENT_SENT,
            "Payment released for {$company->name} via {$integration->provider}.",
            [
                'provider'  => $integration->provider,
                'reference' => $disbursement->reference,
                'count'     => $disbursement->count,
                'total'     => (float) $disbursement->total_amount,
                'status'    => $disbursement->status,
            ],
        );

        return $disbursement;
    }
}
