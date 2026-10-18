<?php

namespace Tests\Unit\Assets;

use App\Modules\Assets\Services\AssetDepreciationService;
use PHPUnit\Framework\TestCase;

/**
 * Approval level resolution: given an amount and configured thresholds,
 * resolve the number of approval levels required.
 *
 * Rules:
 *  amount <  l1 → 1 approver
 *  amount <  l2 → 2 approvers
 *  amount >= l2 → 3 approvers
 */
class ApprovalLevelResolutionTest extends TestCase
{
    private AssetDepreciationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AssetDepreciationService();
    }

    public function test_amount_below_l1_returns_one_level(): void
    {
        $this->assertSame(1, $this->service->resolveApprovalLevel(5000, 10000, 50000));
    }

    public function test_amount_between_l1_and_l2_returns_two_levels(): void
    {
        $this->assertSame(2, $this->service->resolveApprovalLevel(25000, 10000, 50000));
    }

    public function test_amount_at_l1_threshold_returns_two_levels(): void
    {
        // At l1 boundary, amount is not strictly less than l1 → second tier
        $this->assertSame(2, $this->service->resolveApprovalLevel(10000, 10000, 50000));
    }

    public function test_amount_at_l2_threshold_returns_three_levels(): void
    {
        $this->assertSame(3, $this->service->resolveApprovalLevel(50000, 10000, 50000));
    }

    public function test_amount_above_l2_returns_three_levels(): void
    {
        $this->assertSame(3, $this->service->resolveApprovalLevel(250000, 10000, 50000));
    }

    public function test_zero_amount_returns_one_level(): void
    {
        $this->assertSame(1, $this->service->resolveApprovalLevel(0, 10000, 50000));
    }
}
