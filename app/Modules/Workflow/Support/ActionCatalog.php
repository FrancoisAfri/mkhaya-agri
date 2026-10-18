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

    public static function exists(string $key): bool
    {
        return isset(self::ACTIONS[$key]);
    }

    public static function isMaterial(string $key): bool
    {
        return (self::ACTIONS[$key]['risk'] ?? 'material') === 'material';
    }

    /** Whether a company lets designers use it: low-risk ones always, material ones only when listed. */
    public static function permitted(string $key, array $permittedList): bool
    {
        return self::exists($key) && (! self::isMaterial($key) || in_array($key, $permittedList, true));
    }
}
