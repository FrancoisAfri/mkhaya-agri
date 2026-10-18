<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\AuditEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Writes the PM audit trail (PM-1024).
 *
 * Records the actor, what changed (before and after, restricted to the fields that actually moved),
 * and the request it happened in — IP, user agent, route and a request ID that ties the row to the
 * application log.
 *
 * Attributes that would leak or bloat are dropped: nothing that looks like a secret, and no
 * timestamps, whose "change" is noise on every single update.
 */
class AuditLogger
{
    /** Never recorded, whatever model they appear on. */
    private const REDACTED = ['password', 'remember_token', 'api_key', 'secret', 'token'];

    /** Changed on every save and interesting on none. */
    private const IGNORED = ['created_at', 'updated_at', 'deleted_at'];

    public function __construct(private readonly RequestContext $context)
    {
    }

    /**
     * Record a model change.
     *
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     */
    public function record(Model $model, string $action, ?array $before = null, ?array $after = null): AuditEntry
    {
        $actor = Auth::user();

        return AuditEntry::create([
            'company_id'  => $model->getAttribute('company_id'),
            'entity_type' => $model->getTable(),
            'entity_id'   => $model->getKey(),
            'entity_uuid' => $model->getAttribute('uuid'),
            'action'      => $action,
            'actor_id'    => $actor?->id,
            'actor_type'  => $actor ? 'user' : 'system',
            // The label is stamped now rather than joined later: if the person is renamed or
            // removed, the log must still say who did it at the time.
            'actor_label' => $this->labelFor($actor),
            'before'      => $before ? $this->clean($before) : null,
            'after'       => $after ? $this->clean($after) : null,
            'ip'          => Request::ip(),
            'request_id'  => $this->context->requestId(),
            'user_agent'  => Str::limit((string) Request::userAgent(), 250, ''),
            'route'       => Request::route()?->getName() ?? Request::path(),
        ]);
    }

    /**
     * Record a domain action that is not a plain field change — an approval, a supersession, a
     * baseline. `$context` carries whatever makes the action meaningful later.
     *
     * @param  array<string,mixed>  $context
     */
    public function recordAction(Model $model, string $action, array $context = []): AuditEntry
    {
        return $this->record($model, $action, null, $context);
    }

    /** @param  array<string,mixed>  $attributes */
    private function clean(array $attributes): array
    {
        foreach (array_merge(self::REDACTED, self::IGNORED) as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }

    private function labelFor(mixed $actor): ?string
    {
        if (! $actor) {
            return null;
        }

        // There is no users.name column on this platform; the person's name lives on hr_people.
        $person = $actor->person ?? null;
        $name = $person ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? '')) : '';

        return $name !== '' ? $name : ($actor->email ?? ('user #' . $actor->id));
    }
}
