<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Integrated;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\TicketProvider;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * Support tickets raised against a project (`helpdesk.tickets`).
 *
 * Linked explicitly, for the same reason purchases are: `hd_tickets` belongs to the Help Desk and
 * has no project column, and PM guessing at the association would attribute somebody else's fault
 * report to this job.
 *
 * The number worth surfacing is how many are still open. A project with eleven open tickets against
 * it is in a different state from one with eleven closed, and a count that did not distinguish them
 * would be a number nobody could act on.
 */
class HelpDeskTicketProvider implements TicketProvider
{
    public function forProject(string $projectId): array
    {
        $out = [];

        foreach ($this->links($projectId) as $ref) {
            $row = ModuleTable::query('hd_tickets', ['id', 'ticket_number', 'subject', 'status_id'])
                ?->where('id', ctype_digit((string) $ref->source_id) ? (int) $ref->source_id : 0)
                ->first();

            if ($row) {
                $out[] = $this->present($row, $ref->uuid);
            }
        }

        return $out;
    }

    public function isAvailable(): bool
    {
        return ModuleTable::ready('hd_tickets', ['id', 'ticket_number', 'subject', 'status_id']);
    }

    /** How many linked tickets are still open — the figure a project page actually needs. */
    public function openCountFor(string $projectId): int
    {
        return collect($this->forProject($projectId))
            ->filter(fn (ProvidedRecord $r) => $r->meta['open'] ?? false)
            ->count();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function links(string $projectUuid)
    {
        return ExternalRef::query()
            ->where('local_type', 'project_link')
            ->where('source_entity', 'ticket')
            ->where('snapshot->project_id', $projectUuid)
            ->get();
    }

    private function present(object $row, string $linkId): ProvidedRecord
    {
        // Status is a foreign key on this table, so "open" is read from the status record's own
        // resolved flag rather than guessed from the id — the ids are seeded per tenant and mean
        // nothing outside the Help Desk.
        $status = ModuleTable::query('hd_statuses', ['id', 'name'])?->where('id', $row->status_id)->first();

        $name = (string) ($status->name ?? '');
        $closed = $status && (($status->is_closed ?? false) || in_array(strtolower($name), ['closed', 'resolved'], true));

        return new ProvidedRecord(
            id: $linkId,
            label: trim(($row->ticket_number ?: 'Ticket') . ' · ' . ($row->subject ?: '')),
            source: 'helpdesk',
            meta: [
                'kind'   => 'ticket',
                'status' => $name ?: null,
                'open'   => ! $closed,
            ],
        );
    }
}
