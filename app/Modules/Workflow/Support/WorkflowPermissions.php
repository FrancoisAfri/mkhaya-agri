<?php

namespace App\Modules\Workflow\Support;

/**
 * The workflow engine's permissions (PRS §3). Viewing, starting, designing, publishing, managing
 * running work, administering, auditing and reporting are separate so they can be granted apart.
 * Acting on a task needs no permission of its own: being its assignee (or acting delegate) is the
 * grant, and the engine still refuses self-approval and repeat approvers regardless.
 */
final class WorkflowPermissions
{
    public const VIEW = 'workflow.view';

    public const START = 'workflow.start';

    public const DESIGN = 'workflow.design';

    public const PUBLISH = 'workflow.publish';

    public const MANAGE = 'workflow.manage';

    public const REPORTS = 'workflow.reports';

    public const AUDIT = 'workflow.audit';

    public const ADMIN = 'workflow.admin';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }

    /**
     * Starter role templates (§3). Installed once; an administrator edits them afterwards.
     *
     * @return array<string, list<string>>
     */
    public static function roleTemplates(): array
    {
        return [
            'Workflow Administrator' => [self::VIEW, self::START, self::DESIGN, self::MANAGE, self::REPORTS, self::AUDIT, self::ADMIN],
            'Workflow Designer' => [self::VIEW, self::START, self::DESIGN],
            'Workflow Publisher' => [self::VIEW, self::START, self::PUBLISH],
            'Process Manager' => [self::VIEW, self::START, self::MANAGE, self::REPORTS],
            'Workflow Auditor' => [self::VIEW, self::AUDIT, self::REPORTS],
            'Workflow Executive' => [self::VIEW, self::REPORTS],
            'Workflow User' => [self::VIEW, self::START],
        ];
    }
}
