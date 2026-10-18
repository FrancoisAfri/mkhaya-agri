<?php

namespace App\Modules\Operations\Sheq\Sync;

/**
 * The outcome of merging one operation (SHEQ-OFF-4 / OFF-5).
 *
 * Carries three things the caller needs kept apart: the values to write, the conflicts to queue for
 * a human, and a per-field record of which rule decided what. That last part is not decoration —
 * SHEQ-OFF-5 requires the conflict queue to show a worker why their value lost.
 */
class MergeResult
{
    /** @var array<string,mixed> field => value to persist */
    public array $values = [];

    /** @var list<array{field:string,base:mixed,server:mixed,client:mixed,rule:string}> */
    public array $conflicts = [];

    /** @var array<string,string> field => rule applied */
    public array $rules = [];

    /** Set when the client tried to move status; the transition path re-validates it (OFF-6). */
    public mixed $rejectedStatus = null;

    /** Set when the record was deleted on the server and the client had edits (OFF-4 last row). */
    public bool $resurrected = false;

    public function apply(string $field, mixed $value, string $rule): void
    {
        $this->values[$field] = $value;
        $this->rules[$field] = $rule;
    }

    public function conflict(string $field, mixed $base, mixed $server, mixed $client, string $rule): void
    {
        $this->conflicts[] = compact('field', 'base', 'server', 'client') + ['rule' => $rule];
        $this->rules[$field] = $rule;
    }

    public function note(string $field, string $rule): void
    {
        $this->rules[$field] = $rule;
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /**
     * Everything the client sent that is not reflected in `values` and not queued as a conflict.
     *
     * Used as an assertion rather than as a feature: if this is ever non-empty for a field the
     * client changed, a worker's edit vanished, which the spec calls a P0 defect.
     *
     * @param  array<string,mixed>  $client
     * @param  array<string,mixed>  $base
     * @return list<string>
     */
    public function unaccountedFor(array $client, array $base, string $statusField): array
    {
        $conflicted = array_column($this->conflicts, 'field');
        $lost = [];

        foreach ($client as $field => $value) {
            if ($field === $statusField) {
                continue;
            }

            // Unchanged values need no accounting; there was nothing to lose.
            if (array_key_exists($field, $base) && json_encode($base[$field]) === json_encode($value)) {
                continue;
            }

            if (! array_key_exists($field, $this->values) && ! in_array($field, $conflicted, true)) {
                $lost[] = $field;
            }
        }

        return $lost;
    }
}
