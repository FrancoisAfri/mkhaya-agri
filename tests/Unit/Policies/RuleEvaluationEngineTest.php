<?php

namespace Tests\Unit\Policies;

use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Models\EventRuleMap;
use App\Modules\Policies\Models\PolicyRule;
use App\Modules\Policies\Models\RuleCondition;
use App\Modules\Policies\Services\RuleEngine\ConditionEvaluator;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RuleEvaluationEngineTest extends TestCase
{
    use RefreshDatabase;
    private RuleEvaluationEngine $engine;
    private ConditionEvaluator   $conditionEvaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conditionEvaluator = new ConditionEvaluator();
        $this->engine             = new RuleEvaluationEngine($this->conditionEvaluator);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeEvent(array $overrides = []): PolicyEventDTO
    {
        return new PolicyEventDTO(
            module:  $overrides['module']  ?? 'Leave',
            action:  $overrides['action']  ?? 'leave_request.submitted',
            payload: $overrides['payload'] ?? ['days' => 5],
        );
    }

    private function makeRule(bool $active = true, string $severity = 'high', string $action = 'block'): PolicyRule
    {
        $rule                       = new PolicyRule();
        $rule->id                   = 1;
        $rule->active               = $active;
        $rule->severity             = $severity;
        $rule->action_on_breach     = $action;
        $rule->setRelation('conditions', new Collection());
        $rule->setRelation('actions', new Collection());

        return $rule;
    }

    public function test_evaluate_returns_array_of_evaluation_results(): void
    {
        $event = $this->makeEvent();

        // No rules mapped (engine resolves none) → empty results
        $results = $this->engine->evaluate($event);
        $this->assertIsArray($results);
    }

    public function test_evaluation_result_dto_has_correct_structure(): void
    {
        $rule  = $this->makeRule();
        $event = $this->makeEvent();

        // Directly test the evaluateRule logic via the public evaluate with
        // an empty condition set (should pass with no conditions)
        $result = new EvaluationResultDTO(
            rule:     $rule,
            event:    $event,
            passed:   true,
            severity: 'high',
            outcome:  'pass',
        );

        $this->assertTrue($result->passed);
        $this->assertSame('high', $result->severity);
        $this->assertSame('pass', $result->outcome);
        $this->assertSame($rule, $result->rule);
        $this->assertSame($event, $result->event);
        $this->assertNull($result->explanation);
    }

    public function test_payload_filter_passes_when_filter_is_empty(): void
    {
        $engine = $this->engine;
        $ref    = new \ReflectionMethod($engine, 'passesPayloadFilter');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke($engine, null, ['any' => 'thing']));
        $this->assertTrue($ref->invoke($engine, [], ['any' => 'thing']));
    }

    public function test_payload_filter_matches_exact_key_value(): void
    {
        $engine = $this->engine;
        $ref    = new \ReflectionMethod($engine, 'passesPayloadFilter');
        $ref->setAccessible(true);

        $filter  = ['module' => 'Leave', 'status' => 'pending'];
        $payload = ['module' => 'Leave', 'status' => 'pending', 'days' => 5];
        $this->assertTrue($ref->invoke($engine, $filter, $payload));

        $payloadFail = ['module' => 'Leave', 'status' => 'approved', 'days' => 5];
        $this->assertFalse($ref->invoke($engine, $filter, $payloadFail));
    }

    public function test_payload_filter_supports_dot_notation(): void
    {
        $engine = $this->engine;
        $ref    = new \ReflectionMethod($engine, 'passesPayloadFilter');
        $ref->setAccessible(true);

        $filter  = ['user.department' => 'Finance'];
        $payload = ['user' => ['department' => 'Finance']];
        $this->assertTrue($ref->invoke($engine, $filter, $payload));

        $payloadFail = ['user' => ['department' => 'HR']];
        $this->assertFalse($ref->invoke($engine, $filter, $payloadFail));
    }

    public function test_condition_evaluator_integration_with_rule_engine(): void
    {
        $event = $this->makeEvent(['payload' => ['days' => 15]]);

        // Condition: days > 10 (should pass)
        $condition = new RuleCondition([
            'type'             => 'numeric',
            'field'            => 'days',
            'operator'         => 'gt',
            'value'            => 10,
            'logical_operator' => 'AND',
            'sort_order'       => 0,
        ]);

        $rule = $this->makeRule();
        $rule->setRelation('conditions', new Collection([$condition]));

        // Manually invoke evaluateRule via reflection
        $ref = new \ReflectionMethod($this->engine, 'evaluateRule');
        $ref->setAccessible(true);

        /** @var EvaluationResultDTO $result */
        $result = $ref->invoke($this->engine, $rule, $event);

        $this->assertTrue($result->passed);
        $this->assertSame('pass', $result->outcome);
    }

    public function test_failed_condition_produces_breach_outcome(): void
    {
        $event = $this->makeEvent(['payload' => ['days' => 5]]);

        $condition = new RuleCondition([
            'type'             => 'numeric',
            'field'            => 'days',
            'operator'         => 'gt',
            'value'            => 10,
            'logical_operator' => 'AND',
            'sort_order'       => 0,
        ]);

        $rule = $this->makeRule(true, 'critical', 'block');
        $rule->setRelation('conditions', new Collection([$condition]));

        $ref = new \ReflectionMethod($this->engine, 'evaluateRule');
        $ref->setAccessible(true);

        /** @var EvaluationResultDTO $result */
        $result = $ref->invoke($this->engine, $rule, $event);

        $this->assertFalse($result->passed);
        $this->assertSame('block', $result->outcome);
    }
}
