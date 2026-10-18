<?php

namespace App\Modules\Finance\Claims\Services;

use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimRiskScore;
use Illuminate\Support\Facades\DB;

class ClaimsAiService
{
    // ── Phase 2: AI Intelligence ──────────────────────────────────────────────

    public function analyseClaimRisk(Claim $claim): ClaimRiskScore
    {
        $claim->load(['items', 'attachments', 'category', 'policyViolations']);

        $flags       = [];
        $scoreTotal  = 0;

        $isDuplicate     = $this->detectDuplicateClaim($claim);
        $isInflated      = $this->detectInflatedClaim($claim);
        $isSplitting     = $this->detectClaimSplitting($claim);
        $missingReceipt  = $this->detectMissingReceipt($claim);
        $outOfPolicy     = $this->detectOutOfPolicyClaim($claim);
        $isWeekend       = $this->detectWeekendOrAfterHoursClaim($claim);

        if ($isDuplicate)    { $flags[] = 'Possible duplicate claim detected'; $scoreTotal += 35; }
        if ($isInflated)     { $flags[] = 'Amount appears inflated vs typical range'; $scoreTotal += 25; }
        if ($isSplitting)    { $flags[] = 'Potential claim splitting to bypass limits'; $scoreTotal += 30; }
        if ($missingReceipt) { $flags[] = 'Receipt(s) missing for line items'; $scoreTotal += 15; }
        if ($outOfPolicy)    { $flags[] = 'Claim falls outside policy rules'; $scoreTotal += 20; }
        if ($isWeekend)      { $flags[] = 'Claimed on weekend or outside business hours'; $scoreTotal += 10; }

        $scoreTotal += $claim->policyViolations->count() * 5;
        $riskScore   = min(100, $scoreTotal);

        $riskLevel = match (true) {
            $riskScore >= 70 => 'critical',
            $riskScore >= 45 => 'high',
            $riskScore >= 20 => 'medium',
            default          => 'low',
        };

        $explanation = $this->generateClaimRiskExplanation($claim, $flags, $riskScore, $riskLevel);

        $score = ClaimRiskScore::updateOrCreate(
            ['claim_id' => $claim->id],
            [
                'risk_score'     => $riskScore,
                'risk_level'     => $riskLevel,
                'flags'          => $flags,
                'explanation'    => $explanation,
                'is_duplicate'   => $isDuplicate,
                'is_inflated'    => $isInflated,
                'is_splitting'   => $isSplitting,
                'missing_receipt'=> $missingReceipt,
                'out_of_policy'  => $outOfPolicy,
            ]
        );

        $claim->update([
            'risk_score'     => $riskScore,
            'risk_level'     => $riskLevel,
            'ai_flags'       => $flags,
            'ai_reviewed_at' => now(),
        ]);

        activity('claims')
            ->causedBy(auth()->user())
            ->performedOn($claim)
            ->withProperties(['risk_score' => $riskScore, 'risk_level' => $riskLevel, 'flags' => $flags])
            ->event('ai_risk_scored')
            ->log("AI risk analysis: {$riskLevel} ({$riskScore}/100).");

        return $score;
    }

    public function detectDuplicateClaim(Claim $claim): bool
    {
        return Claim::where('id', '!=', $claim->id)
            ->where('employee_id', $claim->employee_id)
            ->where('category_id', $claim->category_id)
            ->where('claim_date', $claim->claim_date)
            ->whereRaw('ABS(total_amount - ?) < 1', [$claim->total_amount])
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->exists();
    }

    public function detectInflatedClaim(Claim $claim): bool
    {
        if (! $claim->category_id) return false;

        $avg = Claim::where('id', '!=', $claim->id)
            ->where('category_id', $claim->category_id)
            ->whereIn('status', ['approved', 'paid'])
            ->avg('total_amount');

        if (! $avg || $avg <= 0) return false;

        return (float) $claim->total_amount > ($avg * 2.5);
    }

    public function detectOutOfPolicyClaim(Claim $claim): bool
    {
        return $claim->policyViolations()->where('severity', 'block')->exists();
    }

    public function detectClaimSplitting(Claim $claim): bool
    {
        $windowStart = $claim->claim_date->copy()->subDays(3);
        $windowEnd   = $claim->claim_date->copy()->addDays(3);

        $nearByClaims = Claim::where('id', '!=', $claim->id)
            ->where('employee_id', $claim->employee_id)
            ->where('category_id', $claim->category_id)
            ->whereBetween('claim_date', [$windowStart, $windowEnd])
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->count();

        if ($nearByClaims < 1) return false;

        $combinedTotal = Claim::where('employee_id', $claim->employee_id)
            ->where('category_id', $claim->category_id)
            ->whereBetween('claim_date', [$windowStart, $windowEnd])
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->sum('total_amount');

        $limit = $claim->category?->max_amount ?? 0;
        if ($limit <= 0) return false;

        return (float) $combinedTotal > $limit && (float) $claim->total_amount < $limit;
    }

    public function detectMissingReceipt(Claim $claim): bool
    {
        if ($claim->category?->requires_receipt && $claim->attachments->isEmpty()) {
            return true;
        }
        return $claim->items->where('receipt_required', true)->where('receipt_uploaded', false)->isNotEmpty();
    }

    public function detectWeekendOrAfterHoursClaim(Claim $claim): bool
    {
        $dayOfWeek = $claim->claim_date->dayOfWeek;
        return $dayOfWeek === 0 || $dayOfWeek === 6;
    }

    public function calculateClaimRiskScore(Claim $claim): float
    {
        return $this->analyseClaimRisk($claim)->risk_score;
    }

    public function generateClaimRiskExplanation(Claim $claim, array $flags, float $score, string $level): string
    {
        if (empty($flags)) {
            return "No risk indicators detected. Claim appears within normal parameters.";
        }

        $lines = ["Risk Level: " . strtoupper($level) . " ({$score}/100)."];
        $lines[] = "Flags detected:";
        foreach ($flags as $flag) {
            $lines[] = "  • {$flag}";
        }
        $lines[] = "Recommended: " . match ($level) {
            'critical' => 'Manual review required before processing.',
            'high'     => 'Finance manager review recommended.',
            'medium'   => 'Verify receipts and supporting documents.',
            default    => 'Standard processing applies.',
        };

        return implode("\n", $lines);
    }
}
