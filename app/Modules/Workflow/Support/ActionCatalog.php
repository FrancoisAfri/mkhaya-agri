<?php

namespace App\Modules\Workflow\Support;

/**
 * The automated actions a designer may place on the canvas (PRS §12). A designer chooses from this
 * list and fills its declared inputs; there is no free-form code, query or network call. Actions
 * marked `material` change a business record and must (a) be listed in the company's permitted
 * actions and (b) sit behind an approval on every path to them.
 */
final class ActionCatalog
{
    public const ACTIONS = [
        'set_value' => [
            'label' => 'Set a value on the request', 'risk' => 'low',
            'inputs' => [['key' => 'field', 'label' => 'Field', 'type' => 'text', 'required' => true], ['key' => 'value', 'label' => 'Value (may use {{field}})', 'type' => 'text', 'required' => false]],
            'outputs' => ['field', 'value'],
        ],
        'add_comment' => [
            'label' => 'Add a comment to the request', 'risk' => 'low',
            'inputs' => [['key' => 'message', 'label' => 'Comment (may use {{field}})', 'type' => 'textarea', 'required' => true]],
            'outputs' => ['comment_id'],
        ],
        'schedule_reminder' => [
            'label' => 'Create a follow-up reminder', 'risk' => 'low',
            'inputs' => [['key' => 'days', 'label' => 'After (days)', 'type' => 'number', 'required' => true], ['key' => 'message', 'label' => 'Reminder', 'type' => 'textarea', 'required' => true], ['key' => 'to', 'label' => 'Remind', 'type' => 'assignee', 'required' => true]],
            'outputs' => ['fire_at'],
        ],
        'mark_record' => [
            'label' => "Set the business record's outcome now", 'risk' => 'material',
            'inputs' => [['key' => 'outcome', 'label' => 'Outcome', 'type' => 'select', 'options' => ['approved', 'rejected'], 'required' => true]],
            'outputs' => ['outcome'],
        ],
    ];

    /**
     * Actions a module adds to the palette: its own steps (request a soil test, record a harvest),
     * each with its declared inputs and a handler. A handler receives the instance and the rendered
     * inputs and returns its output, which is recorded and may be written into the request's data.
     * Risk `record` is a step that records something a person did or a module rule decided: it needs
     * no approval path, unlike `material`.
     *
     * @var array<string, array{label: string, risk: string, inputs: list<array>, outputs: list<string>, module?: string}>
     */
    private static array $registered = [];

    /** @var array<string, callable(\App\Modules\Workflow\Models\WorkflowInstance, array): array> */
    private static array $handlers = [];

    public static function register(string $key, array $spec, callable $handler): void
    {
        self::$registered[$key] = $spec + ['inputs' => [], 'outputs' => [], 'risk' => 'record'];
        self::$handlers[$key] = $handler;
    }

    /** Every action: the built-in ones and those modules registered. */
    public static function all(): array
    {
        return self::ACTIONS + self::$registered;
    }

    public static function spec(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function handler(string $key): ?callable
    {
        return self::$handlers[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function isMaterial(string $key): bool
    {
        return (self::all()[$key]['risk'] ?? 'material') === 'material';
    }

    /** Whether a company lets designers use it: low-risk and record ones always, material ones only when listed. */
    public static function permitted(string $key, array $permittedList): bool
    {
        return self::exists($key) && (! self::isMaterial($key) || in_array($key, $permittedList, true));
    }
}
