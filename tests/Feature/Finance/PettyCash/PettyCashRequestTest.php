<?php

namespace Tests\Feature\Finance\PettyCash;

use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Models\PettyCashRequest;
use Database\Factories\Finance\PettyCashFloatFactory;
use Database\Factories\Finance\PettyCashRequestFactory;
use Tests\Feature\Finance\FinanceTestCase;

class PettyCashRequestTest extends FinanceTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create an active float owned by the financeManager as custodian.
     */
    private function makeActiveFloat(array $overrides = []): PettyCashFloat
    {
        return PettyCashFloatFactory::new()->create(array_merge([
            'custodian_id' => $this->financeManager->id,
            'status'       => 'active',
        ], $overrides));
    }

    /**
     * Build the minimum valid POST body for petty-cash.floats.store.
     */
    private function validFloatPayload(array $overrides = []): array
    {
        return array_merge([
            'name'                    => 'Test Float',
            'custodian_id'            => $this->financeManager->id,
            'float_limit'             => 5000,
            'replenishment_threshold' => 1000,
            'currency'                => 'ZAR',
        ], $overrides);
    }

    /**
     * Build the minimum valid POST body for petty-cash.requests.store.
     */
    private function validRequestPayload(PettyCashFloat $float, array $overrides = []): array
    {
        return array_merge([
            'float_id'         => $float->id,
            'purpose'          => 'Purchase office stationery',
            'category'         => 'office_supplies',
            'amount_requested' => 350.00,
        ], $overrides);
    }

    // ── Authentication ─────────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected(): void
    {
        $response = $this->get(route('petty-cash.requests.index'));

        $response->assertRedirect();
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_employee_can_view_petty_cash_requests_index(): void
    {
        $response = $this->actingAs($this->employee)
            ->get(route('petty-cash.requests.index'));

        $response->assertOk();
    }

    // ── Float store ───────────────────────────────────────────────────────────

    public function test_finance_manager_can_create_a_float(): void
    {
        $response = $this->actingAs($this->financeManager)
            ->post(route('petty-cash.floats.store'), $this->validFloatPayload([
                'name' => 'Finance Department Float',
            ]));

        $response->assertRedirect();

        $this->assertDatabaseHas('petty_cash_floats', [
            'name'         => 'Finance Department Float',
            'custodian_id' => $this->financeManager->id,
            'float_limit'  => 5000,
        ]);
    }

    public function test_float_requires_name_and_limit(): void
    {
        $response = $this->actingAs($this->financeManager)
            ->post(route('petty-cash.floats.store'), [
                // missing name, custodian_id, float_limit
                'currency' => 'ZAR',
            ]);

        $response->assertSessionHasErrors(['name', 'custodian_id', 'float_limit']);
    }

    // ── Request store ─────────────────────────────────────────────────────────

    public function test_employee_can_submit_a_petty_cash_request(): void
    {
        $float = $this->makeActiveFloat();

        $response = $this->actingAs($this->employee)
            ->post(route('petty-cash.requests.store'), $this->validRequestPayload($float, [
                'purpose' => 'Buy printer cartridges',
            ]));

        $response->assertRedirect();

        $this->assertDatabaseHas('petty_cash_requests', [
            'requestor_id'     => $this->employee->id,
            'float_id'         => $float->id,
            'purpose'          => 'Buy printer cartridges',
            'status'           => 'pending',
        ]);
    }

    public function test_request_amount_must_be_positive(): void
    {
        $float = $this->makeActiveFloat();

        $response = $this->actingAs($this->employee)
            ->post(route('petty-cash.requests.store'), $this->validRequestPayload($float, [
                'amount_requested' => 0,
            ]));

        $response->assertSessionHasErrors('amount_requested');
    }

    public function test_request_requires_existing_float(): void
    {
        $response = $this->actingAs($this->employee)
            ->post(route('petty-cash.requests.store'), [
                'float_id'         => 99999,
                'purpose'          => 'Test',
                'amount_requested' => 100,
            ]);

        $response->assertSessionHasErrors('float_id');
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    public function test_finance_manager_can_approve_a_request(): void
    {
        $float   = $this->makeActiveFloat();
        $pcRequest = PettyCashRequestFactory::new()->create([
            'float_id'     => $float->id,
            'requestor_id' => $this->employee->id,
            'status'       => 'pending',
        ]);

        $response = $this->actingAs($this->financeManager)
            ->post(route('petty-cash.requests.approve', $pcRequest->uuid), [
                'comments' => 'Approved for purchase.',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('petty_cash_requests', [
            'id'     => $pcRequest->id,
            'status' => 'approved',
        ]);
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function test_finance_manager_can_reject_a_request_with_reason(): void
    {
        $float   = $this->makeActiveFloat();
        $pcRequest = PettyCashRequestFactory::new()->create([
            'float_id'     => $float->id,
            'requestor_id' => $this->employee->id,
            'status'       => 'pending',
        ]);

        $response = $this->actingAs($this->financeManager)
            ->post(route('petty-cash.requests.reject', $pcRequest->uuid), [
                'reason' => 'Insufficient justification provided.',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('petty_cash_requests', [
            'id'     => $pcRequest->id,
            'status' => 'rejected',
        ]);
    }

    public function test_reject_requires_a_reason(): void
    {
        $float   = $this->makeActiveFloat();
        $pcRequest = PettyCashRequestFactory::new()->create([
            'float_id'     => $float->id,
            'requestor_id' => $this->employee->id,
            'status'       => 'pending',
        ]);

        $response = $this->actingAs($this->financeManager)
            ->post(route('petty-cash.requests.reject', $pcRequest->uuid), [
                'reason' => '',
            ]);

        $response->assertSessionHasErrors('reason');
    }

    // ── Issue ─────────────────────────────────────────────────────────────────

    public function test_finance_manager_can_issue_an_approved_request(): void
    {
        $float   = $this->makeActiveFloat();
        $pcRequest = PettyCashRequestFactory::new()->approved()->create([
            'float_id'     => $float->id,
            'requestor_id' => $this->employee->id,
        ]);

        $response = $this->actingAs($this->financeManager)
            ->post(route('petty-cash.requests.issue', $pcRequest->uuid));

        $response->assertRedirect();

        $this->assertDatabaseHas('petty_cash_requests', [
            'id'     => $pcRequest->id,
            'status' => 'issued',
        ]);
    }

    // ── Settle ────────────────────────────────────────────────────────────────

    public function test_finance_manager_can_settle_an_issued_request(): void
    {
        $float   = $this->makeActiveFloat();
        $pcRequest = PettyCashRequestFactory::new()->issued()->create([
            'float_id'     => $float->id,
            'requestor_id' => $this->employee->id,
        ]);

        $response = $this->actingAs($this->financeManager)
            ->post(route('petty-cash.requests.settle', $pcRequest->uuid), [
                'amount_spent'    => 480.00,
                'amount_returned' => 20.00,
                'notes'           => 'Change returned to custodian.',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('petty_cash_requests', [
            'id'     => $pcRequest->id,
            'status' => 'settled',
        ]);
    }

    public function test_settle_requires_amount_spent(): void
    {
        $float   = $this->makeActiveFloat();
        $pcRequest = PettyCashRequestFactory::new()->issued()->create([
            'float_id'     => $float->id,
            'requestor_id' => $this->employee->id,
        ]);

        $response = $this->actingAs($this->financeManager)
            ->post(route('petty-cash.requests.settle', $pcRequest->uuid), [
                // missing amount_spent
            ]);

        $response->assertSessionHasErrors('amount_spent');
    }
}
