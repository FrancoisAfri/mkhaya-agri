<?php

namespace Tests\Feature\Finance\Claims;

use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimCategory;
use Database\Factories\Finance\ClaimCategoryFactory;
use Database\Factories\Finance\ClaimFactory;
use Tests\Feature\Finance\FinanceTestCase;

class ClaimTest extends FinanceTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a ClaimCategory directly, bypassing the factory's is_active/active
     * column mismatch. The migration uses `active`; the controller filters by
     * `active = true` in categoryOptions().
     */
    private function makeCategory(array $overrides = []): ClaimCategory
    {
        return ClaimCategory::create(array_merge([
            'name'             => 'General Expenses',
            'code'             => 'GEN-' . uniqid(),
            'description'      => 'General expense claims',
            'requires_receipt' => false,
            'max_age_days'     => 30,
            'active'           => true,
            'is_travel'        => false,
        ], $overrides));
    }

    /**
     * Build the minimum valid non-travel POST body for claims.my.store.
     * The store action validates: title, category_id (nullable), claim_date,
     * items (required array min:1), items.*.description, items.*.unit_price.
     */
    private function validClaimPayload(array $overrides = []): array
    {
        return array_merge([
            'title'      => 'Test Expense Claim',
            'claim_date' => now()->toDateString(),
            'is_travel'  => false,
            'items'      => [
                [
                    'description' => 'Office supplies',
                    'unit_price'  => 250.00,
                    'quantity'    => 1,
                ],
            ],
        ], $overrides);
    }

    // ── Authentication ─────────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected_from_claims_index(): void
    {
        $response = $this->get(route('claims.my.index'));

        $response->assertRedirect();
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_employee_can_view_claims_index(): void
    {
        $response = $this->actingAs($this->employee)
            ->get(route('claims.my.index'));

        $response->assertOk();
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_employee_can_create_a_draft_claim(): void
    {
        $category = $this->makeCategory();

        $response = $this->actingAs($this->employee)
            ->post(route('claims.my.store'), $this->validClaimPayload([
                'category_id' => $category->id,
                'title'       => 'My Draft Claim',
            ]));

        $response->assertRedirect();

        $this->assertDatabaseHas('claims', [
            'employee_id' => $this->employee->id,
            'title'       => 'My Draft Claim',
            'status'      => 'draft',
        ]);
    }

    public function test_claim_store_requires_title(): void
    {
        $response = $this->actingAs($this->employee)
            ->post(route('claims.my.store'), $this->validClaimPayload([
                'title' => '',
            ]));

        $response->assertSessionHasErrors('title');
    }

    public function test_claim_store_requires_valid_category_when_provided(): void
    {
        // category_id is nullable but must exist when provided
        $response = $this->actingAs($this->employee)
            ->post(route('claims.my.store'), $this->validClaimPayload([
                'category_id' => 99999,
            ]));

        $response->assertSessionHasErrors('category_id');
    }

    public function test_claim_store_requires_at_least_one_item(): void
    {
        $response = $this->actingAs($this->employee)
            ->post(route('claims.my.store'), $this->validClaimPayload([
                'items' => [],
            ]));

        $response->assertSessionHasErrors('items');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_employee_can_view_own_claim(): void
    {
        $claim = ClaimFactory::new()->create([
            'employee_id' => $this->employee->id,
            'status'      => 'draft',
        ]);

        $response = $this->actingAs($this->employee)
            ->get(route('claims.my.show', $claim->uuid));

        $response->assertOk();
    }

    // ── Submit ────────────────────────────────────────────────────────────────

    public function test_employee_can_submit_a_draft_claim(): void
    {
        $claim = ClaimFactory::new()->create([
            'employee_id' => $this->employee->id,
            'status'      => 'draft',
        ]);

        // A claim with nothing on it cannot be submitted, and rightly so — ClaimsService refuses
        // it with "A claim must have at least one item or trip." The line is what makes this a
        // claim rather than an empty form.
        $claim->items()->create([
            'item_date'   => now()->toDateString(),
            'description' => 'Client lunch',
            'unit_price'  => 240.00,
            'quantity'    => 1,
            'amount'      => 240.00,
            'item_type'   => 'expense',
            // Receipted, because submission also enforces supporting documents and this test is
            // about the submission itself, not the receipt rule.
            'receipt_required' => false,
            'receipt_uploaded' => true,
        ]);

        $response = $this->actingAs($this->employee)
            ->post(route('claims.my.submit', $claim->uuid));

        $response->assertRedirect();

        $this->assertDatabaseHas('claims', [
            'id'     => $claim->id,
            'status' => 'submitted',
        ]);
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    public function test_finance_manager_can_approve_a_submitted_claim(): void
    {
        // Create a claim belonging to employee (not financeManager) so the
        // "no self-approval" guard passes. finance manager is in CLAIMS_ADMIN_ROLES
        // so they can approve any claim regardless of current_approver_id.
        $claim = ClaimFactory::new()->submitted()->create([
            'employee_id' => $this->employee->id,
        ]);

        $response = $this->actingAs($this->financeManager)
            ->post(route('claims.approvals.approve', $claim->uuid), [
                'notes' => 'Looks good.',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('claims', [
            'id'     => $claim->id,
            'status' => 'approved',
        ]);
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function test_finance_manager_can_reject_a_submitted_claim(): void
    {
        $claim = ClaimFactory::new()->submitted()->create([
            'employee_id' => $this->employee->id,
        ]);

        $response = $this->actingAs($this->financeManager)
            ->post(route('claims.approvals.reject', $claim->uuid), [
                'reason' => 'Missing receipts.',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('claims', [
            'id'     => $claim->id,
            'status' => 'rejected',
        ]);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $claim = ClaimFactory::new()->submitted()->create([
            'employee_id' => $this->employee->id,
        ]);

        $response = $this->actingAs($this->financeManager)
            ->post(route('claims.approvals.reject', $claim->uuid), [
                'reason' => '',
            ]);

        $response->assertSessionHasErrors('reason');
    }

    // ── Employee cannot approve own claim ──────────────────────────────────────

    public function test_employee_cannot_approve_their_own_claim(): void
    {
        $claim = ClaimFactory::new()->submitted()->create([
            'employee_id' => $this->employee->id,
        ]);

        $response = $this->actingAs($this->employee)
            ->post(route('claims.approvals.approve', $claim->uuid), [
                'notes' => 'Self-approval attempt.',
            ]);

        // canApproveClaim returns false if employee_id === auth()->id()
        $response->assertForbidden();
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function test_employee_can_cancel_a_draft_claim(): void
    {
        $claim = ClaimFactory::new()->create([
            'employee_id' => $this->employee->id,
            'status'      => 'draft',
        ]);

        $response = $this->actingAs($this->employee)
            ->post(route('claims.my.cancel', $claim->uuid));

        $response->assertRedirect();

        $this->assertDatabaseHas('claims', [
            'id'     => $claim->id,
            'status' => 'cancelled',
        ]);
    }
}
