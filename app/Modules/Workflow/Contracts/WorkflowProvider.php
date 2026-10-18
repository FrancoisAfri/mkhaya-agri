<?php

namespace App\Modules\Workflow\Contracts;

use App\Core\User\Models\User;

/**
 * The integration contract a module implements to run its records through the engine (PRS §13).
 *
 * The engine references the record (subject id), reads only the fields exposed here, and hands the
 * outcome back through onOutcome(), where the module applies its own business rules. It never writes
 * a module's tables itself.
 */
interface WorkflowProvider
{
    /** Stable key, e.g. 'finance.claims'. */
    public function key(): string;

    public function label(): string;

    /** The event name a submission emits, e.g. 'finance.claims.submitted'. Null for providers without records. */
    public function submitEvent(): ?string;

    /**
     * The record fields a workflow may read, as `record.<key>` in conditions and forms.
     *
     * @return list<array{key:string, label:string, type:string}>
     */
    public function fields(): array;

    public function find(string $id): ?object;

    /** The exposed field values of a record (only those listed in fields()). */
    public function snapshot(object $record): array;

    public function reference(object $record): string;

    public function title(object $record): string;

    public function url(object $record): ?string;

    public function requesterId(object $record): ?int;

    public function ownerId(object $record): ?int;

    public function budgetHolderId(object $record): ?int;

    /** Whether the person may open the record in its own module; delegation never widens this (§3). */
    public function canView(User $user, object $record): bool;

    /**
     * Apply the workflow's outcome through the module's own validated code. Must be idempotent: it
     * may be called again after a failure or a retry.
     *
     * @param  string  $outcome  approved | rejected | cancelled
     */
    public function onOutcome(object $record, string $outcome, ?int $actorId, ?string $comment): void;
}
