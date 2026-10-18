<?php
namespace App\Modules\Policies\DTOs;
readonly class PolicyEventDTO {
    public function __construct(
        public string  $module,
        public string  $action,
        public array   $payload,
        public ?int    $actorId       = null,
        public ?int    $subjectId     = null,
        public ?string $subjectType   = null,
        public ?string $correlationId = null,
        public ?int    $eventId       = null,
    ) {}
    public static function from(array $data): self {
        return new self(
            module:        $data['module'],
            action:        $data['action'],
            payload:       $data['payload'],
            actorId:       $data['actor_id'] ?? null,
            subjectId:     $data['subject_id'] ?? null,
            subjectType:   $data['subject_type'] ?? null,
            correlationId: $data['correlation_id'] ?? null,
            eventId:       $data['event_id'] ?? null,
        );
    }
}
