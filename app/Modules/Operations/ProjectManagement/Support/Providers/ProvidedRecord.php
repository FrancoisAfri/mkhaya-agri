<?php

namespace App\Modules\Operations\ProjectManagement\Support\Providers;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One record as Project Management sees it, wherever it came from.
 *
 * The whole point of the provider pattern (PM-1041) is that PM code never asks "is HR installed?"
 * — it asks a provider for a person and gets this back, whether the answer came from the HR module
 * or from PM's own table. Feature code that branches on the source is the thing this exists to
 * prevent.
 *
 * `source` is carried anyway, but for display and for the sync bookkeeping in `pm_external_refs` —
 * not for behaviour.
 */
final class ProvidedRecord implements Arrayable
{
    /**
     * @param  string  $id     the source system's id (a PM uuid when source is 'local')
     * @param  string  $label  what a person should be shown
     * @param  array<string,mixed>  $meta  extra fields the source offers; never required by PM
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $source,
        public readonly ?string $email = null,
        public readonly array $meta = [],
        /** False when the source no longer has this record — a link to nothing (PM-1043). */
        public readonly bool $isResolved = true,
    ) {
    }

    /**
     * A record whose source no longer holds it: the module was deactivated, or the record was
     * deleted there. The last-known label is kept so the screen still reads sensibly instead of
     * showing an id or a blank.
     */
    public static function orphaned(string $id, string $label, string $source): self
    {
        return new self($id, $label, $source, isResolved: false);
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'source'      => $this->source,
            'email'       => $this->email,
            'meta'        => $this->meta,
            'is_resolved' => $this->isResolved,
        ];
    }
}
