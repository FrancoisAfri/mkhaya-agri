<?php

namespace Tests\Unit\Policies;

use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Models\RuleCondition;
use App\Modules\Policies\Services\RuleEngine\ConditionEvaluator;
use Tests\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ConditionEvaluator();
    }

    private function makeCondition(array $attrs): RuleCondition
    {
        return new RuleCondition(array_merge([
            'type'             => 'numeric',
            'field'            => 'amount',
            'operator'         => 'eq',
            'value'            => 100,
            'value_unit'       => null,
            'logical_operator' => 'AND',
            'sort_order'       => 0,
        ], $attrs));
    }

    private function makeEvent(array $payload): PolicyEventDTO
    {
        return new PolicyEventDTO(module: 'test', action: 'test.action', payload: $payload);
    }

    // ── Operator tests ──────────────────────────────────────────────────────

    public function test_eq_operator_matches_equal_values(): void
    {
        $condition = $this->makeCondition(['operator' => 'eq', 'value' => 500]);
        $event     = $this->makeEvent(['amount' => 500]);

        $this->assertTrue($this->evaluator->evaluate([$condition], $event));
    }

    public function test_eq_operator_fails_unequal_values(): void
    {
        $condition = $this->makeCondition(['operator' => 'eq', 'value' => 500]);
        $event     = $this->makeEvent(['amount' => 100]);

        $this->assertFalse($this->evaluator->evaluate([$condition], $event));
    }

    public function test_neq_operator(): void
    {
        $condition = $this->makeCondition(['operator' => 'neq', 'value' => 500]);
        $event     = $this->makeEvent(['amount' => 100]);

        $this->assertTrue($this->evaluator->evaluate([$condition], $event));
    }

    public function test_gt_operator(): void
    {
        $condition = $this->makeCondition(['operator' => 'gt', 'value' => 100]);
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 200])));
        $this->assertFalse($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 100])));
        $this->assertFalse($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 50])));
    }

    public function test_gte_operator(): void
    {
        $condition = $this->makeCondition(['operator' => 'gte', 'value' => 100]);
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 100])));
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 200])));
        $this->assertFalse($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 99])));
    }

    public function test_lt_operator(): void
    {
        $condition = $this->makeCondition(['operator' => 'lt', 'value' => 100]);
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 50])));
        $this->assertFalse($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 100])));
    }

    public function test_lte_operator(): void
    {
        $condition = $this->makeCondition(['operator' => 'lte', 'value' => 100]);
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 100])));
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 0])));
        $this->assertFalse($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 101])));
    }

    public function test_in_operator(): void
    {
        $condition = $this->makeCondition(['operator' => 'in', 'value' => ['HR', 'Finance', 'IT']]);
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 'HR'])));
        $this->assertFalse($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 'Legal'])));
    }

    public function test_not_in_operator(): void
    {
        $condition = $this->makeCondition(['operator' => 'not_in', 'value' => ['HR', 'Finance']]);
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 'IT'])));
        $this->assertFalse($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 'HR'])));
    }

    public function test_between_operator(): void
    {
        $condition = $this->makeCondition(['operator' => 'between', 'value' => [100, 500]]);
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 300])));
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 100])));
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 500])));
        $this->assertFalse($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 99])));
        $this->assertFalse($this->evaluator->evaluate([$condition], $this->makeEvent(['amount' => 501])));
    }

    public function test_contains_operator(): void
    {
        $condition = $this->makeCondition(['operator' => 'contains', 'field' => 'note', 'value' => 'urgent']);
        $this->assertTrue($this->evaluator->evaluate([$condition], $this->makeEvent(['note' => 'this is urgent!'])));
        $this->assertFalse($this->evaluator->evaluate([$condition], $this->makeEvent(['note' => 'routine check'])));
    }

    public function test_empty_conditions_passes(): void
    {
        $event = $this->makeEvent(['amount' => 100]);
        $this->assertTrue($this->evaluator->evaluate([], $event));
    }

    // ── Logical operator tests ───────────────────────────────────────────────

    public function test_and_logic_requires_all_conditions(): void
    {
        $c1 = $this->makeCondition(['operator' => 'gte', 'value' => 100, 'logical_operator' => 'AND']);
        $c2 = $this->makeCondition(['operator' => 'lte', 'field' => 'days', 'value' => 30, 'logical_operator' => 'AND']);

        $event = $this->makeEvent(['amount' => 200, 'days' => 20]);
        $this->assertTrue($this->evaluator->evaluate([$c1, $c2], $event));

        $eventFail = $this->makeEvent(['amount' => 50, 'days' => 20]);
        $this->assertFalse($this->evaluator->evaluate([$c1, $c2], $eventFail));
    }

    public function test_or_logic_passes_if_any_condition_passes(): void
    {
        $c1 = $this->makeCondition(['field' => 'role', 'operator' => 'eq', 'value' => 'manager', 'logical_operator' => 'AND']);
        $c2 = $this->makeCondition(['field' => 'role', 'operator' => 'eq', 'value' => 'executive', 'logical_operator' => 'OR']);

        $event = $this->makeEvent(['role' => 'executive']);
        $this->assertTrue($this->evaluator->evaluate([$c1, $c2], $event));

        $eventFail = $this->makeEvent(['role' => 'intern']);
        $this->assertFalse($this->evaluator->evaluate([$c1, $c2], $eventFail));
    }

    // ── Nested payload path ──────────────────────────────────────────────────

    public function test_nested_payload_field_with_dot_notation(): void
    {
        $condition = $this->makeCondition([
            'field'    => 'user.department',
            'operator' => 'eq',
            'value'    => 'Finance',
        ]);

        $event = $this->makeEvent(['user' => ['department' => 'Finance']]);
        $this->assertTrue($this->evaluator->evaluate([$condition], $event));

        $eventFail = $this->makeEvent(['user' => ['department' => 'HR']]);
        $this->assertFalse($this->evaluator->evaluate([$condition], $eventFail));
    }

    public function test_unknown_operator_throws_exception(): void
    {
        $condition = $this->makeCondition(['operator' => 'unknown_op']);
        $event     = $this->makeEvent(['amount' => 100]);

        $this->expectException(\InvalidArgumentException::class);
        $this->evaluator->evaluate([$condition], $event);
    }
}
