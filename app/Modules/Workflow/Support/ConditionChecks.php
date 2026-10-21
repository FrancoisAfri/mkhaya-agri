<?php

namespace App\Modules\Workflow\Support;

use App\Modules\Workflow\Models\WorkflowInstance;

/**
 * Named checks a "wait until" step polls: whether rainfall has reached a threshold, whether the
 * inputs have arrived, whether a withholding period is over. A module registers what only it can
 * answer; a plain condition on the request's data needs no check at all.
 *
 * A check returns whether it is met, the value it saw (shown on the step and in the audit) and a
 * note. It reads; it never changes anything.
 */
final class ConditionChecks
{
    /** @var array<string, array{label: string, params: list<array>, check: callable(WorkflowInstance, array): array{met: bool, value?: mixed, note?: ?string}}> */
    private static array $checks = [];

    public static function register(string $key, string $label, array $params, callable $check): void
    {
        self::$checks[$key] = compact('label', 'params', 'check');
    }

    public static function exists(string $key): bool
    {
        self::builtIn();

        return isset(self::$checks[$key]);
    }

    public static function all(): array
    {
        self::builtIn();

        return array_map(fn ($c) => ['label' => $c['label'], 'params' => $c['params']], self::$checks);
    }

    /** @return array{met: bool, value?: mixed, note?: ?string} */
    public static function run(string $key, WorkflowInstance $instance, array $params): array
    {
        self::builtIn();
        $c = self::$checks[$key] ?? null;
        if (! $c) {
            return ['met' => false, 'note' => "Unknown check {$key}."];
        }

        return ($c['check'])($instance, $params) + ['met' => false];
    }

    private static function builtIn(): void
    {
        if (isset(self::$checks['children_completed'])) {
            return;
        }
        // Every child this request started (optionally of one workflow) has finished as completed.
        self::$checks['children_completed'] = ['label' => 'Child requests have all completed', 'params' => [['key' => 'definition_key', 'label' => 'Workflow (optional)', 'type' => 'text']],
            'check' => function (WorkflowInstance $instance, array $p) {
                $children = WorkflowInstance::query()->where('parent_instance_id', $instance->id)
                    ->when(! empty($p['definition_key']), fn ($q) => $q->whereHas('definition', fn ($d) => $d->where('key', $p['definition_key'])))->get(['status']);
                $done = $children->where('status', 'completed')->count();

                return ['met' => $children->isNotEmpty() && $done === $children->count(), 'value' => "{$done} of {$children->count()}",
                    'note' => $children->whereIn('status', ['rejected', 'cancelled', 'failed'])->isNotEmpty() ? 'Some did not complete.' : null];
            }];
    }
}
