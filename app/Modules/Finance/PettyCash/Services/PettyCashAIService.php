<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Models\PettyCashReconciliation;
use App\Modules\Finance\PettyCash\Models\PettyCashRequest;
use Illuminate\Support\Collection;

class PettyCashAIService
{
    public function extractPettyCashReceiptData(string $filePath): array
    {
        // Placeholder for OCR/AI receipt extraction (e.g., Claude Vision, Google Vision)
        return [
            'vendor'        => null,
            'amount'        => null,
            'date'          => null,
            'description'   => null,
            'extracted_at'  => now()->toISOString(),
            'confidence'    => 0,
            'raw_text'      => null,
        ];
    }

    public function detectPettyCashDuplicateReceipt(PettyCashRequest $request): array
    {
        $flags = [];

        $attachments = $request->attachments;

        // Look for other requests within the same float in the last 30 days with similar amounts
        $similar = PettyCashRequest::where('float_id', $request->float_id)
            ->where('id', '!=', $request->id)
            ->where('amount_requested', $request->amount_requested)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('status', ['approved', 'issued', 'settled'])
            ->get();

        if ($similar->count() > 0) {
            $flags[] = [
                'type'        => 'duplicate_amount',
                'description' => "Duplicate amount detected: R{$request->amount_requested} matches {$similar->count()} recent request(s).",
                'severity'    => 'medium',
                'details'     => $similar->pluck('reference_number')->toArray(),
            ];
        }

        return $flags;
    }

    public function detectFloatShortage(PettyCashFloat $float): array
    {
        $flags = [];

        $utilizationPct = $float->utilizationPercentage();

        if ($utilizationPct >= 90) {
            $flags[] = [
                'type'        => 'critical_shortage',
                'description' => "Float '{$float->name}' is {$utilizationPct}% depleted. Immediate replenishment required.",
                'severity'    => 'critical',
            ];
        } elseif ($utilizationPct >= 70) {
            $flags[] = [
                'type'        => 'low_balance',
                'description' => "Float '{$float->name}' is {$utilizationPct}% depleted. Consider replenishment soon.",
                'severity'    => 'medium',
            ];
        }

        // Check for rapid depletion (>50% in last 7 days)
        $recentIssuances = PettyCashRequest::where('float_id', $float->id)
            ->where('status', 'issued')
            ->where('issued_at', '>=', now()->subDays(7))
            ->sum('amount_issued');

        if ($recentIssuances > ($float->float_limit * 0.5)) {
            $flags[] = [
                'type'        => 'rapid_depletion',
                'description' => "Float depleted by R{$recentIssuances} in the last 7 days — unusually rapid.",
                'severity'    => 'high',
            ];
        }

        return $flags;
    }

    public function detectSuspiciousCashRequest(PettyCashRequest $request): array
    {
        $flags = [];

        // Round-number amounts are often suspicious
        if (fmod($request->amount_requested, 100) === 0.0 && $request->amount_requested > 200) {
            $flags[] = [
                'type'        => 'round_amount',
                'description' => "Request amount R{$request->amount_requested} is a round number — common in fraudulent cash requests.",
                'severity'    => 'low',
            ];
        }

        // Requests just below the approval limit
        $limit = 1000.00;
        if ($request->amount_requested >= ($limit * 0.9) && $request->amount_requested < $limit) {
            $flags[] = [
                'type'        => 'limit_avoidance',
                'description' => "Request amount R{$request->amount_requested} is just below the R{$limit} approval limit — possible limit avoidance.",
                'severity'    => 'medium',
            ];
        }

        // Vague purpose description
        $purposeWords = str_word_count($request->purpose ?? '');
        if ($purposeWords < 3) {
            $flags[] = [
                'type'        => 'vague_purpose',
                'description' => "Purpose description is too vague: '{$request->purpose}'. Insufficient detail for audit.",
                'severity'    => 'low',
            ];
        }

        return $flags;
    }

    public function detectRepeatedSmallRequests(PettyCashFloat $float): array
    {
        $flags = [];

        $requestors = PettyCashRequest::where('float_id', $float->id)
            ->where('created_at', '>=', now()->subDays(14))
            ->whereIn('status', ['pending', 'approved', 'issued', 'settled'])
            ->selectRaw('requestor_id, COUNT(*) as count, SUM(amount_requested) as total')
            ->groupBy('requestor_id')
            ->having('count', '>=', 3)
            ->get();

        foreach ($requestors as $row) {
            $flags[] = [
                'type'        => 'repeated_small_requests',
                'description' => "Requestor ID {$row->requestor_id} has submitted {$row->count} requests totalling R{$row->total} in the last 14 days.",
                'severity'    => $row->count >= 5 ? 'high' : 'medium',
                'details'     => ['requestor_id' => $row->requestor_id, 'count' => $row->count, 'total' => $row->total],
            ];
        }

        return $flags;
    }

    public function detectReconciliationMismatch(PettyCashReconciliation $reconciliation): array
    {
        $flags = [];

        if (abs($reconciliation->variance) > 0.01) {
            $direction = $reconciliation->variance < 0 ? 'shortage' : 'surplus';
            $absVariance = abs($reconciliation->variance);
            $severity = $absVariance > 500 ? 'critical' : ($absVariance > 100 ? 'high' : 'medium');

            $floatName = $reconciliation->float->name ?? 'unknown';
            $flags[] = [
                'type'        => 'reconciliation_mismatch',
                'description' => "Reconciliation {$direction} of R{$absVariance} detected for float '{$floatName}'.",
                'severity'    => $severity,
                'details'     => ['variance' => $reconciliation->variance, 'direction' => $direction],
            ];
        }

        // Detect if total_receipts doesn't match total_issued
        $receiptGap = $reconciliation->total_issued - $reconciliation->total_receipts;
        if ($receiptGap > 0) {
            $flags[] = [
                'type'        => 'missing_receipts',
                'description' => "R{$receiptGap} in issued cash has no supporting receipts.",
                'severity'    => $receiptGap > 200 ? 'high' : 'medium',
                'details'     => ['gap' => $receiptGap],
            ];
        }

        return $flags;
    }

    public function calculatePettyCashRiskScore(PettyCashRequest $request): array
    {
        $score  = 0;
        $flags  = [];
        $weight = [
            'duplicate_amount'     => 25,
            'round_amount'         => 10,
            'limit_avoidance'      => 30,
            'vague_purpose'        => 15,
            'repeated_requests'    => 20,
        ];

        $allFlags = array_merge(
            $this->detectPettyCashDuplicateReceipt($request),
            $this->detectSuspiciousCashRequest($request)
        );

        foreach ($allFlags as $flag) {
            $score += $weight[$flag['type']] ?? 10;
            $flags[] = $flag;
        }

        $score  = min(100, $score);
        $level  = match (true) {
            $score >= 75 => 'critical',
            $score >= 50 => 'high',
            $score >= 25 => 'medium',
            default      => 'low',
        };

        return [
            'score' => $score,
            'level' => $level,
            'flags' => $flags,
        ];
    }

    public function generatePettyCashNarrativeReport(PettyCashFloat $float, array $options = []): array
    {
        $period  = $options['period'] ?? 'last_30_days';
        $from    = now()->subDays(30);

        $requests = PettyCashRequest::where('float_id', $float->id)
            ->where('created_at', '>=', $from)
            ->get();

        $totalRequested = $requests->sum('amount_requested');
        $totalIssued    = $requests->where('status', 'issued')->sum('amount_issued')
                        + $requests->where('status', 'settled')->sum('amount_issued');
        $totalSettled   = $requests->where('status', 'settled')->sum('amount_spent');
        $pendingCount   = $requests->whereIn('status', ['pending', 'approved'])->count();
        $overdueFraction= $requests->filter(fn($r) => $r->isReceiptOverdue())->count();

        $categoryBreakdown = $requests->groupBy('category')->map(fn($g) => [
            'count'  => $g->count(),
            'total'  => $g->sum('amount_requested'),
        ]);

        $floatFlags = array_merge(
            $this->detectFloatShortage($float),
            $this->detectRepeatedSmallRequests($float)
        );

        return [
            'period'             => $period,
            'float_name'         => $float->name,
            'current_balance'    => $float->current_balance,
            'float_limit'        => $float->float_limit,
            'utilization_pct'    => $float->utilizationPercentage(),
            'total_requested'    => $totalRequested,
            'total_issued'       => $totalIssued,
            'total_settled'      => $totalSettled,
            'pending_requests'   => $pendingCount,
            'overdue_receipts'   => $overdueFraction,
            'category_breakdown' => $categoryBreakdown,
            'ai_flags'           => $floatFlags,
            'risk_summary'       => count($floatFlags) === 0
                ? 'No significant risks detected in this period.'
                : count($floatFlags) . ' risk indicator(s) detected requiring attention.',
            'generated_at'       => now()->toISOString(),
        ];
    }
}
