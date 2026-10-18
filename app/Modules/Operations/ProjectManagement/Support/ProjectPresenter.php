<?php

namespace App\Modules\Operations\ProjectManagement\Support;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator;

/**
 * Turns models into the shape the client sees.
 *
 * Two rules it exists to enforce, in one place rather than in every controller:
 *  - the internal bigint `id` never leaves the server; the public UUID is presented as `id`
 *    (PM-ADR-003);
 *  - money is emitted as a decimal string with its currency, never as a JSON number, so a
 *    JavaScript client's floats cannot degrade it (PM-ADR-006).
 */
class ProjectPresenter
{
    /**
     * Money fields an external party must never receive (PM-1021).
     *
     * Redaction happens here, where the payload is built, rather than by hiding a column in the UI:
     * a Client or Contractor who calls the API directly must get the same answer as one who looks
     * at a screen. Removed entirely rather than nulled — a null invites "why is this empty?", an
     * absent key says the field is not theirs.
     */
    private const INTERNAL_MONEY_FIELDS = ['budget_amount', 'currency'];

    /**
     * Strip internal cost from a presented row when the viewer is external.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public static function redactFor(array $row, ?User $viewer, ?Project $project = null): array
    {
        if (! $viewer) {
            return $row;
        }

        $evaluator = app(PermissionEvaluator::class);

        // Either signal is enough: the role on this project is Client/Contractor, or the viewer
        // simply does not hold cost visibility.
        $external = $evaluator->isExternalOn($viewer, $project);
        $canSeeCosts = $evaluator->can($viewer, RoleCatalog::VIEW_COSTS, $project);

        if (! $external && $canSeeCosts) {
            return $row;
        }

        foreach (self::INTERNAL_MONEY_FIELDS as $field) {
            unset($row[$field]);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    public static function summary(Project $project): array
    {
        return [
            'id'            => $project->uuid,
            'code'          => $project->code,
            'name'          => $project->name,
            'status'        => $project->status,
            'stage'         => $project->stage,
            'priority'      => $project->priority,
            'client'        => $project->clientRef?->display_name,
            // The id as well as the name: the edit form has to be able to pre-select the client,
            // and a name is not something you can select by. It is the id the *provider* uses —
            // the ref's own uuid when PM owns the client, the CRM account's when CRM does — so it
            // matches what the picker's search returns and the selection resolves.
            'client_id'     => $project->clientRef
                ? ($project->clientRef->isLocal() ? $project->clientRef->uuid : $project->clientRef->source_id)
                : null,
            // Where the client came from, so the screen can say "this is a CRM customer" rather
            // than leaving the reader to guess which list they are editing.
            'client_source' => $project->clientRef?->source_module,
            'currency'      => $project->currency,
            'budget_amount' => self::money($project->budget_amount),
            'planned_start' => $project->planned_start?->toDateString(),
            'planned_end'   => $project->planned_end?->toDateString(),
            'is_billable'   => (bool) $project->is_billable,
            // Whether booked hours wait for a sign-off. On the payload because the delivery screen
            // has to say "Book hours" or "Submit for approval" truthfully, and the overview shows it
            // as a project fact rather than something you discover by booking an hour.
            'timesheet_approval_required' => (bool) $project->timesheet_approval_required,
        ];
    }

    /** @return array<string,mixed> */
    public static function detail(Project $project): array
    {
        return array_merge(self::summary($project), [
            'description'    => $project->description,
            'lens_code'      => $project->lens_code,
            'timezone'       => $project->timezone,
            'calendar'       => $project->calendar ? [
                'id'       => $project->calendar->uuid,
                'name'     => $project->calendar->name,
                'timezone' => $project->calendar->timezone,
            ] : null,
            'actual_start'   => $project->actual_start?->toDateString(),
            'actual_end'     => $project->actual_end?->toDateString(),
            'location_name'  => $project->location_name,
            'geo_lat'        => $project->geo_lat,
            'geo_lng'        => $project->geo_lng,
            'manager_id'     => $project->manager_id,
            'task_count'     => $project->tasks()->count(),
            'evidence_count' => EvidenceEvent::where('project_id', $project->id)->count(),
            'created_at'     => $project->created_at?->toIso8601String(),
            'updated_at'     => $project->updated_at?->toIso8601String(),
        ]);
    }

    /** @return array<string,mixed> */
    public static function evidence(EvidenceEvent $event): array
    {
        return [
            'id'            => $event->uuid,
            'event_type'    => $event->event_type,
            'subject_type'  => $event->subject_type,
            'subject_id'    => $event->subject_uuid,
            'source_module' => $event->source_module,
            'actor_type'    => $event->actor_type,
            'actor_id'      => $event->actor_id,
            // The instant plus the zone it was observed in — the pair is the point (DoD 7).
            'occurred_at'   => $event->occurred_at?->toIso8601String(),
            'occurred_tz'   => $event->occurred_tz,
            'recorded_at'   => $event->recorded_at?->toIso8601String(),
            'quantity'      => $event->quantity,
            'unit'          => $event->unit,
            'amount'        => self::money($event->amount),
            'currency'      => $event->currency,
            'confidence'    => $event->confidence,
            'geo'           => $event->geo_lat !== null ? ['lat' => $event->geo_lat, 'lng' => $event->geo_lng] : null,
            'payload'       => $event->payload,
            'attachments'   => $event->attachments,
            'superseded_by' => $event->superseded_by,
            // Presented so the UI can show the chain exists; verification is a separate action.
            'hash'          => $event->hash,
        ];
    }

    /** Decimal string or null — never a float, and never a bare number in JSON. */
    private static function money(mixed $amount): ?string
    {
        return $amount === null ? null : (string) $amount;
    }
}
