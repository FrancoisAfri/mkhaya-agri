<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Str;

/**
 * The written due-diligence summary.
 *
 * Two things about this are non-negotiable, and both are about not letting a paragraph of fluent
 * prose do work that only evidence should do.
 *
 * The first is that the facts are assembled here, deterministically, from the observations — not
 * recalled by a model. A language model asked to summarise a supplier will produce a confident
 * account of checks that were never run, because a fluent summary is what it was asked for. So the
 * findings, the gaps and the numbers are built in PHP; the model is given them and asked only to
 * write them up.
 *
 * The second is that the result is labelled as AI-written wherever it appears, and the gaps travel
 * with it. A summary that reads well and omits "we could not verify the bank account" is worse than
 * no summary, because the reader now believes they have read the file.
 *
 * Without a model configured it still produces a summary — a plainer one, assembled from the same
 * facts. The narrative is the convenience; the facts are the product.
 */
class DueDiligenceSummary
{
    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly PersonalDataGuard $guard,
        private readonly OwnershipGraph $ownership,
        private readonly ReputationScore $reputation,
        private readonly HealthScore $health,
        private readonly BankingGate $banking,
    ) {}

    private const SYSTEM_PROMPT = <<<'TXT'
You write the executive summary of a supplier due-diligence file for a procurement committee.

You are given ONLY verified facts assembled by the system. Write from them and nothing else:
never introduce a fact, a number, a date or a conclusion that is not in the input, and never
describe a check as done when the input says it was not.

Return ONLY valid JSON:
{"summary": string, "strengths": [string], "concerns": [string], "recommendation": string}

- summary: 3-5 sentences, plain professional English, no bullet points.
- concerns MUST include every gap listed in the input. A gap is a concern.
- recommendation: one sentence, and it must be conditional on the outstanding items where any exist.
- Never recommend approval where the input says an approval gate is failing.
TXT;

    /**
     * Build the summary.
     *
     * @return array{summary:string, strengths:array, concerns:array, recommendation:string,
     *               ai_generated:bool, facts:array, generated_at:string}
     */
    public function for(Supplier $supplier): array
    {
        $facts = $this->facts($supplier);
        $written = $this->write($facts);

        return $written + [
            // The assembled facts travel with the prose, so a reader can check the summary against
            // them without opening five screens — and so the gaps cannot be edited out by fluency.
            'facts' => $facts,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Everything the summary is allowed to be about, assembled deterministically.
     *
     * @return array<string, mixed>
     */
    public function facts(Supplier $supplier): array
    {
        $checks = SupplierVerification::currentFor($supplier->id);
        $risk = SupplierRiskScore::latestFor($supplier->id);
        $graph = $this->ownership->forSupplier($supplier);
        $reputation = $this->reputation->for($supplier);
        $health = $this->health->for($supplier);
        $banking = $this->banking->verdict($supplier);

        $verified = [];
        $gaps = [];
        $problems = [];

        foreach (CheckType::verificationChecks() as $check) {
            $observation = $checks->get($check);
            $label = CheckType::label($check);

            if (! $observation || $observation->status === 'unavailable') {
                $gaps[] = "{$label}: never verified.";

                continue;
            }

            if ($observation->passed() && ! $observation->isStale()) {
                $verified[] = "{$label}: verified ".$observation->checked_at->diffForHumans().'.';
            } elseif ($observation->passed()) {
                $gaps[] = "{$label}: verified but the answer is out of date.";
            } else {
                $problems[] = "{$label}: {$observation->status} — ".Str::limit((string) $observation->summary, 160);
            }
        }

        $sanctions = ScreeningHit::query()->where('supplier_id', $supplier->id)->live()
            ->type(ScreeningHit::TYPE_SANCTIONS)->count();
        $unadjudicated = ScreeningHit::query()->where('supplier_id', $supplier->id)->unadjudicated()->count();

        if ($sanctions > 0) {
            $problems[] = "{$sanctions} live sanctions match(es) against this supplier or its people.";
        }
        if ($unadjudicated > 0) {
            $gaps[] = "{$unadjudicated} screening match(es) have not been adjudicated.";
        }

        if ($banking['blocks']) {
            $problems[] = 'Payment is blocked: '.$banking['reason'];
        }

        if ($graph['ubos'] === []) {
            $gaps[] = 'Ownership does not resolve to any natural person.';
        }
        foreach ($graph['unallocated'] as $gap) {
            $problems[] = "{$gap['entity']}: {$gap['missing']}% of the shareholding is unaccounted for.";
        }

        if (! $reputation['coverage']) {
            $gaps[] = 'No reputation or adverse-media monitoring has been run.';
        }

        return [
            'supplier' => $supplier->company_name,
            'registration_number' => $supplier->company_registration_number,
            'tier' => $supplier->risk_tier,
            'risk_score' => $risk ? (float) $risk->total : null,
            'risk_band' => $risk?->band,
            'health_score' => $health['covered'] ? $health['score'] : null,
            'auto_approvable' => (bool) ($risk->auto_approvable ?? false),
            'approval_gates_failing' => $risk?->blockers ?? ['Never assessed.'],
            'edd_triggers' => $risk?->edd_triggers ?? [],
            'beneficial_owners' => collect($graph['ubos'])->map(fn ($u) => $u['name'].' ('.$u['effective_percentage'].'%)')->all(),
            'verified' => $verified,
            'problems' => $problems,
            // Named `gaps` rather than folded into concerns, so the prompt can be told plainly that
            // every one of them has to survive into the output.
            'gaps' => $gaps,
            'reputation' => $reputation['coverage']
                ? sprintf('%d finding(s), reputation score %.0f.', $reputation['findings'], $reputation['score'])
                : 'Not searched.',
        ];
    }

    /** @return array{summary:string, strengths:array, concerns:array, recommendation:string, ai_generated:bool} */
    private function write(array $facts): array
    {
        try {
            $response = $this->agents->forTask('suppliers', 'due-diligence-summary')->ask(new AgentRequest(
                // Redacted like every other prompt in this module — a summary is not a reason to send
                // identity numbers to a third party.
                prompt: "Write the summary from these facts:\n\n".json_encode($this->guard->redact($facts), JSON_PRETTY_PRINT),
                module: 'supplier-management.dd-summary',
                context: ['supplier' => $facts['supplier']],
                systemPrompt: self::SYSTEM_PROMPT,
                temperature: 0.1,
                maxTokens: 900,
                responseFormat: 'json',
            ));

            if ($response->failed()) {
                return $this->plain($facts);
            }

            $data = (array) $response->toArray();

            if (empty($data['summary'])) {
                return $this->plain($facts);
            }

            return [
                'summary' => Str::limit((string) $data['summary'], 2000, ''),
                'strengths' => array_slice(array_map('strval', (array) ($data['strengths'] ?? [])), 0, 8),
                // Every gap survives into the output whatever the model returned. A summary that
                // reads well and omits "we could not verify the bank account" is worse than none.
                'concerns' => array_values(array_unique(array_merge(
                    array_slice(array_map('strval', (array) ($data['concerns'] ?? [])), 0, 8),
                    $facts['problems'],
                    $facts['gaps'],
                ))),
                'recommendation' => Str::limit((string) ($data['recommendation'] ?? ''), 500, ''),
                'ai_generated' => true,
            ];
        } catch (\Throwable $e) {
            report($e);

            return $this->plain($facts);
        }
    }

    /**
     * The summary without a model: the same facts, assembled into sentences.
     *
     * Plainer, and no less true. The narrative was the convenience.
     */
    private function plain(array $facts): array
    {
        $sentences = [];

        $sentences[] = sprintf(
            '%s%s has been assessed at %s.',
            $facts['supplier'],
            $facts['registration_number'] ? " ({$facts['registration_number']})" : '',
            $facts['risk_score'] !== null
                ? sprintf('%.0f out of 100 — %s risk', $facts['risk_score'], $facts['risk_band'])
                : 'no risk score, because no assessment has been run'
        );

        if ($facts['verified'] !== []) {
            $sentences[] = count($facts['verified']).' check(s) are verified and current.';
        }

        if ($facts['problems'] !== []) {
            $sentences[] = count($facts['problems']).' issue(s) require attention.';
        }

        if ($facts['gaps'] !== []) {
            $sentences[] = count($facts['gaps']).' check(s) have not been completed.';
        }

        return [
            'summary' => implode(' ', $sentences),
            'strengths' => $facts['verified'],
            'concerns' => array_merge($facts['problems'], $facts['gaps']),
            'recommendation' => $facts['auto_approvable']
                ? 'Every approval gate passes; this supplier may be approved.'
                : 'Do not approve until the outstanding items above are resolved or explicitly overridden.',
            'ai_generated' => false,
        ];
    }
}
