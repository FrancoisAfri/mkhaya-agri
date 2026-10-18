<?php

namespace App\Modules\Operations\Sheq\Services;

use InvalidArgumentException;

/**
 * A tenant's risk matrix, in memory (SHEQ-RA-1).
 *
 * The specification says plainly: "No hard-coded 5×5." So there is no 5×5 anywhere in this class —
 * dimensions, labels, bands, colours and the approval role per band all come from the row. A mine
 * running 6×6 with its own consequence descriptors and a factory running 3×3 are the same code path.
 *
 * Bands are ordered by threshold and matched by score range. The approval role attached to a band
 * is what makes SHEQ-RA-3 enforceable: a residual "Critical" line cannot be signed off by a site
 * officer, because the band itself says who is allowed to.
 */
final class RiskMatrix
{
    /**
     * @param  list<array{min:int,max:int,label:string,colour?:string,action?:string,max_approval_role?:string}>  $bands
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $likelihoodLevels,
        public readonly int $severityLevels,
        public readonly array $bands,
        public readonly array $likelihoodLabels = [],
        public readonly array $severityLabels = [],
    ) {
        if ($likelihoodLevels < 3 || $likelihoodLevels > 6 || $severityLevels < 3 || $severityLevels > 6) {
            throw new InvalidArgumentException('A risk matrix must be between 3×3 and 6×6 (SHEQ-RA-1).');
        }

        if ($bands === []) {
            throw new InvalidArgumentException('A risk matrix needs at least one band.');
        }
    }

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            name: (string) $row->name,
            likelihoodLevels: (int) $row->likelihood_levels,
            severityLevels: (int) $row->severity_levels,
            bands: json_decode($row->bands, true) ?: [],
            likelihoodLabels: json_decode($row->likelihood_labels ?? '[]', true) ?: [],
            severityLabels: json_decode($row->severity_labels ?? '[]', true) ?: [],
        );
    }

    public function maxScore(): int
    {
        return $this->likelihoodLevels * $this->severityLevels;
    }

    /** @throws InvalidArgumentException when a rating falls outside the matrix's own dimensions */
    public function score(int $likelihood, int $severity): int
    {
        if ($likelihood < 1 || $likelihood > $this->likelihoodLevels) {
            throw new InvalidArgumentException(
                "Likelihood {$likelihood} is outside this matrix (1–{$this->likelihoodLevels})."
            );
        }

        if ($severity < 1 || $severity > $this->severityLevels) {
            throw new InvalidArgumentException(
                "Severity {$severity} is outside this matrix (1–{$this->severityLevels})."
            );
        }

        return $likelihood * $severity;
    }

    /** @return array{label:string,colour:?string,action:?string,max_approval_role:?string}|null */
    public function bandFor(int $score): ?array
    {
        foreach ($this->bands as $band) {
            if ($score >= (int) $band['min'] && $score <= (int) $band['max']) {
                return [
                    'label' => (string) $band['label'],
                    'colour' => $band['colour'] ?? null,
                    'action' => $band['action'] ?? null,
                    'max_approval_role' => $band['max_approval_role'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Deliberately not called, and recorded here so the next audit does not report it as an
     * oversight.
     *
     * It composes `score()` and `bandFor()`, and both halves are already reached by other routes:
     * a risk line stores its own `inherent_band` and `residual_band` when it is added, and the
     * screen receives the whole `bands` array so it can label any score itself. Wiring this as
     * well would be a third way to arrive at the same label.
     */
    public function bandLabel(int $likelihood, int $severity): ?string
    {
        return $this->bandFor($this->score($likelihood, $severity))['label'] ?? null;
    }

    /**
     * The role required to approve a given score. Null means the matrix does not restrict it.
     * SHEQ-RA-3 hangs off this: approval authority is derived, never chosen by the approver.
     */
    public function approvalRoleFor(int $score): ?string
    {
        return $this->bandFor($score)['max_approval_role'] ?? null;
    }

    /**
     * The seniority ladder, derived from the matrix rather than hard-coded.
     *
     * Bands run from least to most severe, and each names the role that may sign it, so the band
     * order *is* the authority order: whoever can approve "Critical" outranks whoever can approve
     * "Low". Reading it from the data means a tenant whose ladder is Supervisor → Manager → Director
     * gets correct behaviour without anyone editing PHP — the same principle as SHEQ-RA-1.
     *
     * @return array<string,int> role => rank, higher is more senior
     */
    public function authorityRanks(): array
    {
        $bands = $this->bands;
        usort($bands, fn (array $a, array $b) => (int) $a['min'] <=> (int) $b['min']);

        $ranks = [];
        $rank = 0;

        foreach ($bands as $band) {
            $role = $band['max_approval_role'] ?? null;

            // A role reappearing on a higher band keeps its first (lower) rank: several bands
            // sharing an approver simply means that approver covers all of them.
            if ($role !== null && ! array_key_exists($role, $ranks)) {
                $ranks[$role] = $rank++;
            }
        }

        return $ranks;
    }

    /**
     * Whether any of the roles held clears the bar for this score.
     *
     * Seniority satisfies a lower requirement — an executive may approve a medium risk — but never
     * the reverse, which is the whole point of SHEQ-RA-3. A role the matrix has never heard of
     * carries no authority at all rather than being assumed senior.
     *
     * @param  list<string>  $rolesHeld
     */
    public function satisfiesApproval(int $score, array $rolesHeld): bool
    {
        $required = $this->approvalRoleFor($score);

        if ($required === null) {
            return true;
        }

        $ranks = $this->authorityRanks();
        $requiredRank = $ranks[$required] ?? PHP_INT_MAX;

        foreach ($rolesHeld as $role) {
            if (($ranks[$role] ?? -1) >= $requiredRank) {
                return true;
            }
        }

        return false;
    }

    /**
     * A sensible starting matrix for a new tenant — 5×5 because it is the most familiar, *not*
     * because anything depends on it. It is a seed value in a row the tenant may replace entirely.
     *
     * @return array{likelihood_levels:int,severity_levels:int,likelihood_labels:list<array<string,mixed>>,severity_labels:list<array<string,mixed>>,bands:list<array<string,mixed>>}
     */
    public static function defaultDefinition(): array
    {
        return [
            'likelihood_levels' => 5,
            'severity_levels' => 5,
            'likelihood_labels' => [
                ['value' => 1, 'label' => 'Rare', 'descriptor' => 'May occur only in exceptional circumstances'],
                ['value' => 2, 'label' => 'Unlikely', 'descriptor' => 'Could occur at some time'],
                ['value' => 3, 'label' => 'Possible', 'descriptor' => 'Might occur at some time'],
                ['value' => 4, 'label' => 'Likely', 'descriptor' => 'Will probably occur in most circumstances'],
                ['value' => 5, 'label' => 'Almost certain', 'descriptor' => 'Expected to occur in most circumstances'],
            ],
            'severity_labels' => [
                ['value' => 1, 'label' => 'Insignificant', 'descriptor' => 'No injury, negligible loss'],
                ['value' => 2, 'label' => 'Minor', 'descriptor' => 'First aid treatment'],
                ['value' => 3, 'label' => 'Moderate', 'descriptor' => 'Medical treatment, lost time'],
                ['value' => 4, 'label' => 'Major', 'descriptor' => 'Serious injury or permanent disability'],
                ['value' => 5, 'label' => 'Catastrophic', 'descriptor' => 'Fatality or multiple serious injuries'],
            ],
            'bands' => [
                ['min' => 1,  'max' => 4,  'label' => 'Low',      'colour' => 'success',
                    'action' => 'Manage by routine procedures', 'max_approval_role' => 'SHEQ_PRACTITIONER'],
                ['min' => 5,  'max' => 9,  'label' => 'Medium',   'colour' => 'warning',
                    'action' => 'Management responsibility must be specified', 'max_approval_role' => 'SHEQ_PRACTITIONER'],
                ['min' => 10, 'max' => 15, 'label' => 'High',     'colour' => 'deep-orange',
                    'action' => 'Senior management attention needed', 'max_approval_role' => 'SITE_MANAGER'],
                ['min' => 16, 'max' => 25, 'label' => 'Critical', 'colour' => 'error',
                    'action' => 'Immediate action required; work must not proceed', 'max_approval_role' => 'EXECUTIVE'],
            ],
        ];
    }
}
