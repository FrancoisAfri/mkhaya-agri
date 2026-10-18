<?php

namespace App\Modules\Operations\ProjectManagement\Support\Evidence;

/**
 * The canonical evidence vocabulary (PM-1001): what each event type means, what its payload must
 * carry, and how much a claim of that kind is worth by default.
 *
 * Three things hang off this:
 *  - ingestion validates a payload against the declared contract before it reaches the ledger;
 *  - derivation weights evidence by `confidence` and only considers types it knows;
 *  - clients fetch the exported schema (`GET /api/v1/pm/evidence/schema`) so a mobile app and the
 *    backend are working from the same definition rather than two copies that drift.
 *
 * **On "JSON Schema":** the ticket asks for one per type. No JSON Schema *validator* is installed
 * here and adding a dependency is not mine to decide, so the contract is declared in the shape
 * below — required and optional fields with types — validated by TaxonomyValidator, and *exported*
 * as draft 2020-12 documents for clients that do have a validator. What we enforce and what we
 * publish are the same constraints; we simply do not claim to enforce the whole of JSON Schema.
 *
 * **Confidence defaults** encode how much a claim of each kind is worth. A measured quantity
 * verified on site outranks somebody typing a percentage, and progress derivation depends on that
 * ordering being stated rather than assumed (PM-1201).
 *
 * Adding a type is additive and safe. Renaming one is not: stored events keep the old string
 * forever, so a rename means adding the new type and leaving the old one listed as legacy.
 */
class EvidenceTaxonomy
{
    /** Bumped when the *meaning* of a payload changes, not when a type is added. */
    public const VERSION = 'v1';

    public const DOMAIN_WORK = 'work';

    public const DOMAIN_QUANTITY = 'quantity';

    public const DOMAIN_TIME = 'time';

    public const DOMAIN_PROCUREMENT = 'procurement';

    public const DOMAIN_FIELD = 'field';

    public const DOMAIN_ASSET = 'asset';

    public const DOMAIN_DOCUMENT = 'document';

    public const DOMAIN_META = 'meta';

    /**
     * type => [domain, confidence, requires, optional, description]
     *
     * `requires`/`optional` describe the event's `payload` object. The columns every event has —
     * quantity, unit, amount, currency, geo — are declared on the table, not here.
     *
     * @var array<string, array{domain:string, confidence:float, requires:array<string,string>, optional:array<string,string>, description:string}>
     */
    private const TYPES = [
        // ── Work ─────────────────────────────────────────────────────────────
        'task.started' => [
            'domain' => self::DOMAIN_WORK, 'confidence' => 1.00,
            'requires' => [], 'optional' => ['note' => 'string'],
            'description' => 'Work on a task began.',
        ],
        'task.completed' => [
            'domain' => self::DOMAIN_WORK, 'confidence' => 1.00,
            'requires' => [], 'optional' => ['note' => 'string'],
            'description' => 'A task was reported complete.',
        ],
        'task.reopened' => [
            'domain' => self::DOMAIN_WORK, 'confidence' => 1.00,
            'requires' => ['reason' => 'string'], 'optional' => [],
            'description' => 'A completed task was reopened; its earlier completion no longer stands.',
        ],
        'checklist.item.completed' => [
            'domain' => self::DOMAIN_WORK, 'confidence' => 0.90,
            'requires' => ['item' => 'string'], 'optional' => ['of_total' => 'integer'],
            'description' => 'One checklist item was ticked off.',
        ],

        // ── Quantity — the strongest evidence of physical progress ────────────
        'quantity.installed' => [
            'domain' => self::DOMAIN_QUANTITY, 'confidence' => 1.00,
            'requires' => [], 'optional' => ['location' => 'string', 'verified_by' => 'string'],
            'description' => 'Measured work placed or installed. Carries quantity + unit.',
        ],
        'quantity.delivered' => [
            'domain' => self::DOMAIN_QUANTITY, 'confidence' => 0.90,
            'requires' => [], 'optional' => ['supplier' => 'string', 'delivery_note' => 'string'],
            'description' => 'Material arrived on site. Delivered is not installed.',
        ],
        'quantity.rejected' => [
            'domain' => self::DOMAIN_QUANTITY, 'confidence' => 1.00,
            'requires' => ['reason' => 'string'], 'optional' => [],
            'description' => 'Delivered or installed work was rejected and does not count.',
        ],

        // ── Time ─────────────────────────────────────────────────────────────
        // `hours` is required on all three time events: derivation sums it (PM-ADR-010), and an
        // event announcing that hours happened without saying how many moves nothing and can only
        // mislead whoever reads the timeline.
        'time.logged' => [
            'domain' => self::DOMAIN_TIME, 'confidence' => 0.70,
            'requires' => ['hours' => 'number'], 'optional' => ['note' => 'string'],
            'description' => 'Hours claimed against a task, not yet approved.',
        ],
        'time.approved' => [
            'domain' => self::DOMAIN_TIME, 'confidence' => 1.00,
            'requires' => ['approved_by' => 'string', 'hours' => 'number'],
            'optional' => ['worked_on' => 'string', 'member' => 'string'],
            'description' => 'Hours approved. This is what counts towards actual cost.',
        ],
        'attendance.clock_in' => [
            'domain' => self::DOMAIN_TIME, 'confidence' => 1.00,
            'requires' => [], 'optional' => ['geofence' => 'string', 'within_fence' => 'boolean'],
            'description' => 'Someone clocked in on site.',
        ],
        'attendance.clock_out' => [
            'domain' => self::DOMAIN_TIME, 'confidence' => 1.00,
            'requires' => [], 'optional' => ['geofence' => 'string', 'within_fence' => 'boolean'],
            'description' => 'Someone clocked out.',
        ],

        // ── Procurement ──────────────────────────────────────────────────────
        'requisition.raised' => [
            'domain' => self::DOMAIN_PROCUREMENT, 'confidence' => 1.00,
            'requires' => ['reference' => 'string'], 'optional' => ['requested_by' => 'string'],
            'description' => 'A purchase was requested for this project.',
        ],
        'po.issued' => [
            'domain' => self::DOMAIN_PROCUREMENT, 'confidence' => 1.00,
            'requires' => ['reference' => 'string'], 'optional' => ['supplier' => 'string'],
            'description' => 'A purchase order was issued — money is committed, not yet spent.',
        ],
        'grn.received' => [
            'domain' => self::DOMAIN_PROCUREMENT, 'confidence' => 1.00,
            'requires' => ['reference' => 'string'], 'optional' => ['supplier' => 'string'],
            'description' => 'Goods received against an order. Evidence of both delivery and cost.',
        ],
        'invoice.received' => [
            'domain' => self::DOMAIN_PROCUREMENT, 'confidence' => 1.00,
            'requires' => ['reference' => 'string'], 'optional' => ['supplier' => 'string'],
            'description' => 'A supplier invoice arrived against this project.',
        ],
        'invoice.paid' => [
            'domain' => self::DOMAIN_PROCUREMENT, 'confidence' => 1.00,
            'requires' => ['reference' => 'string'], 'optional' => [],
            'description' => 'A supplier invoice was paid.',
        ],

        // ── Field ────────────────────────────────────────────────────────────
        'photo.captured' => [
            'domain' => self::DOMAIN_FIELD, 'confidence' => 0.80,
            'requires' => [], 'optional' => ['caption' => 'string', 'taken_at' => 'string'],
            'description' => 'A site photograph. Attachments carry the file; geo carries where.',
        ],
        'site.visit' => [
            'domain' => self::DOMAIN_FIELD, 'confidence' => 0.90,
            'requires' => [], 'optional' => ['purpose' => 'string', 'attendees' => 'array'],
            'description' => 'Somebody attended site.',
        ],
        'diary.submitted' => [
            'domain' => self::DOMAIN_FIELD, 'confidence' => 0.80,
            'requires' => [], 'optional' => ['weather' => 'string', 'labour_count' => 'integer', 'summary' => 'string'],
            'description' => 'A site diary for the day. The backbone of a delay claim.',
        ],
        'inspection.passed' => [
            'domain' => self::DOMAIN_FIELD, 'confidence' => 1.00,
            'requires' => ['inspector' => 'string'], 'optional' => ['reference' => 'string'],
            'description' => 'Work was inspected and accepted.',
        ],
        'inspection.failed' => [
            'domain' => self::DOMAIN_FIELD, 'confidence' => 1.00,
            'requires' => ['inspector' => 'string', 'reason' => 'string'], 'optional' => [],
            'description' => 'Work was inspected and rejected. Progress claimed on it does not stand.',
        ],

        // ── Asset & fleet ────────────────────────────────────────────────────
        'asset.allocated' => [
            'domain' => self::DOMAIN_ASSET, 'confidence' => 1.00,
            'requires' => ['asset' => 'string'], 'optional' => ['allocated_to' => 'string'],
            'description' => 'Equipment was allocated to this project.',
        ],
        'asset.returned' => [
            'domain' => self::DOMAIN_ASSET, 'confidence' => 1.00,
            'requires' => ['asset' => 'string'], 'optional' => ['condition' => 'string'],
            'description' => 'Equipment came back off the project.',
        ],
        'vehicle.arrived' => [
            'domain' => self::DOMAIN_ASSET, 'confidence' => 1.00,
            'requires' => ['vehicle' => 'string'], 'optional' => ['odometer' => 'number'],
            'description' => 'A vehicle arrived on site.',
        ],
        'vehicle.departed' => [
            'domain' => self::DOMAIN_ASSET, 'confidence' => 1.00,
            'requires' => ['vehicle' => 'string'], 'optional' => ['odometer' => 'number'],
            'description' => 'A vehicle left site.',
        ],
        'fuel.logged' => [
            'domain' => self::DOMAIN_ASSET, 'confidence' => 1.00,
            'requires' => ['vehicle' => 'string'], 'optional' => ['odometer' => 'number'],
            'description' => 'Fuel drawn against the project. Carries quantity + unit and cost.',
        ],

        // ── Documents ────────────────────────────────────────────────────────
        'document.uploaded' => [
            'domain' => self::DOMAIN_DOCUMENT, 'confidence' => 1.00,
            'requires' => ['document' => 'string'], 'optional' => ['kind' => 'string'],
            'description' => 'A document was attached to the project.',
        ],
        'document.approved' => [
            'domain' => self::DOMAIN_DOCUMENT, 'confidence' => 1.00,
            'requires' => ['document' => 'string', 'approved_by' => 'string'], 'optional' => [],
            'description' => 'A document was approved.',
        ],
        'drawing.revised' => [
            'domain' => self::DOMAIN_DOCUMENT, 'confidence' => 1.00,
            'requires' => ['drawing' => 'string', 'revision' => 'string'], 'optional' => ['supersedes' => 'string'],
            'description' => 'A drawing was revised — work done to the old revision is at risk.',
        ],

        // ── Meta ─────────────────────────────────────────────────────────────
        'milestone.claimed' => [
            'domain' => self::DOMAIN_META, 'confidence' => 0.70,
            'requires' => ['milestone' => 'string'], 'optional' => [],
            'description' => 'A milestone was claimed as reached. Claimed is not verified.',
        ],
        'milestone.verified' => [
            'domain' => self::DOMAIN_META, 'confidence' => 1.00,
            'requires' => ['milestone' => 'string', 'verified_by' => 'string'], 'optional' => [],
            'description' => 'A milestone was independently verified.',
        ],
        'status.reported' => [
            'domain' => self::DOMAIN_META, 'confidence' => 0.60,
            'requires' => [], 'optional' => ['status' => 'string', 'note' => 'string'],
            'description' => 'Somebody stated a status. The weakest evidence there is, and weighted accordingly.',
        ],

        // ── Legacy ───────────────────────────────────────────────────────────
        // Shipped before this taxonomy and still stored, so they stay known: an event's type string
        // is written once and lives forever, and dropping them here would exclude real history from
        // derivation.
        'progress.reported' => [
            'domain' => self::DOMAIN_META, 'confidence' => 0.60,
            // The figure itself is the whole content of the claim.
            'requires' => ['percent' => 'number'], 'optional' => ['note' => 'string'],
            'description' => 'Legacy: a stated progress figure. Prefer quantity.installed.',
        ],
        'cost.incurred' => [
            'domain' => self::DOMAIN_PROCUREMENT, 'confidence' => 0.90,
            'requires' => [], 'optional' => ['note' => 'string'],
            'description' => 'Legacy: cost against the project. Prefer grn.received or invoice.received.',
        ],
        'hours.booked' => [
            'domain' => self::DOMAIN_TIME, 'confidence' => 0.70,
            'requires' => ['hours' => 'number'], 'optional' => ['note' => 'string'],
            'description' => 'Legacy: hours claimed. Prefer time.logged / time.approved.',
        ],
        'material.delivered' => [
            'domain' => self::DOMAIN_QUANTITY, 'confidence' => 0.90,
            'requires' => [], 'optional' => ['supplier' => 'string'],
            'description' => 'Legacy: material arrived. Prefer quantity.delivered.',
        ],
        'issue.raised' => [
            'domain' => self::DOMAIN_FIELD, 'confidence' => 0.90,
            'requires' => [], 'optional' => ['summary' => 'string'],
            'description' => 'Legacy: a problem was raised on site.',
        ],
        'status.changed' => [
            'domain' => self::DOMAIN_META, 'confidence' => 1.00,
            'requires' => [], 'optional' => ['from' => 'string', 'to' => 'string'],
            'description' => 'Legacy: a record changed state.',
        ],
        'attachment.added' => [
            'domain' => self::DOMAIN_DOCUMENT, 'confidence' => 1.00,
            'requires' => [], 'optional' => ['filename' => 'string'],
            'description' => 'Legacy: a file was attached. Prefer document.uploaded.',
        ],
        'correction' => [
            'domain' => self::DOMAIN_META, 'confidence' => 1.00,
            'requires' => ['supersedes' => 'string'], 'optional' => ['reason' => 'string'],
            'description' => 'Supersedes an earlier event. Written by EvidenceLedger::supersede().',
        ],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    public static function knows(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function domainOf(string $type): ?string
    {
        return self::TYPES[$type]['domain'] ?? null;
    }

    /**
     * How much a claim of this kind is worth before anything else is considered.
     *
     * An unknown type gets 0.0, not a guess: it is stored (forward compatibility, PM-1001) but
     * must not influence a derived number until somebody declares what it means.
     */
    public static function defaultConfidence(string $type): float
    {
        return self::TYPES[$type]['confidence'] ?? 0.0;
    }

    /** @return array{requires:array<string,string>, optional:array<string,string>}|null */
    public static function contractFor(string $type): ?array
    {
        if (! isset(self::TYPES[$type])) {
            return null;
        }

        return [
            'requires' => self::TYPES[$type]['requires'],
            'optional' => self::TYPES[$type]['optional'],
        ];
    }

    /** @return list<string> types grouped under one domain */
    public static function inDomain(string $domain): array
    {
        return array_keys(array_filter(self::TYPES, fn ($t) => $t['domain'] === $domain));
    }

    /**
     * The shared schema document clients validate against (PM-1001).
     *
     * Emitted as JSON Schema draft 2020-12 so a mobile client with a real validator can use it
     * directly, from the same declarations this server enforces.
     *
     * @return array<string,mixed>
     */
    public static function export(): array
    {
        $schemas = [];

        foreach (self::TYPES as $type => $definition) {
            $properties = [];

            foreach ($definition['requires'] + $definition['optional'] as $field => $phpType) {
                $properties[$field] = ['type' => self::jsonType($phpType)];
            }

            $schemas[$type] = [
                '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
                '$id'         => 'https://mkhaya.app/schemas/pm/evidence/' . $type . '/' . self::VERSION,
                'title'       => $type,
                'description' => $definition['description'],
                'type'        => 'object',
                'properties'  => (object) $properties,
                'required'    => array_keys($definition['requires']),
                // Unknown keys are kept: a newer client may send a field this server has not heard
                // of, and dropping it would lose evidence.
                'additionalProperties' => true,
                'x-domain'     => $definition['domain'],
                'x-confidence' => $definition['confidence'],
            ];
        }

        return ['version' => self::VERSION, 'schemas' => $schemas];
    }

    private static function jsonType(string $phpType): string
    {
        return match ($phpType) {
            'integer' => 'integer',
            'number'  => 'number',
            'boolean' => 'boolean',
            'array'   => 'array',
            default   => 'string',
        };
    }
}
